import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  // Base path per Altervista — lascia '/' se il sito è nella root
  // Cambia in '/nomecartella/' se è in una sottocartella
  base: '/nuovo/',
  build: {
    outDir: 'dist',
    // Genera sourcemap solo in sviluppo
    sourcemap: false,
  },
  server: {
    // Proxy per sviluppo locale: redirige /api → XAMPP
    proxy: {
      '/api': {
        target: 'http://localhost/legafanta',
        changeOrigin: true,
      }
    }
  }
})
