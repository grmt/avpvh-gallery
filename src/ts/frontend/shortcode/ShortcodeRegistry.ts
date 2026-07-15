import $ from 'jquery';

import { Shortcode } from './Shortcode';

interface ShortcodeRegistry {
	shortcodes: Record<string, Shortcode>;
	init(): void;
	reflowAll(): void;
}

export const ShortcodeRegistry: ShortcodeRegistry = {
	shortcodes: {},

	init(): void {
		$('.avpvh-gallery-container').each((_, container) => {
			const hash = $(container).data('avpvhHash') as string | undefined;
			if (hash !== undefined) {
				this.shortcodes[hash.substring(0, 8)] = new Shortcode(
					container,
					hash
				);
			}
		});
	},

	reflowAll(): void {
		$.each(this.shortcodes, (_, shortcode) => {
			shortcode.reflow();
		});
	},
};
