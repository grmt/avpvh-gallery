<?php
/**
 * Contains the Code_String_Option class
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

/**
 * An option representing a code which the user has to fill in, with the option for the code to be locked to be read-only.
 *
 * @see String_Option
 */
final class Code_String_Option extends String_Option {

	/**
	 * Whether the option should be rendered as read-only.
	 *
	 * @var bool $is_readonly
	 */
	private $is_readonly;

	/**
	 * Whether the stored value is a secret that must never be re-echoed to the browser.
	 *
	 * @var bool $is_secret
	 */
	private $is_secret;

	/**
	 * Code_String_Option class constructor.
	 *
	 * @param string $name The name of the option to be used as the key to reference it. The prefix `avpvh_` will be added automatically.
	 * @param string $default_value The default value of the option to be returned if the option is not set.
	 * @param string $page The page in which the option will be accessible to the user. The prefix `avpvh_` will be added automatically.
	 * @param string $section The section (within the selected page) in which the option will be accessible to the user. The prefix `avpvh_` will be added automatically.
	 * @param string $title A human-readable name of the option to be displayed to the user.
	 * @param bool   $is_secret Whether the value is a secret that must never be re-echoed to the browser. Default false.
	 */
	public function __construct( $name, $default_value, $page, $section, $title, $is_secret = false ) {
		parent::__construct( $name, $default_value, $page, $section, $title );

		$this->is_readonly = false;
		$this->is_secret   = $is_secret;
	}

	/**
	 * Adds the option to the WordPress UI.
	 *
	 * This function adds the the option to the WordPress settings on page `$page` in section `$section`. The option is drawn by the `html()` method. Additionaly, the option can be set to be rendered as read-only.
	 *
	 * @see $page
	 * @see $section
	 * @see $is_readonly
	 * @see html()
	 *
	 * @param bool $is_readonly Sets whether the option should be read-only.
	 */
	public function add_field( $is_readonly = false ) {
		$this->is_readonly = $is_readonly;

		parent::add_field();
	}

	/**
	 * Sanitizes user input.
	 *
	 * For secret fields, an empty submission means "leave the stored secret unchanged" (the
	 * real value is never sent back to the browser, so the field is always submitted empty
	 * unless the user actually typed a replacement).
	 *
	 * @param mixed $value The unsanitized user input.
	 *
	 * @return mixed The sanitized value to be written to the database.
	 */
	public function sanitize( $value ) {
		if ( $this->is_secret && '' === trim( (string) $value ) ) {
			return get_option( $this->name, $this->default_value );
		}

		return parent::sanitize( $value );
	}

	/**
	 * Renders the UI for updating the option.
	 *
	 * This function renders (by calling `echo()`) the UI for updating the option, including the current value. The option will be rendered as read-only, depending on the value of the `$is_readonly` property. Secret values are never written into the page; a masked placeholder is shown instead, and the field submits empty unless the user enters a replacement.
	 *
	 * A masked, read-only secret is rendered `disabled` rather than `readonly`: a `readonly`
	 * field's (empty) value is still POSTed on save, which would look like "clear the secret"
	 * to `sanitize()`. A `disabled` field is excluded from the submission entirely, so an
	 * unrelated save elsewhere on the page can never touch it.
	 *
	 * @see $is_readonly
	 * @see $is_secret
	 */
	public function html() {
		$stored_value = get_option( $this->name, $this->default_value );
		$is_masked    = $this->is_secret && '' !== $stored_value;

		echo '<input type="text" name="' .
			esc_attr( $this->name ) .
			'" value="' .
			( $is_masked ? '' : esc_attr( $stored_value ) ) .
			'" ' .
			( $is_masked ? 'placeholder="' . esc_attr__(
				'(unchanged) Enter a new value to replace it',
				'avpvh-gallery'
			) . '" ' : '' ) .
			( $this->is_readonly ? ( $is_masked ? 'disabled ' : 'readonly ' ) : '' ) .
			'class="regular-text code">';
	}
}
