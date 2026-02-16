<?php
/**
 * Example Bridge: Wordfence Login Security
 *
 * Connects Wordfence's TOTP-based 2FA to a host plugin's reauthentication
 * challenge via the three-hook pattern (detect, render, validate).
 *
 * Replace the hook names with your host plugin's actual hook names.
 * As written, these use WP Sudo's hooks as a concrete example.
 *
 * Requirements:
 *   - Wordfence 7.0+ or Wordfence Login Security 1.0+
 *   - A host plugin that fires the three hooks below
 *
 * @package    WordPress_2FA_Ecosystem
 * @version    1.0.0
 * @license    GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * 1. DETECTION — Does this user have 2FA configured in Wordfence?
 *
 * Wordfence's has_2fa_active() expects a WP_User object, not a user ID.
 */
add_filter(
	'wp_sudo_requires_two_factor',
	static function ( bool $needs, int $user_id ): bool {
		if ( $needs ) {
			return true;
		}

		if ( ! class_exists( '\WordfenceLS\Controller_Users' ) ) {
			return $needs;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return $needs;
		}

		return \WordfenceLS\Controller_Users::shared()->has_2fa_active( $user );
	},
	10,
	2
);

/**
 * 2. RENDERING — Show a TOTP code input.
 *
 * Wordfence only supports TOTP, so this is a straightforward 6-digit input.
 */
add_action(
	'wp_sudo_render_two_factor_fields',
	static function ( \WP_User $user ): void {
		if ( ! class_exists( '\WordfenceLS\Controller_Users' ) ) {
			return;
		}

		if ( ! \WordfenceLS\Controller_Users::shared()->has_2fa_active( $user ) ) {
			return;
		}

		?>
		<p>
			<label for="wf-2fa-code">
				<?php esc_html_e( 'Enter the code from your authenticator app:', 'wordfence-bridge' ); ?>
			</label><br />
			<input type="text"
				id="wf-2fa-code"
				name="wf_2fa_code"
				class="regular-text"
				autocomplete="one-time-code"
				inputmode="numeric"
				pattern="[0-9]*"
				maxlength="6"
				required />
		</p>
		<?php
	}
);

/**
 * 3. VALIDATION — Verify the submitted TOTP code.
 */
add_filter(
	'wp_sudo_validate_two_factor',
	static function ( bool $valid, \WP_User $user ): bool {
		if ( $valid ) {
			return true;
		}

		if ( ! class_exists( '\WordfenceLS\Controller_TOTP' ) ) {
			return $valid;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Host plugin handles nonce.
		$code = isset( $_POST['wf_2fa_code'] )
			? sanitize_text_field( wp_unslash( $_POST['wf_2fa_code'] ) )
			: '';

		if ( empty( $code ) ) {
			return false;
		}

		return \WordfenceLS\Controller_TOTP::shared()->validate_2fa( $user, $code );
	},
	10,
	2
);
