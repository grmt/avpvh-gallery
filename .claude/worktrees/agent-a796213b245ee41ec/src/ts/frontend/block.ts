import { registerBlockType } from '@wordpress/blocks';

import { AvpvhBlockIconComponent } from './block/AvpvhBlockIconComponent';
import { AvpvhEditorComponent } from './block/AvpvhEditorComponent';

function renderFrontend(): null {
	return null;
}

function extractFromShortcode(
	attributes: ShortcodeToBlockTransformAttributes
): Array<string> {
	if (!attributes.named['path']) {
		return [];
	}
	return attributes.named['path'].replace(/^\/+|\/+$/g, '').split('/');
}

registerBlockType('avpvh-gallery/gallery', {
	title: avpvhBlockLocalize.block_name,
	description: avpvhBlockLocalize.block_description,
	category: 'media',
	icon: AvpvhBlockIconComponent,
	attributes: {
		path: {
			type: 'array',
			default: [],
		},
		grid_height: {
			type: 'number',
		},
		grid_spacing: {
			type: 'number',
		},
		dir_counts: {
			type: 'string',
		},
		page_size: {
			type: 'number',
		},
		page_autoload: {
			type: 'string',
		},
		image_ordering_order: {
			type: 'string',
		},
		image_ordering_by: {
			type: 'string',
		},
		dir_ordering_order: {
			type: 'string',
		},
		dir_ordering_by: {
			type: 'string',
		},
		preview_size: {
			type: 'number',
		},
		preview_loop: {
			type: 'string',
		},
	},
	edit: AvpvhEditorComponent,
	save: renderFrontend,
	transforms: {
		from: [
			{
				type: 'shortcode',
				tag: 'avpvh',
				priority: 15,
				attributes: {
					path: {
						type: 'string',
						shortcode: extractFromShortcode,
					},
				},
			},
		],
	},
});
