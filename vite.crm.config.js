import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// Subpath deploy: set VITE_APP_BASE=/your-subpath/ (leading + trailing slash) and
// use the same value when building. Router uses import.meta.env.BASE_URL automatically.
//
// Root CRM SPA is built with vite.spa.config.js → public/index.html + public/assets/.
// This config builds the same Vue app for /tickets-app/ (see routes/spa.php).
export default defineConfig({
    base: process.env.VITE_APP_BASE || '/tickets-app/',
    plugins: [vue()],
    root: path.resolve(__dirname, 'resources/crm'),
    publicDir: false,
    build: {
        outDir: path.resolve(__dirname, 'public/tickets-app'),
        emptyOutDir: true,
        rollupOptions: {
            input: path.resolve(__dirname, 'resources/crm/index.html'),
        },
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/crm'),
            // Laravel public/ assets (SVGs, etc.) — root has publicDir: false
            '@public': path.resolve(__dirname, 'public'),
        },
    },
});
