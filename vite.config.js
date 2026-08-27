import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const environment = loadEnv(mode, process.cwd(), '');
    const vitePort = Number.parseInt(environment.VITE_PORT || '5173', 10);
    const viteDevServerHost = environment.VITE_DEV_SERVER_HOST || 'localhost';

    return {
        server: {
            host: '0.0.0.0',
            port: vitePort,
            strictPort: true,
            origin: `http://${viteDevServerHost}:${vitePort}`,
            hmr: {
                host: viteDevServerHost,
                port: vitePort,
            },
        },
        plugins: [
            tailwindcss(),
            laravel({
                input: [
                    'resources/css/app.css',
                    'resources/js/app.js',
                    'resources/forum/blade-tailwind/css/forum.css',
                    'resources/forum/blade-tailwind/js/forum.js',
                ],
                refresh: true,
            }),
        ],
    };
});
