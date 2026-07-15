import { defineConfig } from 'vite';

export default defineConfig({
	build: {
		emptyOutDir: false,
		lib: {
			entry: 'src/ts/admin/folder-authors.ts',
			name: 'folderAuthors',
			formats: ['iife'],
		},
		rollupOptions: {
			output: {
				entryFileNames: 'admin/js/folder-authors.min.js',
			},
		},
	},
});
