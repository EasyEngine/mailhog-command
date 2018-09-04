<?php

if ( ! class_exists( 'EE' ) ) {
	return;
}

$autoload = dirname( __FILE__ ) . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

EE::add_command( 'mailhog', 'Mailhog_Command' );

EE::add_hook('before_invoke:mailhog up', 'init_auth' );

/**
 * Initialize global auth if it's not present.
 *
 * @throws \EE\ExitException
 */
function init_global_auth() {
	if ( ! is_array( EE::get_runner()->find_command_to_run( [ 'auth' ] ) ) ) {
		EE::error( 'Auth command needs to be registered for mailhog' );
	}

	EE::run_command( [ 'auth', 'init' ] );
}
