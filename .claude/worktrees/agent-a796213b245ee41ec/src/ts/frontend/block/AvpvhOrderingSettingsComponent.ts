import { ToggleControl } from '@wordpress/components';
import { Component, createElement } from '@wordpress/element';

import type { AvpvhEditorComponent } from './AvpvhEditorComponent';

interface AvpvhOrderingSettingsComponentProps {
	readonly editor: AvpvhEditorComponent;
	readonly name: BlockOrderingOptions;
}

interface AvpvhOrderingSettingsComponentState {
	valueBy: string;
	valueOrder: string;
}

export class AvpvhOrderingSettingsComponent extends Component<
	AvpvhOrderingSettingsComponentProps,
	AvpvhOrderingSettingsComponentState
> {
	public constructor(props: AvpvhOrderingSettingsComponentProps) {
		super(props);
		const { editor, name } = this.props;
		let valueBy = editor.getAttribute(name + '_by') as string | undefined;
		let valueOrder = editor.getAttribute(name + '_order') as
			| string
			| undefined;
		if (undefined === valueBy) {
			valueBy = avpvhBlockLocalize[name].default_by;
		}
		if (undefined === valueOrder) {
			valueOrder = avpvhBlockLocalize[name].default_order;
		}
		this.state = { valueBy, valueOrder };
	}

	public override render(): React.ReactNode {
		const { editor, name } = this.props;
		const { valueBy, valueOrder } = this.state;
		const disabledBy = undefined === editor.getAttribute(name + '_by');
		const disabledOrder =
			undefined === editor.getAttribute(name + '_order');
		return createElement('div', { className: 'avpvh-block-settings-row' }, [
			createElement(ToggleControl, {
				checked: !disabledBy && !disabledOrder,
				className: 'avpvh-block-settings-checkbox',
				label: createElement(
					'span',
					{ className: 'avpvh-block-settings-description' },
					[avpvhBlockLocalize[name].name, ':']
				),
				onChange: () => {
					this.toggle();
				},
			}),
			createElement(
				'select',
				{
					className: 'avpvh-block-settings-select',
					disabled: disabledOrder,
					onChange: (e: React.FormEvent) => {
						this.changeOrder(e);
					},
					placeholder: avpvhBlockLocalize[name].default_order,
					type: 'number',
					value: valueOrder,
				},
				[
					createElement(
						'option',
						{
							selected: 'ascending' === valueOrder,
							value: 'ascending',
						},
						avpvhBlockLocalize.ordering_option_ascending
					),
					createElement(
						'option',
						{
							selected: 'descending' === valueOrder,
							value: 'descending',
						},
						avpvhBlockLocalize.ordering_option_descending
					),
				]
			),
			createElement(
				'label',
				{
					className: 'avpvh-block-settings-radio',
					for: name + '_by_time',
				},
				[
					createElement('input', {
						checked: 'time' === valueBy,
						disabled: disabledBy,
						id: name + '_by_time',
						name: name + '_by',
						onChange: (e) => {
							this.changeBy(e);
						},
						type: 'radio',
						value: 'time',
					}),
					avpvhBlockLocalize.ordering_option_by_time,
				]
			),
			createElement(
				'label',
				{
					className: 'avpvh-block-settings-radio',
					for: name + '_by_name',
				},
				[
					createElement('input', {
						checked: 'name' === valueBy,
						disabled: disabledBy,
						id: name + '_by_name',
						name: name + '_by',
						onChange: (e) => {
							this.changeBy(e);
						},
						type: 'radio',
						value: 'name',
					}),
					avpvhBlockLocalize.ordering_option_by_name,
				]
			),
		]);
	}

	private toggle(): void {
		const { editor, name } = this.props;
		const { valueBy, valueOrder } = this.state;
		editor.setAttribute(
			name + '_by',
			undefined !== editor.getAttribute(name + '_by')
				? undefined
				: valueBy
		);
		editor.setAttribute(
			name + '_order',
			undefined !== editor.getAttribute(name + '_order')
				? undefined
				: valueOrder
		);
	}

	private changeBy(e: React.FormEvent): void {
		const { editor, name } = this.props;
		const target = e.target as HTMLInputElement;
		this.setState({ valueBy: target.value });
		editor.setAttribute(name + '_by', target.value);
	}

	private changeOrder(e: React.FormEvent): void {
		const { editor, name } = this.props;
		const target = e.target as HTMLSelectElement;
		this.setState({ valueOrder: target.value });
		editor.setAttribute(name + '_order', target.value);
	}
}
