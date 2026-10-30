import { defineConfig, type UserConfig } from 'vite';

export function viteConfig(
	sitePart: 'admin' | 'frontend',
	entry: string
): UserConfig {
	return defineConfig({
		build: {
			emptyOutDir: false,
			lib: {
				entry: `src/ts/${sitePart}/${entry}.ts`,
				name: entry,
				formats: ['iife'],
			},
			rolldownOptions: {
				external: [
					'@wordpress/block-editor',
					'@wordpress/blocks',
					'@wordpress/components',
					'@wordpress/editor',
					'@wordpress/element',
					'jquery',
					'tinymce',
				],
				output: {
					entryFileNames: `${sitePart}/js/[name].min.js`,
					globals: {
						'@wordpress/block-editor': 'wp.blockEditor',
						'@wordpress/blocks': 'wp.blocks',
						'@wordpress/components': 'wp.components',
						'@wordpress/editor': 'wp.editor',
						'@wordpress/element': 'wp.element',
						jquery: 'jQuery',
						tinymce: 'tinymce',
					},
				},
			},
		},
	});
}
