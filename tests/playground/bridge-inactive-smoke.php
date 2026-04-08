<?php
/**
 * Smoke checks for the example bridges when their backing plugins are inactive.
 *
 * This runs before any vendor plugins are installed in Playground.
 */

defined( 'ABSPATH' ) || exit;

require_once '/workspace/bridges/wp2fa-bridge.php';
require_once '/workspace/bridges/wordfence-bridge.php';
require_once '/workspace/bridges/aios-bridge.php';

/**
 * Record a failure when a condition is not met.
 *
 * @param bool   $condition Whether the assertion passed.
 * @param string $message   Failure message.
 * @param array  $failures  Collected failures.
 * @return void
 */
function wordpress_2fa_ecosystem_inactive_assert( bool $condition, string $message, array &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

$user_id = wp_insert_user(
	array(
		'user_login' => 'bridge-inactive-user',
		'user_pass'  => 'password',
		'user_email' => 'bridge-inactive@example.com',
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

update_user_meta( $user_id, 'tfa_enable_tfa', 1 );

$requires = apply_filters( 'wp_sudo_requires_two_factor', false, $user_id );
wordpress_2fa_ecosystem_inactive_assert(
	false === $requires,
	'All bridge examples should silently no-op when their backing plugin runtime is inactive.',
	$failures
);

ob_start();
do_action( 'wp_sudo_render_two_factor_fields', $user );
$rendered = trim( ob_get_clean() );
wordpress_2fa_ecosystem_inactive_assert(
	'' === $rendered,
	'Inactive bridge examples should not render challenge fields.',
	$failures
);

$_POST['wp2fa_authcode']   = '123456';
$_POST['wf_2fa_code']      = '123456';
$_POST['aios_2fa_code']    = '123456';
$_POST['two_factor_code']  = 'untouched';
$validation_result         = apply_filters( 'wp_sudo_validate_two_factor', false, $user );

wordpress_2fa_ecosystem_inactive_assert(
	false === $validation_result,
	'Inactive bridge examples should not validate a challenge.',
	$failures
);

wordpress_2fa_ecosystem_inactive_assert(
	'untouched' === $_POST['two_factor_code'],
	'Inactive bridge examples should not mutate unrelated POST state.',
	$failures
);

unset(
	$_POST['wp2fa_authcode'],
	$_POST['wf_2fa_code'],
	$_POST['aios_2fa_code'],
	$_POST['two_factor_code']
);

$result = array(
	'success'  => 0 === count( $failures ),
	'failures' => $failures,
);

echo wp_json_encode( $result, JSON_PRETTY_PRINT ) . PHP_EOL;

exit( $result['success'] ? 0 : 1 );
