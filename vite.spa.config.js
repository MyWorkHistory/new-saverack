import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * CRM SPA served at site root via routes/spa.php → public/index.html.
 * Use VITE_APP_BASE=/subdir/ for subdirectory installs (must match trailing slash).
 * Does not empty public/: preserves public/images, build/, tickets-app/, etc.
 */
export default defineConfig({
    base: process.env.VITE_APP_BASE || '/',
    plugins: [vue()],
    root: path.resolve(__dirname, 'resources/crm'),
    publicDir: false,
    build: {
        outDir: path.resolve(__dirname, 'public'),
        emptyOutDir: false,
        rollupOptions: {
            input: path.resolve(__dirname, 'resources/crm/index.html'),
        },
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/crm'),
            '@public': path.resolve(__dirname, 'public'),
        },
    },
    server: {
        proxy: {
            '/api': { target: 'http://127.0.0.1:8000', changeOrigin: true },
            '/sanctum': { target: 'http://127.0.0.1:8000', changeOrigin: true },
            '/storage': { target: 'http://127.0.0.1:8000', changeOrigin: true },
            '/avatars': { target: 'http://127.0.0.1:8000', changeOrigin: true },
        },
    },
});
