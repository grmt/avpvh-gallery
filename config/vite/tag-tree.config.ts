import { defineConfig } from 'vite';

export default defineConfig({
	build: {
		emptyOutDir: false,
		lib: {
			entry: 'src/ts/admin/tag-tree.ts',
			name: 'tagTree',
			formats: ['iife'],
		},
		rollupOptions: {
			output: {
				entryFileNames: 'admin/js/tag-tree.min.js',
			},
		},
	},
});
