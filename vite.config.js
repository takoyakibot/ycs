import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig(({ mode }) => {
    const isLocal = process.env.APP_ENV === 'local';

    return {
        plugins: [
            laravel({
                input: [
                    'resources/css/app.css',
                    'resources/js/app.js',
                    'resources/js/show.js',
                    'resources/js/manage/archives.js',
                    'resources/js/manage/channels.js',
                ],
                refresh: true,
            }),
        ],
        build: {
            outDir: 'public/build',
        },
        server: {
            https: true,
        }
    };
});
