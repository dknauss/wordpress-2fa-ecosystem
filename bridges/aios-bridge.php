<?php
/**
 * Example Bridge: All-In-One Security (AIOS) / Simba TFA
 *
 * Connects AIOS's embedded Simba Two Factor Authentication engine to a host
 * plugin's reauthentication challenge via the three-hook pattern.
 *
 * Replace the hook names with your host plugin's actual hook names.
 * As written, these use WP Sudo's hooks as a concrete example.
 *
 * Requirements:
 *   - All-In-One Security 5.0+ (with Simba TFA engine)
 *   - A host plugin that fires the three hooks below
 *
 * @package    WordPress_2FA_Ecosystem
 * @version    1.0.0
 * @license    GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wordpress_2fa_ecosystem_aios_is_available' ) ) {
	/**
	 * Check whether the AIOS Simba runtime is available for bridging.
	 *
	 * @return bool
	 */
	function wordpress_2fa_ecosystem_aios_is_available(): bool {
		global $simba_two_factor_authentication;

		return class_exists( 'Simba_Two_Factor_Authentication_1' )
			&& $simba_two_factor_authentication
			&& method_exists( $simba_two_factor_authentication, 'authorise_user_from_login' );
	}
}

/**
 * 1. DETECTION — Does this user have 2FA configured in AIOS?
 *
 * AIOS stores the enabled flag in user meta.
 */
add_filter(
	'wp_sudo_requires_two_factor',
	static function ( bool $needs, int $user_id ): bool {
		if ( $needs ) {
			return true;
		}

		if ( ! wordpress_2fa_ecosystem_aios_is_available() ) {
			return $needs;
		}

		if ( ! get_user_meta( $user_id, 'tfa_enable_tfa', true ) ) {
			return $needs;
		}

		return true;
	},
	10,
	2
);

/**
 * 2. RENDERING — Show a TOTP code input.
 *
 * AIOS uses TOTP (or HOTP), so a standard 6-digit input works.
 */
add_action(
	'wp_sudo_render_two_factor_fields',
	static function ( \WP_User $user ): void {
		if ( ! wordpress_2fa_ecosystem_aios_is_available() ) {
			return;
		}

		if ( ! get_user_meta( $user->ID, 'tfa_enable_tfa', true ) ) {
			return;
		}

		?>
		<p>
			<label for="aios-2fa-code">
				<?php esc_html_e( 'Enter the code from your authenticator app:', 'aios-bridge' ); ?>
			</label><br />
			<input type="text"
				id="aios-2fa-code"
				name="aios_2fa_code"
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
 * 3. VALIDATION — Verify the submitted TOTP code via Simba TFA.
 *
 * IMPORTANT: The Simba TFA engine reads from $_POST['two_factor_code'] internally.
 * We copy the submitted value into that key before calling authorise_user_from_login().
 */
add_filter(
	'wp_sudo_validate_two_factor',
	static function ( bool $valid, \WP_User $user ): bool {
		if ( $valid ) {
			return true;
		}

		if ( ! wordpress_2fa_ecosystem_aios_is_available() ) {
			return $valid;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Host plugin handles nonce.
		$code = isset( $_POST['aios_2fa_code'] )
			? sanitize_text_field( wp_unslash( $_POST['aios_2fa_code'] ) )
			: '';

		if ( empty( $code ) ) {
			return false;
		}

		global $simba_two_factor_authentication;

		$params = array(
			'log'    => $user->user_login,
			'caller' => 'external-bridge',
		);

		$had_existing_code = array_key_exists( 'two_factor_code', $_POST );
		$existing_code     = $had_existing_code ? $_POST['two_factor_code'] : null;

		// Simba TFA reads from $_POST['two_factor_code'] internally.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$_POST['two_factor_code'] = $code;

		try {
			return (bool) $simba_two_factor_authentication->authorise_user_from_login( $params );
		} finally {
			if ( $had_existing_code ) {
				$_POST['two_factor_code'] = $existing_code;
			} else {
				unset( $_POST['two_factor_code'] );
			}
		}
	},
	10,
	2
);
