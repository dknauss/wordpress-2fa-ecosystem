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
require_once '/workspace/bridges/wordfence-bridge.php';

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

// Backup-code fallback should validate and consume the code.
$backup_code       = 'bridge-backup-code';
$backup_code_hash  = wp_hash_password( $backup_code );
update_user_meta( $user_id, 'wp_2fa_backup_codes', array( $backup_code_hash ) );

$_POST['wp2fa_backup_code'] = $backup_code;
$backup_validation_result   = apply_filters( 'wp_sudo_validate_two_factor', false, $user );
$remaining_backup_codes     = get_user_meta( $user_id, 'wp_2fa_backup_codes', true );

wordpress_2fa_ecosystem_smoke_assert(
	true === $backup_validation_result,
	'WP 2FA bridge should validate a real backup code.',
	$failures
);

wordpress_2fa_ecosystem_smoke_assert(
	false === get_transient( $transient_key ),
	'WP 2FA bridge should clear the debounce marker after backup-code validation.',
	$failures
);

wordpress_2fa_ecosystem_smoke_assert(
	empty( $remaining_backup_codes ),
	'WP 2FA bridge should consume a backup code after successful validation.',
	$failures
);

unset( $_POST['wp2fa_backup_code'] );

// Validation should also clear the debounce marker for the primary email path.
ob_start();
do_action( 'wp_sudo_render_two_factor_fields', $user );
ob_end_clean();

$refreshed_token_hash = \WP2FA\Admin\Helpers\User_Helper::get_email_token_for_user( $user );
$email_token          = \WP2FA\Authenticator\Authentication::generate_token( $user_id );
$_POST['wp2fa_authcode'] = $email_token;
$validation_result       = apply_filters( 'wp_sudo_validate_two_factor', false, $user );
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

wordpress_2fa_ecosystem_smoke_assert(
	$refreshed_token_hash !== $second_token_hash,
	'WP 2FA bridge should generate a fresh email token after a successful fallback challenge.',
	$failures
);

unset( $_POST['wp2fa_authcode'] );

// Wordfence bridge: detect/render/validate using a real activated recovery-code setup.
$wordfence_user_id = wp_insert_user(
	array(
		'user_login' => 'wordfence-bridge-user',
		'user_pass'  => 'password',
		'user_email' => 'wordfence-bridge@example.com',
		'role'       => 'administrator',
	)
);

if ( is_wp_error( $wordfence_user_id ) ) {
	$failures[] = $wordfence_user_id->get_error_message();
} else {
	$wordfence_user = get_userdata( $wordfence_user_id );

	$wordfence_requires_before = apply_filters( 'wp_sudo_requires_two_factor', false, $wordfence_user_id );
	wordpress_2fa_ecosystem_smoke_assert(
		false === $wordfence_requires_before,
		'Wordfence bridge should not require 2FA before Wordfence 2FA is activated for the user.',
		$failures
	);

	ob_start();
	do_action( 'wp_sudo_render_two_factor_fields', $wordfence_user );
	$wordfence_render_before = trim( ob_get_clean() );
	wordpress_2fa_ecosystem_smoke_assert(
		'' === $wordfence_render_before,
		'Wordfence bridge should not render fields for an unconfigured user.',
		$failures
	);

	$wordfence_secret        = '1234567890abcdef1234567890abcdef';
	$wordfence_recovery_code = '1234567890abcdef';

	\WordfenceLS\Controller_TOTP::shared()->activate_2fa(
		$wordfence_user,
		$wordfence_secret,
		array( $wordfence_recovery_code )
	);

	$wordfence_requires_after = apply_filters( 'wp_sudo_requires_two_factor', false, $wordfence_user_id );
	wordpress_2fa_ecosystem_smoke_assert(
		true === $wordfence_requires_after,
		'Wordfence bridge should require 2FA after Wordfence activates 2FA for the user.',
		$failures
	);

	ob_start();
	do_action( 'wp_sudo_render_two_factor_fields', $wordfence_user );
	$wordfence_render_after = ob_get_clean();
	wordpress_2fa_ecosystem_smoke_assert(
		false !== strpos( $wordfence_render_after, 'wf_2fa_code' ),
		'Wordfence bridge should render its challenge field for a configured user.',
		$failures
	);

	$_POST['wf_2fa_code'] = '1234 5678 90ab cdef';
	$wordfence_validation = apply_filters( 'wp_sudo_validate_two_factor', false, $wordfence_user );
	wordpress_2fa_ecosystem_smoke_assert(
		true === $wordfence_validation,
		'Wordfence bridge should validate a real recovery code through the Wordfence controller.',
		$failures
	);

	$_POST['wf_2fa_code'] = '1234 5678 90ab cdef';
	$wordfence_reuse = apply_filters( 'wp_sudo_validate_two_factor', false, $wordfence_user );
	wordpress_2fa_ecosystem_smoke_assert(
		false === $wordfence_reuse,
		'Wordfence bridge should not allow a consumed recovery code to be reused.',
		$failures
	);

	unset( $_POST['wf_2fa_code'] );
}

$result = array(
	'success'  => 0 === count( $failures ),
	'failures' => $failures,
);

echo wp_json_encode( $result, JSON_PRETTY_PRINT ) . PHP_EOL;

exit( $result['success'] ? 0 : 1 );
