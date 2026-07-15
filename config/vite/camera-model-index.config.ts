import { defineConfig } from 'vite';

export default defineConfig({
	build: {
		emptyOutDir: false,
		lib: {
			entry: 'src/ts/admin/camera-model-index.ts',
			name: 'cameraModelIndex',
			formats: ['iife'],
		},
		rollupOptions: {
			output: {
				entryFileNames: 'admin/js/camera-model-index.min.js',
			},
		},
	},
});
