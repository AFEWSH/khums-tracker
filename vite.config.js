import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: ['resources/views/**', 'app/Livewire/**'],
        }),
        tailwindcss(),
    ],
    server: {
        host: '127.0.0.1',
        strictPort: true,
    },
});
