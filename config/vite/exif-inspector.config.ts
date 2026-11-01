import { defineConfig } from 'vite';

export default defineConfig({
	build: {
		emptyOutDir: false,
		lib: {
			entry: 'src/ts/admin/exif-inspector.ts',
			name: 'exifInspector',
			formats: ['iife'],
		},
		rolldownOptions: {
			output: {
				entryFileNames: 'admin/js/exif-inspector.min.js',
			},
		},
	},
});
