import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import { createAppI18n } from './i18n'
import { createAppRouter } from './router'
import './styles/main.css'

async function bootstrap(): Promise<void> {
  let eventSource: typeof EventSource | undefined
  if (import.meta.env.MODE === 'mock') {
    // `npm run dev:mock`: MSW answers the API and a fake EventSource replays live updates.
    const { startMocks } = await import('./mocks/browser')
    eventSource = await startMocks()
  }
  if (eventSource) window.EventSource = eventSource

  createApp(App).use(createPinia()).use(createAppI18n()).use(createAppRouter()).mount('#app')
}

void bootstrap()
