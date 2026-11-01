import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

// Builds into the public directory nntmux-web serves, with the manifest it reads for the tags.
export default defineConfig({
    plugins: [tailwindcss()],
    publicDir: false,
    base: '/build/',
    build: {
        outDir: 'public/build',
        emptyOutDir: true,
        manifest: 'manifest.json',
        rollupOptions: {
            input: ['css/app.css', 'js/app.js'],
        },
    },
});
