import { defineConfig } from 'vite';

export default defineConfig({
    build: {
        outDir: 'assets/dist',
        emptyOutDir: false,
        cssCodeSplit: false,
        target: 'es2022',
        minify: true,
        rollupOptions: {
            input: 'src-svelte/shared/index.ts',
            output: {
                entryFileNames: 'shared.js',
                format: 'iife',
                name: 'AWPD_SHARED',
            },
        },
    },
});
