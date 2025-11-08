import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig(() => {
    return {
        plugins: [
            laravel({
                input: [
                    'resources/css/app.css',
                    'resources/js/app.js',
                    'resources/js/show.js',
                    'resources/js/manage/archives.js',
                    'resources/js/manage/channels.js',
                    'resources/js/channels/archive-list.js',
                ],
                refresh: true,
            }),
        ],
        build: {
            outDir: 'public/build',
        }
    };
});
