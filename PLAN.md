# Plan de développement : add-on Home Assistant chauffage & climatisation

## Contexte

Source : le doc « Add-on HA chauffage et clim : notes de brainstorming ». Objectif : un add-on Home Assistant (conteneur + UI via ingress) qui pilote chauffage **et** clim avec parité totale, piloté par événements (WebSocket HA, zéro polling), reprise sans à-coups après redémarrage, audit exhaustif, réglages par pièce + surcharges globales.

Le répertoire `/home/mikael/dev/ha-climate` est vide : projet from scratch.

Contraintes imposées :
- Implémentation par des **agents Sonnet** (orchestrés par la session principale).
- Code, identifiants, commentaires, commits **en anglais** ; bonnes pratiques PHP (`declare(strict_types=1)`, classes `final`/`readonly` par défaut, PSR-4, injection de dépendances, pas d'état global).
- **php-cs-fixer** ruleset `@Symfony` ; **PHPStan niveau max** ; **PHPUnit** avec `#[CoversClass]` obligatoire sur chaque test et **couverture > 90 %**.
- Publication sur **GitHub public**, installable en ajoutant l'URL du dépôt dans Paramètres → Add-ons → Boutique → Dépôts.

Décisions prises avec l'utilisateur : **Vue 3 + Vite** ; **images pré-construites sur GHCR** ; **anti-cyclage en v1** (durées min marche/arrêt + hystérésis) ; alertes d'indisponibilité = **section UI + notification + persistent_notification HA** (retirée au retour du capteur).

Décisions tranchées dans ce plan (questions ouvertes du doc) :
- **Chauffer et climatiser en même temps** : impossible par construction — le sélecteur de saison (hiver / été) détermine le seul mode actif. Depuis la 0.2.0, « coupé » n'est plus une saison : c'est l'interrupteur séparé « HA Climate actif / en pause » (en pause : le moteur observe et décide, journalise ce qu'il *aurait* fait, mais n'envoie rien à Home Assistant).
- **Écouteur WS ↔ Symfony** : voir Architecture (base SQLite partagée + canal HTTP interne en localhost).

## Stack

| Couche | Choix |
|---|---|
| Langage | PHP 8.5 |
| Framework | Symfony 7.4 LTS (console, http-kernel, doctrine, serializer, validator, translation, clock, uid) |
| Persistance | SQLite dans `/data` (persistant, inclus dans les backups HA), Doctrine ORM + Migrations |
| Démon temps réel | Commande console `app:engine:run` sur AMPHP v3 / Revolt : `amphp/websocket-client`, `amphp/http-client`, `amphp/http-server` |
| Front | Vue 3 + TypeScript + Vite, vue-i18n, Pinia, Vitest, ESLint + Prettier |
| Conteneur | Base `ghcr.io/home-assistant/{arch}-base` (Alpine), s6-overlay v3, nginx + php-fpm 8.4 |
| Archis | amd64, aarch64 (armv7/i386 dépréciés par HA) |

## Architecture

```
            HA Supervisor (ingress 172.30.32.2)
                        │
                     nginx :8099 ──────────────┐
          /api/*, /  → php-fpm (Symfony web)   │ /api/live (SSE) → engine :8100
                        │                      │
                        ▼                      ▼
                SQLite /data/app.db  ◄──►  engine (AMPHP, long-running)
                        ▲   POST /internal/* (localhost + token)
                        │                      │ WS ws://supervisor/core/websocket
                        └──────────────────────┘ (SUPERVISOR_TOKEN)
```

- **Engine** = seul décideur. Il tient le cache d'états HA (`subscribe_entities` filtré sur les seules entités configurées), les minuteurs, évalue les pièces, envoie les commandes, écrit l'audit, sert le flux SSE temps réel et un mini-serveur HTTP interne (`127.0.0.1:8100`, jeton partagé généré au démarrage dans `/data`).
- **Web Symfony** (php-fpm) = configuration CRUD, historique/audit, sauvegarde/restauration, découverte. Après chaque modification il notifie l'engine (`POST /internal/reload`, `/internal/boost`…) ; les actions temps réel (boost) passent par l'engine.
- **Mode standalone pour le dev** : variables `HA_URL` / `HA_TOKEN` (jeton longue durée) à la place de `SUPERVISOR_TOKEN`, pour tourner hors Supervisor contre un HA de dev.
- **Ingress** : nginx n'accepte que `172.30.32.2`, base path lu depuis `X-Ingress-Path`, front buildé avec `base: './'`.

### Structure du dépôt (= dépôt d'add-ons HA)

```
repository.yaml                 # name, url, maintainer
README.md  LICENSE (MIT)
.github/workflows/ci.yml  release.yml  dependabot.yml
ha_climate/                     # dossier de l'add-on (slug)
  config.yaml  DOCS.md  CHANGELOG.md  icon.png  logo.png  apparmor.txt
  translations/en.yaml fr.yaml  # libellés des options de l'add-on
  Dockerfile                    # multi-stage (front Node → composer → runtime)
  rootfs/etc/s6-overlay/s6-rc.d/{init-app,php-fpm,nginx,engine}/
  rootfs/etc/nginx/  rootfs/etc/php85/
app/                            # application Symfony
  src/Domain/  src/Application/  src/Infrastructure/  src/UI/
  tests/Unit/  tests/Integration/  tests/Functional/
  migrations/  translations/{messages,audit}.{en,fr}.yaml
frontend/                       # Vue 3 + Vite
compose.dev.yaml                # HA core de dev + add-on en standalone
```

Nom provisoire : add-on « HA Climate », slug `ha_climate`, dépôt `<owner>/ha-climate` (owner à confirmer avant publication).

### Modèle de domaine (`app/src/Domain`, PHP pur, sans dépendance framework)

- `Season` (enum Winter/Summer) → `HvacMode` actif (heat/cool) ; la pause n'est pas une règle du domaine (`GlobalSettings.paused`, appliquée par le moteur).
- `Room` : capteur de température, entité de présence (optionnelle), capteurs ouvrants, délais (absence pièce, coupure fenêtre, notification fenêtre), planning chauffage + planning clim, températures par preset et par mode.
- `Device` : entité `climate.*`, rôle (heating / cooling / reversible), anti-cyclage (`minOnDuration`, `minOffDuration`, `hysteresis`).
- `Preset` (comfort, eco, night, away… extensibles) ; `RoomPresetTemperature(room, preset, mode, °C)`.
- `WeeklySchedule` + `ScheduleSlot(day, start, preset)` ; un début de créneau peut être **fixe ou ancré sur une entité HA** (`input_datetime`, ex. heure du coucher) → souplesse « façon Netatmo ». Un planning est partageable entre pièces.
- `Override` runtime : `Boost(delta, endsAt | untilNextSlot)`, `ManualOverride` (changement manuel traité comme boost de durée paramétrable), `WindowCut`.
- `GlobalSettings` : saison, délai « personne à la maison », vacances (date de retour → relance avec préchauffage), durée des surcharges manuelles, rétention d'audit, entité météo, langue, destinations de notification.

**Résolution de consigne** : `TargetResolver` applique une chaîne de règles (`RoomRuleInterface`, services taggués par priorité, chaque règle dans son propre fichier → features développables en parallèle) et renvoie une `Decision(hvacMode, target|off, Reason)` :

1. Saison Off → off
2. Vacances (avant date de retour − préchauffage) → off / preset away
3. Personne à la maison depuis > délai → off
4. Ouvrant ouvert depuis > délai de coupure → off (pièce)
5. Boost / surcharge manuelle actifs → consigne planifiée ± delta
6. Pièce inoccupée depuis > délai → preset eco
7. Planning (avec **préchauffage/pré-refroidissement** : démarrage anticipé = Δ°C / vitesse apprise, plafonné)

`Reason` porte une clé i18n + paramètres, réutilisée par l'audit et l'UI.

### Engine (`app/src/Application/Engine`, `app/src/Infrastructure/HomeAssistant`)

- `HomeAssistantGatewayInterface` (getStates, subscribeEntities, callService, getServices, registries, getConfig) ; impl AMPHP + **fake** pour les tests.
- `StateStore`, `RoomEvaluator` (réévalue uniquement les pièces impactées par un événement), `CommandExecutor` :
  - dédoublonnage (n'envoie rien si l'appareil est déjà dans l'état voulu) ;
  - **anti-cyclage** : diffère une commande violant min marche/arrêt, programme un minuteur, audite le report ;
  - **distinction commandes propres / manuelles** : mémorise les `context.id` renvoyés par `call_service` ; un `state_changed` dont le contexte n'est pas le nôtre et qui modifie consigne/mode → `ManualOverride`.
- `TimerScheduler` : minuteurs Revolt **persistés en base** (`due_at`) ; rechargés au boot.
- **Reprise après redémarrage** : boot → recharge config, minuteurs, overrides, alertes, dernières commandes → `get_states` → réévaluation ; n'envoie une commande que si la décision diffère de l'état réel de l'appareil ; ne renvoie pas une notification déjà émise.
- Reconnexion WS avec backoff exponentiel + resynchronisation complète.
- `HeatingRateLearner` : vitesse de montée/descente par pièce et par mode (EWMA), persistée.
- `AuditLogger` : tout changement utilisateur (acteur, avant/après) et toute décision automatique (cause). Purge quotidienne selon rétention.
- `AlertManager` : capteur/appareil `unavailable`/`unknown` → alerte active en base + notification (si activée) + `persistent_notification.create` ; levée au retour + `persistent_notification.dismiss`. Aucun repli sur la logique de pilotage.
- `NotificationService` : types fenêtre restée ouverte, consigne non applicable, appareil ne répond plus (commande sans changement d'état après délai), capteur indisponible ; chaque type activable ; destinations choisies parmi les services `notify.*` découverts.
- Serveur HTTP interne : `/internal/reload`, `/internal/boost`, `/internal/discovery`, `/api/live` (SSE de l'état des pièces).

### Web & API (`app/src/UI`)

API JSON REST sous `/api` (contrat **OpenAPI** écrit en premier, partagé avec le front) : pièces, appareils, presets, plannings, réglages globaux, saison, vacances, boosts, notifications, découverte (personnes, `climate`, `binary_sensor` door/window/occupancy, `sensor` température, `weather`, `notify`, zones, areas), audit paginé/filtré, alertes, météo, export/import de configuration (JSON versionné, validé, audité). Validation via Symfony Validator, DTO `readonly`, Serializer.

### Front (`frontend/`)

Rendu « natif HA » : variables CSS HA (`--primary-color`, `--card-background-color`…, thème clair/sombre suivant `prefers-color-scheme`), police Roboto, icônes `@mdi/js`, cartes et listes au style HA. Écrans : vue d'ensemble temps réel (SSE) avec boost par pièce + bandeau alertes + météo ; pièce (capteurs, appareils, températures par preset, délais) ; éditeur de planning hebdo (glisser les bornes, créneaux ancrés sur entité) ; réglages globaux (saison, absence, vacances) ; notifications ; historique/audit ; sauvegarde/restauration. i18n **en + fr** dès le départ (vue-i18n côté front, Symfony Translation côté back pour l'audit et les notifications).

**Langue et thème automatiques** (le sélecteur reste, valeur par défaut « Automatique ») :
- Le panneau ingress est servi sur la même origine que HA : le front lit `window.parent.document.querySelector('home-assistant').hass` → `hass.language` (langue du profil de l'utilisateur connecté), `hass.themes.darkMode` (mode sombre effectif) et les variables CSS du thème actif (`--primary-color`…) pour s'aligner sur le thème HA. Réagit aux changements à chaud (observateur sur le parent).
- Replis, dans l'ordre : choix explicite de l'utilisateur → profil HA → langue serveur (`get_config.language`, exposée par `GET /api/context`) → `navigator.language` → `en`. Thème : choix explicite → `hass.themes.darkMode` → `prefers-color-scheme`.
- Le choix explicite est stocké par utilisateur HA, identifié par l'en-tête `X-Remote-User-Id` que le Supervisor ajoute aux requêtes ingress (table `user_preference`).
- Côté serveur (notifications, persistent_notification, libellés d'audit exportés) : pas d'utilisateur → langue de HA (`get_config`) ou valeur forcée dans les réglages.
- Langue non supportée (ex. `de`) → `en`. Accès hors ingress (dev standalone) : le parent n'est pas lisible, les replis s'appliquent.

## Qualité (gates bloquants pour chaque agent)

- `app/.php-cs-fixer.dist.php` : `@Symfony` + `declare_strict_types`, `risky` autorisé.
- `app/phpstan.dist.neon` : `level: max`, extensions `phpstan-symfony`, `phpstan-doctrine`, `phpstan-phpunit`, `phpstan-strict-rules` ; **pas de baseline, pas d'`ignoreErrors`**.
- `app/phpunit.dist.xml` (PHPUnit 12) : `requireCoverageMetadata="true"`, `beStrictAboutCoverageMetadata="true"`, `failOnRisky`, `failOnWarning`, source = `src/` (exclus : `Kernel.php`, `migrations/`). Ce paramétrage **rend `#[CoversClass]` obligatoire** sur chaque test.
- Seuil de couverture : script `app/bin/coverage-check` lisant le clover → échec si lignes < 90 % (cible visée 95 %). Driver `pcov` en CI.
- Tests : unitaires (domaine, règles, executor avec `MockClock`), scénarios engine (fake gateway : « fenêtre ouverte → coupure après 2 min → notification après 30 min → fermeture → relance »), intégration client WS contre un serveur `amphp/websocket-server` lancé dans le test, fonctionnels API (`WebTestCase` + SQLite, `dama/doctrine-test-bundle`).
- Autres lints : `lint:container`, `lint:yaml`, `doctrine:schema:validate`, `composer validate --strict`, `composer audit`, hadolint, `frenck/action-addon-linter`.
- Front : `vue-tsc`, ESLint, Prettier, Vitest (couverture ≥ 80 %).
- Scripts : `composer ci` (cs check + phpstan + tests + coverage-check) et `npm run ci`. **Définition de « fini » pour toute tâche : les deux sont verts.**

## Organisation des agents Sonnet

- La session principale orchestre : elle écrit le brief de chaque agent (`Agent`, `model: "sonnet"`), relit le diff, relance `composer ci`/`npm run ci`, merge.
- Agents en parallèle uniquement sur des répertoires disjoints, avec `isolation: "worktree"` ; un agent = une branche = un lot de commits conventionnels.
- Brief type : contexte du projet + extrait de ce plan, périmètre de fichiers autorisé, interfaces à respecter, scénarios de tests attendus, DoD ci-dessus. Interdits : modifier les configs qualité, baseline/ignore PHPStan, `@codeCoverageIgnore`, tests sans assertions.
- Git initialisé en phase 0 sur `main` ; chaque phase se termine par un merge relu et un tag interne.

## Phases

**Phase 0 — Socle (1 agent)**
`git init`, squelette Symfony 7.4 dans `app/`, Vite+Vue dans `frontend/`, configs qualité, `coverage-check`, Makefile, `compose.dev.yaml` (HA core de dev), workflow `ci.yml`. DoD : CI verte sur un test trivial couvert.

**Phase 1 — Fondations (3 agents en parallèle)**
- 1a Domaine : value objects, `Season`, presets, `WeeklySchedule` (résolution du créneau courant/suivant, ancrage entité), `TargetResolver` + interface de règle + règles saison/planning.
- 1b Persistance : entités Doctrine, migrations, repositories, minuteurs/overrides/alertes/audit persistés.
- 1c Client HA : gateway interface, impl AMPHP (auth, ids de message, `subscribe_entities`, `call_service`, registres, reconnexion), fake gateway, tests d'intégration WS.

**Phase 2 — Engine (1 agent, séquentiel)**
`app:engine:run`, StateStore, RoomEvaluator, CommandExecutor (dédoublonnage + détection contexte propre), TimerScheduler persistant, reprise après redémarrage, AuditLogger + purge, serveur HTTP interne + SSE.

**Phase 3 — Fonctionnalités par règles (jusqu'à 3 agents en parallèle, fichiers disjoints)**
- 3a Présence globale + par pièce, délais d'absence.
- 3b Ouvrants : coupure, notification « toujours ouverte / consigne non applicable », relance.
- 3c Boost (delta, durée ou prochain créneau) + surcharge manuelle détectée.
- 3d Vacances + préchauffage/pré-refroidissement (learner).
- 3e Anti-cyclage dans l'executor.
- 3f Alertes d'indisponibilité + persistent_notification + « appareil ne répond plus » + NotificationService.

**Phase 4 — API web (1 agent) ∥ Phase 5 — Front (1–2 agents)**
Contrat OpenAPI figé d'abord par l'orchestrateur, puis API et front en parallèle (front développé contre un mock du contrat). API : CRUD, découverte, audit, backup/restore, i18n back. Front : écrans listés plus haut, thème HA, i18n en/fr.

**Phase 6 — Packaging add-on (1 agent)**
Dockerfile multi-stage (front buildé sur `$BUILDPLATFORM`, `composer install --no-dev --classmap-authoritative`, opcache + preload), services s6 (`init-app` oneshot : migrations + jeton interne ; `php-fpm`, `nginx`, `engine` longrun avec redémarrage), nginx ingress, `config.yaml` (`ingress: true`, `ingress_port: 8099`, `panel_icon: mdi:thermostat`, `homeassistant_api: true`, `hassio_api` seulement si nécessaire, `backup: hot`, `watchdog`, `image: ghcr.io/<owner>/{arch}-ha-climate`, `arch: [amd64, aarch64]`), traductions des options, AppArmor, `DOCS.md`.

**Phase 7 — CI/CD & publication (orchestrateur + 1 agent)**
- `ci.yml` (PR/push) : PHP CI, front CI, hadolint, addon-linter, build Docker de test.
- `release.yml` (tag `vX.Y.Z`) : matrice amd64 (`ubuntu-24.04`) / aarch64 (`ubuntu-24.04-arm`, pas de QEMU), build-arg `BUILD_FROM`, push GHCR, vérification que `config.yaml.version` = tag, GitHub Release avec CHANGELOG.
- README : bouton « Add repository » (my.home-assistant.io) + instructions manuelles.
- Avec l'accord explicite de l'utilisateur au moment voulu : création du dépôt public, push, tag `v0.1.0`, passage des packages GHCR en public.

## Vérification de bout en bout

1. `make ci` (= `composer ci` + `npm run ci`) vert, couverture affichée > 90 %, PHPStan max sans erreur, `php-cs-fixer --dry-run` propre.
2. `docker compose -f compose.dev.yaml up` : HA de dev avec entités factices (template/`input_*` simulant thermostats, fenêtres, présence) ; dérouler la checklist de scénarios : planning, préchauffage, absence globale, fenêtre (2 délais), boost, changement manuel, vacances, anti-cyclage, capteur indisponible (alerte + persistent_notification + retrait), redémarrage de l'engine en cours de minuteur (reprise sans commande intempestive), audit et rétention, export/import, bascule fr/en.
3. Build des deux images en local (`docker buildx`) puis release de test.
4. Dans le vrai HA : Add-ons → Boutique → ⋮ → Dépôts → URL GitHub → installer, démarrer, ouvrir le panneau ingress, vérifier découverte et pilotage d'une pièce réelle (l'instance est aussi interrogeable via le MCP HA pour contrôler les entités).

## Hors v1 (rappel du doc)

Mode simulation, hors-gel, copie de profils entre pièces, anticipation météo, été/hiver automatique.
