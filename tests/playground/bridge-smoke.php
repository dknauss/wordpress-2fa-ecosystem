<?php
/**
 * Real plugin-backed smoke checks for the example bridge files.
 *
 * Runs inside WordPress Playground after AIOS and WP 2FA are installed
 * and activated from WordPress.org.
 */

defined( 'ABSPATH' ) || exit;

require_once '/workspace/bridges/wp2fa-bridge.php';
require_once '/workspace/bridges/aios-bridge.php';

/**
 * Record a failure when a condition is not met.
 *
 * @param bool   $condition Whether the assertion passed.
 * @param string $message   Failure message.
 * @param array  $failures  Collected failures.
 * @return void
 */
function wordpress_2fa_ecosystem_smoke_assert( bool $condition, string $message, array &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

$user_id = wp_insert_user(
	array(
		'user_login' => 'bridge-smoke-user',
		'user_pass'  => 'password',
		'user_email' => 'bridge-smoke@example.com',
		'role'       => 'administrator',
	)
);

if ( is_wp_error( $user_id ) ) {
	echo wp_json_encode(
		array(
			'success'  => false,
			'failures' => array( $user_id->get_error_message() ),
		),
		JSON_PRETTY_PRINT
	) . PHP_EOL;
	exit( 1 );
}

$user     = get_userdata( $user_id );
$failures = array();

// AIOS runtime gating: stale user meta should not require 2FA if the runtime object is missing.
update_user_meta( $user_id, 'tfa_enable_tfa', 1 );

global $simba_two_factor_authentication;

$simba_runtime = $simba_two_factor_authentication ?? null;

$simba_two_factor_authentication = null;
$inactive_requires               = apply_filters( 'wp_sudo_requires_two_factor', false, $user_id );
wordpress_2fa_ecosystem_smoke_assert(
	false === $inactive_requires,
	'AIOS bridge should no-op when the Simba runtime object is unavailable.',
	$failures
);

$simba_two_factor_authentication = $simba_runtime;
$active_requires                 = apply_filters( 'wp_sudo_requires_two_factor', false, $user_id );
wordpress_2fa_ecosystem_smoke_assert(
	true === $active_requires,
	'AIOS bridge should require 2FA when the Simba runtime is available and user meta is enabled.',
	$failures
);

// AIOS POST-state containment: an existing two_factor_code should be restored.
$_POST['two_factor_code'] = 'original-two-factor-code';
$_POST['aios_2fa_code']   = '654321';
apply_filters( 'wp_sudo_validate_two_factor', false, $user );

wordpress_2fa_ecosystem_smoke_assert(
	'original-two-factor-code' === $_POST['two_factor_code'],
	'AIOS bridge should restore an existing two_factor_code value after validation.',
	$failures
);

unset( $_POST['two_factor_code'], $_POST['aios_2fa_code'] );
$_POST['aios_2fa_code'] = '654321';
apply_filters( 'wp_sudo_validate_two_factor', false, $user );

wordpress_2fa_ecosystem_smoke_assert(
	! array_key_exists( 'two_factor_code', $_POST ),
	'AIOS bridge should remove temporary two_factor_code state when no original value existed.',
	$failures
);

unset( $_POST['aios_2fa_code'] );

// WP 2FA email flow: rendering twice should not regenerate the token.
\WP2FA\Admin\Helpers\User_Helper::set_enabled_method_for_user( 'email', $user );

ob_start();
do_action( 'wp_sudo_render_two_factor_fields', $user );
ob_end_clean();

$first_token_hash = \WP2FA\Admin\Helpers\User_Helper::get_email_token_for_user( $user );
$transient_key    = wordpress_2fa_ecosystem_wp2fa_email_token_key( $user );

ob_start();
do_action( 'wp_sudo_render_two_factor_fields', $user );
ob_end_clean();

$second_token_hash = \WP2FA\Admin\Helpers\User_Helper::get_email_token_for_user( $user );

wordpress_2fa_ecosystem_smoke_assert(
	! empty( $first_token_hash ),
	'WP 2FA bridge should generate an email token on first render.',
	$failures
);

wordpress_2fa_ecosystem_smoke_assert(
	$first_token_hash === $second_token_hash,
	'WP 2FA bridge should debounce email token generation across rerenders.',
	$failures
);

wordpress_2fa_ecosystem_smoke_assert(
	false !== get_transient( $transient_key ),
	'WP 2FA bridge should store the debounce marker after render.',
	$failures
);

// Validation should clear the debounce marker so the next challenge can send a fresh token.
$email_token               = \WP2FA\Authenticator\Authentication::generate_token( $user_id );
$_POST['wp2fa_authcode']   = $email_token;
$validation_result         = apply_filters( 'wp_sudo_validate_two_factor', false, $user );
$post_validation_transient = get_transient( $transient_key );

wordpress_2fa_ecosystem_smoke_assert(
	true === $validation_result,
	'WP 2FA bridge should validate a real email token.',
	$failures
);

wordpress_2fa_ecosystem_smoke_assert(
	false === $post_validation_transient,
	'WP 2FA bridge should clear the debounce marker after successful validation.',
	$failures
);

unset( $_POST['wp2fa_authcode'] );

$result = array(
	'success'  => 0 === count( $failures ),
	'failures' => $failures,
);

echo wp_json_encode( $result, JSON_PRETTY_PRINT ) . PHP_EOL;

exit( $result['success'] ? 0 : 1 );
