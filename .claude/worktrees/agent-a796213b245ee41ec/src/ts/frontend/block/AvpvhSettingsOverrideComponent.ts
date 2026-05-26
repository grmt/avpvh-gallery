import { PanelBody } from '@wordpress/components';
import { Component, createElement } from '@wordpress/element';

import { AvpvhBooleanSettingsComponent } from './AvpvhBooleanSettingsComponent';
import type { AvpvhEditorComponent } from './AvpvhEditorComponent';
import { AvpvhIntegerSettingsComponent } from './AvpvhIntegerSettingsComponent';
import { AvpvhOrderingSettingsComponent } from './AvpvhOrderingSettingsComponent';

interface AvpvhSettingsOverrideComponentProps {
	readonly editor: AvpvhEditorComponent;
}

export class AvpvhSettingsOverrideComponent extends Component<AvpvhSettingsOverrideComponentProps> {
	public override render(): React.ReactNode {
		const { editor } = this.props;
		return createElement(PanelBody, {
			title: avpvhBlockLocalize.settings_override,
			className: 'avpvh-block-settings',
			children: [
				createElement('h3', null, avpvhBlockLocalize.grid_section_name),
				createElement(AvpvhIntegerSettingsComponent, {
					editor,
					name: 'grid_height',
				}),
				createElement(AvpvhIntegerSettingsComponent, {
					editor,
					name: 'grid_spacing',
				}),
				createElement(AvpvhBooleanSettingsComponent, {
					editor,
					name: 'dir_counts',
				}),
				createElement(AvpvhIntegerSettingsComponent, {
					editor,
					name: 'page_size',
				}),
				createElement(AvpvhBooleanSettingsComponent, {
					editor,
					name: 'page_autoload',
				}),
				createElement(AvpvhOrderingSettingsComponent, {
					editor,
					name: 'image_ordering',
				}),
				createElement(AvpvhOrderingSettingsComponent, {
					editor,
					name: 'dir_ordering',
				}),
				createElement(
					'h3',
					null,
					avpvhBlockLocalize.lightbox_section_name
				),
				createElement(AvpvhIntegerSettingsComponent, {
					editor,
					name: 'preview_size',
				}),
				createElement(AvpvhBooleanSettingsComponent, {
					editor,
					name: 'preview_loop',
				}),
			],
		});
	}
}
