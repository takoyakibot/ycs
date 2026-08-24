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
                    'resources/js/manage/admins.js',
                    'resources/js/manage/archives.js',
                    'resources/js/manage/channels.js',
                    'resources/js/manage/reports.js',
                    'resources/js/manage/settings.js',
                    'resources/js/channels/archive-list.js',
                    'resources/js/channels/play-history.js',
                    'resources/js/songs/normalize.js',
                    'resources/js/songs/decompose.js',
                    'resources/js/songs/duplicates.js',
                    'resources/js/songs/cleansing.js',
                ],
                refresh: true,
            }),
        ],
        build: {
            outDir: 'public/build',
        }
    };
});
