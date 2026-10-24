// Onboards a fresh dev Home Assistant (or logs into an onboarded one) and prints a long-lived access token
// on the last line of stdout. Driven by docker/dev/bootstrap-ha.sh; configuration comes from the environment:
//   HA_URL, HA_DEV_USER, HA_DEV_PASSWORD
// Everything but the token goes to stderr. Passwords and tokens are never logged.

const base = (process.env.HA_URL ?? 'http://localhost:8123').replace(/\/$/, '');
const username = process.env.HA_DEV_USER;
const password = process.env.HA_DEV_PASSWORD;
const clientId = `${base}/`;
const redirectUri = `${base}/?auth_callback=1`;

if (!username || !password) {
    throw new Error('HA_DEV_USER and HA_DEV_PASSWORD are required.');
}

const log = (message) => process.stderr.write(`[bootstrap-ha] ${message}\n`);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function waitUntilReady() {
    for (let attempt = 0; attempt < 90; attempt++) {
        try {
            const response = await fetch(`${base}/api/onboarding`);
            if (response.status < 500) {
                return;
            }
        } catch {
            // Not listening yet.
        }
        await sleep(2000);
    }
    throw new Error(`Home Assistant did not come up at ${base}.`);
}

async function post(path, body, { token, form = false } = {}) {
    const headers = { 'content-type': form ? 'application/x-www-form-urlencoded' : 'application/json' };
    if (token) {
        headers.authorization = `Bearer ${token}`;
    }
    const response = await fetch(`${base}${path}`, {
        method: 'POST',
        headers,
        body: form ? new URLSearchParams(body).toString() : JSON.stringify(body),
    });
    const text = await response.text();
    if (!response.ok) {
        throw new Error(`POST ${path} answered ${response.status}: ${text.slice(0, 200)}`);
    }
    return text === '' ? {} : JSON.parse(text);
}

async function pendingSteps() {
    const response = await fetch(`${base}/api/onboarding`);
    if (response.status === 404) {
        return []; // onboarding finished
    }
    const steps = await response.json();
    return steps.filter((step) => !step.done).map((step) => step.step);
}

async function authorizationCode(pending) {
    if (pending.includes('user')) {
        log('Creating the dev user through the onboarding API.');
        const { auth_code } = await post('/api/onboarding/users', { client_id: clientId, name: 'Dev', username, password, language: 'en' });
        return auth_code;
    }
    log('Logging in with the stored dev user.');
    const flow = await post('/auth/login_flow', { client_id: clientId, handler: ['homeassistant', null], redirect_uri: redirectUri });
    const result = await post(`/auth/login_flow/${flow.flow_id}`, { client_id: clientId, username, password });
    if (result.type !== 'create_entry') {
        throw new Error('Login refused: the stored dev credentials do not match this Home Assistant (reset docker/dev/ha-config/.storage and docker/dev/.env.dev).');
    }
    return result.result;
}

async function finishOnboarding(pending, accessToken) {
    for (const [step, path, body] of [
        ['core_config', '/api/onboarding/core_config', {}],
        ['analytics', '/api/onboarding/analytics', {}],
        ['integration', '/api/onboarding/integration', { client_id: clientId, redirect_uri: redirectUri }],
    ]) {
        if (!pending.includes(step)) {
            continue;
        }
        try {
            await post(path, body, { token: accessToken });
            log(`Onboarding step "${step}" done.`);
        } catch (error) {
            log(`Onboarding step "${step}" skipped: ${error.message}`);
        }
    }
}

function longLivedToken(accessToken) {
    const wsUrl = `${base.replace(/^http/, 'ws')}/api/websocket`;
    return new Promise((resolve, reject) => {
        const socket = new WebSocket(wsUrl);
        const fail = (error) => {
            socket.close();
            reject(error);
        };
        socket.addEventListener('error', () => fail(new Error('WebSocket error.')));
        socket.addEventListener('message', (event) => {
            const message = JSON.parse(event.data);
            if (message.type === 'auth_required') {
                socket.send(JSON.stringify({ type: 'auth', access_token: accessToken }));
            } else if (message.type === 'auth_ok') {
                socket.send(JSON.stringify({ id: 1, type: 'auth/long_lived_access_token', client_name: `ha-climate-dev-${Date.now()}`, lifespan: 3650 }));
            } else if (message.type === 'auth_invalid') {
                fail(new Error('Home Assistant refused the access token.'));
            } else if (message.type === 'result' && message.id === 1) {
                socket.close();
                message.success ? resolve(message.result) : reject(new Error(`auth/long_lived_access_token failed: ${JSON.stringify(message.error)}`));
            }
        });
    });
}

await waitUntilReady();
const pending = await pendingSteps();
const code = await authorizationCode(pending);
const { access_token: accessToken } = await post('/auth/token', { grant_type: 'authorization_code', code, client_id: clientId }, { form: true });
await finishOnboarding(pending, accessToken);
process.stdout.write(`${await longLivedToken(accessToken)}\n`);
