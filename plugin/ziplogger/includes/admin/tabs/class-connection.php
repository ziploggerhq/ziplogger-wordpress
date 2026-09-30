<?php
/**
 * Connection tab: credentials, endpoint and destination policy.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Admin\Tabs;

use ZipLogger\WordPress\Admin\Fields;
use ZipLogger\WordPress\Admin\Settings_Page;
use ZipLogger\WordPress\Endpoint;
use ZipLogger\WordPress\Meta_Store;
use ZipLogger\WordPress\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Three credentials, kept apart on purpose:
 *
 * The credentials, one for each purpose:
 *   server   sends logs, events and traces from PHP. Private.
 *   browser  ingestion-only, delivered to every visitor's browser. Public by design, so it must be a
 *            key that can do nothing but send telemetry, and it must not be the server key.
 *   read     lets this screen read recent errors back from ZipLogger, on the server only.
 *
 * No key is ever printed: the screen shows where it comes from and a masked ending.
 */
final class Connection {

	/**
	 * Render.
	 *
	 * @param array $s Settings.
	 * @param array $h Health snapshot.
	 * @return void
	 */
	public static function render( array $s, array $h ) {
		Common::held_panel( $h );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ziplogger-form" autocomplete="off">';
		Fields::form_header( 'connection', 'connection' );

		echo '<h2>' . esc_html__( 'API keys', 'ziplogger' ) . '</h2>';
		echo '<p>' . esc_html__( 'Create keys in ZipLogger under Settings, API keys. Use a separate key for each purpose so each can be revoked on its own. Keys are stored in the database and never shown again; to keep one out of the database entirely, define it in wp-config.php (constant names are shown below) or set it as an environment variable.', 'ziplogger' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		self::key_row(
			'server',
			__( 'Server key', 'ziplogger' ),
			__( 'An ingestion key. PHP uses it to send logs, events and traces. It never appears in a web page.', 'ziplogger' ),
			'ZIPLOGGER_API_KEY'
		);
		self::key_row(
			'browser',
			__( 'Browser key', 'ziplogger' ),
			__( 'A separate ingestion-only key for browser monitoring, analytics, replay and browser tracing. It IS delivered to every visitor\'s browser, so create it just for this, and never use a key that can read data.', 'ziplogger' ),
			'ZIPLOGGER_BROWSER_KEY'
		);
		self::key_row(
			'read',
			__( 'Read key (optional)', 'ziplogger' ),
			__( 'A key with the read scope, used only on the server to show recent errors and trends in this dashboard. It is never sent to a browser. Without it the dashboard shows local delivery health and links into ZipLogger.', 'ziplogger' ),
			'ZIPLOGGER_READ_KEY'
		);
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Site', 'ziplogger' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="ziplogger-source">' . esc_html__( 'Site label', 'ziplogger' ) . '</label></th><td>';
		printf( '<input type="text" id="ziplogger-source" name="source" value="%s" class="regular-text" maxlength="64" aria-describedby="ziplogger-source-help" />', esc_attr( $s['source'] ) );
		echo '<p class="description" id="ziplogger-source-help">' . esc_html__( 'Shown as the "source" of every event. Use a different label per site to tell sites apart in ZipLogger. Letters, numbers, dots, dashes and underscores.', 'ziplogger' ) . '</p></td></tr>';

		$env_options = array_merge( array( '' ), Settings::ENVIRONMENTS );
		if ( '' !== $s['environment'] && ! in_array( $s['environment'], $env_options, true ) ) {
			$env_options[] = $s['environment'];
		}
		echo '<tr><th scope="row"><label for="ziplogger-environment">' . esc_html__( 'Environment', 'ziplogger' ) . '</label></th><td>';
		echo '<select id="ziplogger-environment" name="environment">';
		foreach ( $env_options as $env ) {
			$label = '' === $env
				/* translators: %s: detected environment type, e.g. production. */
				? sprintf( __( 'Detect automatically (currently: %s)', 'ziplogger' ), Settings::environment() )
				: $env;
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $env ), selected( $s['environment'], $env, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Endpoint', 'ziplogger' ) . '</th><td>';
		printf( '<p><code>%s</code></p>', esc_html( '' !== $h['endpoint'] ? $h['endpoint'] : Endpoint::DEFAULT_BASE ) );
		if ( '' !== $h['endpoint_problem'] ) {
			echo '<p class="ziplogger-error"><strong>' . esc_html__( 'The configured endpoint cannot be used:', 'ziplogger' ) . '</strong> ' . esc_html( $h['endpoint_problem'] ) . '</p>';
		}
		if ( 'constant' === Settings::endpoint_source() ) {
			echo '<p class="description">' . esc_html__( 'Set by the ZIPLOGGER_ENDPOINT constant.', 'ziplogger' ) . '</p>';
		} else {
			echo '<details class="ziplogger-advanced"><summary>' . esc_html__( 'Advanced: use a different endpoint', 'ziplogger' ) . '</summary>';
			echo '<p><label for="ziplogger-endpoint" class="screen-reader-text">' . esc_html__( 'Endpoint override', 'ziplogger' ) . '</label>';
			printf( '<input type="url" id="ziplogger-endpoint" name="endpoint" value="%1$s" class="regular-text code" placeholder="%2$s" aria-describedby="ziplogger-endpoint-help" /></p>', esc_attr( $s['endpoint'] ), esc_attr( Endpoint::DEFAULT_BASE ) );
			echo '<p class="description" id="ziplogger-endpoint-help">' . esc_html__( 'Only needed for a self-hosted ZipLogger. It must be an https:// address on a public host name. Your API keys are sent to this address, so only enter one you trust. Leave empty for the default. The browser key is also delivered to visitors together with this address.', 'ziplogger' ) . '</p></details>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'If the key or endpoint changes', 'ziplogger' ) . '</th><td><fieldset><legend class="screen-reader-text"><span>' . esc_html__( 'Queued data when the key or endpoint changes', 'ziplogger' ) . '</span></legend>';
		$policies = array(
			'hold'     => __( 'Hold items already waiting until I decide (recommended)', 'ziplogger' ),
			'retarget' => __( 'Send them to the new destination (only if it is the same workspace)', 'ziplogger' ),
			'discard'  => __( 'Discard them', 'ziplogger' ),
		);
		foreach ( $policies as $value => $label ) {
			printf(
				'<label for="ziplogger-odc-%1$s" style="display:block"><input type="radio" id="ziplogger-odc-%1$s" name="on_destination_change" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( $value ),
				checked( $s['on_destination_change'], $value, false ),
				esc_html( $label )
			);
		}
		echo '<p class="description">' . esc_html__( 'A different key can belong to a different workspace. Queued data is never sent to a workspace other than the one it was collected for unless you choose so here or on the held-data notice.', 'ziplogger' ) . '</p></fieldset></td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Save connection', 'ziplogger' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Check the connection', 'ziplogger' ) . '</h2>';
		Common::actions( 'connection' );

		echo '<h3>' . esc_html__( 'Read access for this dashboard', 'ziplogger' ) . '</h3>';
		$reason = \ZipLogger\WordPress\Dashboard\Remote::unavailable_reason();
		if ( '' !== $reason ) {
			echo '<p class="description">' . esc_html( $reason ) . '</p>';
		} else {
			echo '<div class="ziplogger-actions">';
			Settings_Page::action_form( 'ziplogger_test_read', __( 'Test the read key', 'ziplogger' ), 'secondary', 'connection' );
			echo '</div>';
			$last = ( new Meta_Store() )->get_json( 'read_test' );
			if ( ! empty( $last['at'] ) ) {
				$text = ! empty( $last['ok'] )
					? ( ! empty( $last['workspace'] )
						/* translators: %s: workspace name. */
						? sprintf( __( 'Worked; the key belongs to the workspace "%s".', 'ziplogger' ), $last['workspace'] )
						: __( 'Worked.', 'ziplogger' ) )
					: ( isset( $last['error'] ) ? (string) $last['error'] : __( 'Did not work.', 'ziplogger' ) );
				echo '<p class="description">' . esc_html( sprintf( /* translators: 1: when, 2: result. */ __( 'Last test %1$s: %2$s', 'ziplogger' ), Settings_Page::ago( (int) $last['at'], $h['now'] ), $text ) ) . '</p>';
			}
			echo '<p class="description">' . esc_html__( 'The read key is used by this server only, to fetch recent errors and trends into the tabs of this screen. It is never sent to a browser.', 'ziplogger' ) . '</p>';
		}
		Common::open_link( $h, $s['source'] );
	}

	/**
	 * One credential row.
	 *
	 * @param string $kind     server, browser or read.
	 * @param string $title    Row title.
	 * @param string $help     Purpose.
	 * @param string $constant Constant name shown for wp-config.php.
	 * @return void
	 */
	private static function key_row( $kind, $title, $help, $constant ) {
		$id      = 'ziplogger-key-' . $kind;
		$source  = Settings::key_source( $kind );
		$problem = Settings::key_problem( $kind );

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</label></th><td>';
		if ( 'constant' === $source || 'environment' === $source ) {
			printf(
				'<p><strong>%1$s</strong> <code>%2$s</code></p><p class="description">%3$s</p>',
				esc_html( 'constant' === $source ? sprintf( /* translators: %s: constant name */ __( 'Set by the %s constant:', 'ziplogger' ), $constant ) : sprintf( /* translators: %s: variable name */ __( 'Set by the %s environment variable:', 'ziplogger' ), $constant ) ),
				esc_html( Settings::key_hint( $kind ) ),
				esc_html__( 'To change it, edit wp-config.php or the server environment. A key saved in the database is ignored while this is set.', 'ziplogger' )
			);
		} else {
			if ( 'saved' === $source ) {
				printf( '<p><strong>%1$s</strong> <code>%2$s</code></p>', esc_html__( 'A key is saved:', 'ziplogger' ), esc_html( Settings::key_hint( $kind ) ) );
			} elseif ( 'invalid' === $source ) {
				echo '<p><strong>' . esc_html__( 'The stored key is not valid.', 'ziplogger' ) . '</strong></p>';
			} else {
				echo '<p><strong>' . esc_html__( 'No key saved.', 'ziplogger' ) . '</strong></p>';
			}
			printf(
				'<input type="password" id="%1$s" name="key[%2$s]" value="" class="regular-text" autocomplete="new-password" spellcheck="false" autocapitalize="off" aria-describedby="%1$s-help" placeholder="%3$s" />',
				esc_attr( $id ),
				esc_attr( $kind ),
				esc_attr( 'saved' === $source ? __( 'Paste a new key to replace the saved one', 'ziplogger' ) : __( 'Paste a key (zk_...)', 'ziplogger' ) )
			);
			if ( 'none' !== $source ) {
				printf(
					'<p><label for="%1$s-remove"><input type="checkbox" id="%1$s-remove" name="remove_key[%2$s]" value="1" /> %3$s</label></p>',
					esc_attr( $id ),
					esc_attr( $kind ),
					esc_html__( 'Remove the saved key', 'ziplogger' )
				);
			}
			/* translators: %s: constant name */
			echo '<p class="description">' . esc_html( sprintf( __( 'Or define %s in wp-config.php.', 'ziplogger' ), $constant ) ) . '</p>';
		}
		echo '<p class="description" id="' . esc_attr( $id ) . '-help">' . esc_html( $help ) . '</p>';
		if ( '' !== $problem ) {
			echo '<p class="ziplogger-error"><strong>' . esc_html( $problem ) . '</strong></p>';
		}
		echo '</td></tr>';
	}
}
