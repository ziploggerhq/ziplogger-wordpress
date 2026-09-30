<?php
/**
 * Small, accessible form-control renderers for the settings screens.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Every control has a real <label for>, help text tied to it with aria-describedby, and escaped
 * output. Field names for module settings are ziplogger[<module>][<key>].
 */
final class Fields {

	/**
	 * Input name for a module field.
	 *
	 * @param string $module Module.
	 * @param string $key    Key.
	 * @return string
	 */
	public static function name( $module, $key ) {
		return 'ziplogger[' . $module . '][' . $key . ']';
	}

	/**
	 * DOM id for a module field.
	 *
	 * @param string $module Module.
	 * @param string $key    Key.
	 * @return string
	 */
	public static function id( $module, $key ) {
		return 'ziplogger-' . $module . '-' . str_replace( '_', '-', $key );
	}

	/**
	 * Render the rows of a module form from a field specification.
	 *
	 * Spec keys: key, type (checkbox|number|select|textarea|text|roles), label, help, and per type:
	 * min/max/step/unit (number), options (select), rows (textarea), placeholder.
	 *
	 * @param string  $module Module.
	 * @param array[] $specs  Field specifications.
	 * @param array   $values Current values of the module.
	 * @return void
	 */
	public static function render_module_rows( $module, array $specs, array $values ) {
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $specs as $spec ) {
			$key   = $spec['key'];
			$id    = self::id( $module, $key );
			$name  = self::name( $module, $key );
			$value = isset( $values[ $key ] ) ? $values[ $key ] : '';
			$help  = isset( $spec['help'] ) ? $spec['help'] : '';
			$hid   = $id . '-help';
			$desc  = '' !== $help ? ' aria-describedby="' . esc_attr( $hid ) . '"' : '';

			echo '<tr><th scope="row">';
			if ( 'checkbox' === $spec['type'] ) {
				echo esc_html( $spec['label'] );
				echo '</th><td>';
				printf(
					'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s%4$s /> %5$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( ! empty( $value ), true, false ),
					$desc, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
					esc_html( isset( $spec['checkbox_label'] ) ? $spec['checkbox_label'] : $spec['label'] )
				);
			} elseif ( 'roles' === $spec['type'] ) {
				echo esc_html( $spec['label'] );
				echo '</th><td><fieldset><legend class="screen-reader-text"><span>' . esc_html( $spec['label'] ) . '</span></legend>';
				foreach ( wp_roles()->roles as $slug => $role ) {
					$rid = $id . '-' . $slug;
					printf(
						'<label for="%1$s" style="display:block"><input type="checkbox" id="%1$s" name="%2$s[]" value="%3$s" %4$s /> %5$s</label>',
						esc_attr( $rid ),
						esc_attr( $name ),
						esc_attr( $slug ),
						checked( is_array( $value ) && in_array( $slug, $value, true ), true, false ),
						esc_html( translate_user_role( $role['name'] ) )
					);
				}
				echo '</fieldset>';
			} else {
				printf( '<label for="%s">%s</label></th><td>', esc_attr( $id ), esc_html( $spec['label'] ) );
				switch ( $spec['type'] ) {
					case 'number':
						printf(
							'<input type="number" id="%1$s" name="%2$s" value="%3$s" min="%4$s" max="%5$s" step="%6$s" class="small-text"%7$s /> %8$s',
							esc_attr( $id ),
							esc_attr( $name ),
							esc_attr( (string) $value ),
							esc_attr( (string) ( isset( $spec['min'] ) ? $spec['min'] : 0 ) ),
							esc_attr( (string) ( isset( $spec['max'] ) ? $spec['max'] : 100 ) ),
							esc_attr( (string) ( isset( $spec['step'] ) ? $spec['step'] : 1 ) ),
							$desc, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $desc is ' aria-describedby="' . esc_attr( $hid ) . '"', built above from an escaped value.
							esc_html( isset( $spec['unit'] ) ? $spec['unit'] : '' )
						);
						break;
					case 'select':
						printf( '<select id="%1$s" name="%2$s"%3$s>', esc_attr( $id ), esc_attr( $name ), $desc ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $desc is ' aria-describedby="' . esc_attr( $hid ) . '"', built above from an escaped value.
						foreach ( $spec['options'] as $option_value => $option_label ) {
							printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( (string) $option_value ), selected( (string) $value, (string) $option_value, false ), esc_html( $option_label ) );
						}
						echo '</select>';
						break;
					case 'textarea':
						printf(
							'<textarea id="%1$s" name="%2$s" rows="%3$d" cols="50" class="large-text code" spellcheck="false" placeholder="%4$s"%5$s>%6$s</textarea>',
							esc_attr( $id ),
							esc_attr( $name ),
							isset( $spec['rows'] ) ? (int) $spec['rows'] : 4,
							esc_attr( isset( $spec['placeholder'] ) ? $spec['placeholder'] : '' ),
							$desc, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $desc is ' aria-describedby="' . esc_attr( $hid ) . '"', built above from an escaped value.
							esc_textarea( (string) $value )
						);
						break;
					default:
						printf(
							'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text" placeholder="%4$s"%5$s />',
							esc_attr( $id ),
							esc_attr( $name ),
							esc_attr( (string) $value ),
							esc_attr( isset( $spec['placeholder'] ) ? $spec['placeholder'] : '' ),
							$desc // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $desc is ' aria-describedby="' . esc_attr( $hid ) . '"', built above from an escaped value.
						);
				}
			}
			if ( '' !== $help ) {
				echo '<p class="description" id="' . esc_attr( $hid ) . '">' . esc_html( $help ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Hidden fields every settings form needs: the action, the section and a nonce.
	 *
	 * @param string $section Section (also names the nonce).
	 * @param string $tab     Tab to return to.
	 * @return void
	 */
	public static function form_header( $section, $tab ) {
		echo '<input type="hidden" name="action" value="ziplogger_save" />';
		echo '<input type="hidden" name="section" value="' . esc_attr( $section ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';
		wp_nonce_field( 'ziplogger_save' );
	}
}
