<?php
/**
 * Example Bridge: WP 2FA (Melapress)
 *
 * Connects WP 2FA's TOTP, email, and backup code methods to a host plugin's
 * reauthentication challenge via the three-hook pattern (detect, render, validate).
 *
 * Replace the hook names with your host plugin's actual hook names.
 * As written, these use WP Sudo's hooks as a concrete example.
 *
 * Requirements:
 *   - WP 2FA 3.0+ by Melapress
 *   - A host plugin that fires the three hooks below
 *
 * @package    WordPress_2FA_Ecosystem
 * @version    1.0.0
 * @license    GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * 1. DETECTION — Does this user have 2FA configured in WP 2FA?
 *
 * Hook: wp_sudo_requires_two_factor (filter)
 * Adapt the hook name to your host plugin.
 */
add_filter(
	'wp_sudo_requires_two_factor',
	static function ( bool $needs, int $user_id ): bool {
		if ( $needs ) {
			return true;
		}

		if ( ! class_exists( '\WP2FA\Admin\Helpers\User_Helper' ) ) {
			return $needs;
		}

		return \WP2FA\Admin\Helpers\User_Helper::is_user_using_two_factor( $user_id );
	},
	10,
	2
);

/**
 * 2. RENDERING — Show the appropriate 2FA input on the challenge page.
 *
 * Hook: wp_sudo_render_two_factor_fields (action)
 * Renders TOTP or email code input based on the user's configured method.
 * Always shows backup code fallback when available.
 *
 * Rules:
 *   - No <form> wrapper (already inside one).
 *   - No submit button (host plugin provides one).
 *   - No 'action' or '_wpnonce' hidden fields (host plugin handles these).
 */
add_action(
	'wp_sudo_render_two_factor_fields',
	static function ( \WP_User $user ): void {
		if ( ! class_exists( '\WP2FA\Admin\Helpers\User_Helper' ) ) {
			return;
		}

		$method = \WP2FA\Admin\Helpers\User_Helper::get_enabled_method_for_user( $user );

		if ( empty( $method ) ) {
			return;
		}

		// Primary method input.
		if ( 'totp' === $method ) {
			?>
			<p>
				<label for="wp2fa-code">
					<?php esc_html_e( 'Enter the code from your authenticator app:', 'wp-2fa-bridge' ); ?>
				</label><br />
				<input type="text"
					id="wp2fa-code"
					name="wp2fa_authcode"
					class="regular-text"
					autocomplete="one-time-code"
					inputmode="numeric"
					pattern="[0-9]*"
					maxlength="6"
					required />
			</p>
			<?php
		} elseif ( 'email' === $method ) {
			// Generate and send the email OTP now.
			if ( class_exists( '\WP2FA\Authenticator\Authentication' ) ) {
				\WP2FA\Authenticator\Authentication::generate_token( $user->ID );
			}
			?>
			<p>
				<label for="wp2fa-code">
					<?php esc_html_e( 'Enter the code sent to your email:', 'wp-2fa-bridge' ); ?>
				</label><br />
				<input type="text"
					id="wp2fa-code"
					name="wp2fa_authcode"
					class="regular-text"
					autocomplete="one-time-code"
					inputmode="numeric"
					pattern="[0-9]*"
					maxlength="6"
					required />
			</p>
			<?php
		}

		// Backup code fallback — shown for any primary method.
		if ( class_exists( '\WP2FA\Methods\Backup_Codes' ) ) {
			$has_backup = get_user_meta( $user->ID, 'wp_2fa_backup_codes', true );
			if ( ! empty( $has_backup ) ) {
				?>
				<details>
					<summary>
						<?php esc_html_e( 'Use a backup code instead', 'wp-2fa-bridge' ); ?>
					</summary>
					<p>
						<label for="wp2fa-backup">
							<?php esc_html_e( 'Backup code:', 'wp-2fa-bridge' ); ?>
						</label><br />
						<input type="text"
							id="wp2fa-backup"
							name="wp2fa_backup_code"
							class="regular-text"
							autocomplete="off" />
					</p>
				</details>
				<?php
			}
		}
	}
);

/**
 * 3. VALIDATION — Verify the submitted 2FA code.
 *
 * Hook: wp_sudo_validate_two_factor (filter)
 * Tries the primary method first, then falls back to backup codes.
 * The host plugin has already verified the nonce.
 */
add_filter(
	'wp_sudo_validate_two_factor',
	static function ( bool $valid, \WP_User $user ): bool {
		if ( $valid ) {
			return true;
		}

		if ( ! class_exists( '\WP2FA\Admin\Helpers\User_Helper' ) ) {
			return $valid;
		}

		$method = \WP2FA\Admin\Helpers\User_Helper::get_enabled_method_for_user( $user );

		if ( empty( $method ) ) {
			return $valid;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Host plugin handles nonce.
		$code = isset( $_POST['wp2fa_authcode'] )
			? sanitize_text_field( wp_unslash( $_POST['wp2fa_authcode'] ) )
			: '';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$backup_code = isset( $_POST['wp2fa_backup_code'] )
			? sanitize_text_field( wp_unslash( $_POST['wp2fa_backup_code'] ) )
			: '';

		// Try primary method.
		if ( ! empty( $code ) ) {
			if ( 'totp' === $method && class_exists( '\WP2FA\Authenticator\Authentication' ) && class_exists( '\WP2FA\Methods\TOTP' ) ) {
				$key = \WP2FA\Methods\TOTP::get_totp_key( $user );
				if ( $key && \WP2FA\Authenticator\Authentication::is_valid_authcode( $key, $code ) ) {
					return true;
				}
			} elseif ( 'email' === $method && class_exists( '\WP2FA\Authenticator\Authentication' ) ) {
				if ( \WP2FA\Authenticator\Authentication::validate_token( $user, $code ) ) {
					return true;
				}
			}
		}

		// Try backup code fallback.
		if ( ! empty( $backup_code ) && class_exists( '\WP2FA\Methods\Backup_Codes' ) ) {
			if ( \WP2FA\Methods\Backup_Codes::validate_code( $user, $backup_code ) ) {
				return true;
			}
		}

		return false;
	},
	10,
	2
);
