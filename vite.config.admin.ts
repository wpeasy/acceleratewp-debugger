import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';

export default defineConfig({
    plugins: [svelte()],
    build: {
        outDir: 'assets/dist/admin',
        emptyOutDir: true,
        cssCodeSplit: false,
        target: 'es2022',
        minify: true,
        rollupOptions: {
            input: 'src-svelte/apps/admin/main.ts',
            output: {
                entryFileNames: 'main.js',
                assetFileNames: '[name][extname]',
                format: 'es',
            },
        },
    },
});
