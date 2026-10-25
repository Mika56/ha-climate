import { fileURLToPath, URL } from 'node:url'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig(({ mode }) => ({
  // Relative base: the add-on is served under a dynamic Home Assistant ingress path.
  base: './',
  plugins: [vue()],
  // The MSW service worker is only served by `npm run dev:mock`, never shipped.
  publicDir: mode === 'mock' ? 'mocks-public' : false,
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    proxy: {
      '/api': 'http://localhost:8099',
    },
  },
  test: {
    environment: 'jsdom',
    include: ['src/**/*.test.ts'],
    setupFiles: ['src/test/setup.ts'],
    coverage: {
      provider: 'v8',
      include: ['src/**/*.{ts,vue}'],
      exclude: [
        'src/main.ts',
        'src/**/*.test.ts',
        'src/**/*.d.ts',
        'src/test/**',
        'src/mocks/browser.ts',
      ],
      reporter: ['text', 'html', 'lcov'],
      thresholds: { lines: 90, functions: 90, branches: 85, statements: 90 },
    },
  },
}))
