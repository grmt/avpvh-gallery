import { ToggleControl } from '@wordpress/components';
import { Component, createElement } from '@wordpress/element';

import type { AvpvhEditorComponent } from './AvpvhEditorComponent';

interface AvpvhSettingsComponentProps {
	readonly editor: AvpvhEditorComponent;
	readonly name: BlockOptions;
}

interface AvpvhSettingsComponentState {
	value: number | string | undefined;
}

export abstract class AvpvhSettingsComponent extends Component<
	AvpvhSettingsComponentProps,
	AvpvhSettingsComponentState
> {
	public constructor(props: AvpvhSettingsComponentProps) {
		super(props);
		const { editor, name } = this.props;
		let value = editor.getAttribute(name) as string | undefined;
		if (undefined === value) {
			value = avpvhBlockLocalize[name].default;
		}
		this.state = { value };
	}

	public override render(): React.ReactNode {
		const { editor, name } = this.props;
		const disabled = undefined === editor.getAttribute(name);
		return createElement('div', { className: 'avpvh-block-settings-row ' }, [
			createElement(ToggleControl, {
				checked: !disabled,
				label: createElement(
					'span',
					{ className: 'avpvh-block-settings-description' },
					[avpvhBlockLocalize[name].name, ':']
				),
				className: 'avpvh-block-settings-checkbox',
				onChange: () => {
					this.toggle();
				},
			}),
			this.renderInput(),
		]);
	}

	protected change(e: React.FormEvent): void {
		const { editor, name } = this.props;
		const value = this.getValue(e.target);
		this.setState({ value });
		editor.setAttribute(name, value ?? avpvhBlockLocalize[name].default);
	}

	private toggle(): void {
		const { editor, name } = this.props;
		const { value } = this.state;
		editor.setAttribute(
			name,
			undefined !== editor.getAttribute(name) ? undefined : value
		);
	}

	protected abstract renderInput(): React.ReactNode;

	protected abstract getValue(
		element: EventTarget
	): number | string | undefined;
}
