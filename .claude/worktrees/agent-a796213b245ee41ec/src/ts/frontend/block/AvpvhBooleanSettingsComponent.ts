import { createElement } from '@wordpress/element';

import { AvpvhSettingsComponent } from './AvpvhSettingsComponent';

export class AvpvhBooleanSettingsComponent extends AvpvhSettingsComponent {
	protected renderInput(): React.ReactNode {
		const disabled =
			undefined === this.props.editor.getAttribute(this.props.name);
		return createElement('input', {
			checked: 'true' === this.state.value,
			className: 'avpvh-block-settings-boolean',
			disabled,
			onChange: (e: React.FormEvent) => {
				this.change(e);
			},
			type: 'checkbox',
		});
	}

	protected override getValue(element: EventTarget): string {
		return (element as HTMLInputElement).checked ? 'true' : 'false';
	}
}
