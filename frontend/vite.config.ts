/// <reference types="vitest/config" />
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import path from 'node:path'
import { defineConfig, loadEnv } from 'vite'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, import.meta.dirname, '')
  const backendUrl = env.BACKEND_URL || 'http://localhost:8001'

  return {
    plugins: [react(), tailwindcss()],
    resolve: { alias: { '@': path.resolve(import.meta.dirname, './src') } },
    server: {
      port: 5174,
      strictPort: true,
      host: '0.0.0.0',
      // Mesma origem em dev: o navegador só fala com :5174 e o Vite repassa API e Sanctum.
      proxy: {
        '/api': { target: backendUrl },
        '/sanctum': { target: backendUrl },
      },
      watch: { usePolling: true },
    },
    test: {
      environment: 'jsdom',
      setupFiles: ['./src/test/setup.ts'],
      include: ['src/**/*.test.{ts,tsx}'],
    },
  }
})
