import { createElement } from '@wordpress/element';

import { AvpvhSettingsComponent } from './AvpvhSettingsComponent';

export class AvpvhIntegerSettingsComponent extends AvpvhSettingsComponent {
	protected renderInput(): React.ReactNode {
		const disabled =
			undefined === this.props.editor.getAttribute(this.props.name);
		return createElement('input', {
			className:
				'avpvh-block-settings-integer components-range-control__number',
			disabled,
			onChange: (e: React.FormEvent) => {
				this.change(e);
			},
			placeholder: avpvhBlockLocalize[this.props.name].default,
			type: 'number',
			value: this.state.value,
		});
	}

	protected override getValue(element: EventTarget): number | undefined {
		const value = parseInt((element as HTMLInputElement).value);
		if (isNaN(value)) {
			return undefined;
		}
		return value;
	}
}
