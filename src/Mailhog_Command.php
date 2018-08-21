<?php

/**
 * Enables/Disables admin-tools on a site.
 *
 * ## EXAMPLES
 *
 *     # Enable admin tools on site
 *     $ ee admin-tools up example.com
 *
 * @package ee-cli
 */

use \Symfony\Component\Filesystem\Filesystem;

class Mailhog_Command extends EE_Command {

	/**
	 * @var array $db Object containing essential site related information.
	 */
	private $db;

	/**
	 * Enables mailhog on given site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to enable mailhog on.
	 */
	public function up( $args, $assoc_args ) {

		EE\Utils\delem_log( 'mailhog' . __FUNCTION__ . ' start' );
		$args = EE\SiteUtils\auto_site_name( $args, 'mailhog', __FUNCTION__ );
		$this->db = Site::find( EE\Utils\remove_trailing_slash( $args[0] ) );
		if ( ! $this->db || ! $this->db->site_enabled ) {
			EE::error( sprintf( 'Site %s does not exist / is not enabled.', $args[0] ) );
		}

		// TODO: mailhog_enabled fnuction after db changes
		// if($this->mailhog_enabled()){
		// 	EE::error('Mailhog is already enabled.');
		// }

		chdir( $this->db->site_fs_path );
		$this->check_mailhog_available();
		EE::docker()::docker_compose_up( $this->db->site_fs_path, [ 'mailhog' ] );
		EE::exec( "docker-compose exec postfix postconf -e 'relayhost = mailhog:1025'" );

	}

	/**
	 * Disables mailhog on given site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to disable mailhog on.
	 */
	public function down( $args, $assoc_args ) {

		EE\Utils\delem_log( 'mailhog' . __FUNCTION__ . ' start' );
		$args = EE\SiteUtils\auto_site_name( $args, 'mailhog', __FUNCTION__ );
		$this->db = Site::find( EE\Utils\remove_trailing_slash( $args[0] ) );
		if ( ! $this->db || ! $this->db->site_enabled ) {
			EE::error( sprintf( 'Site %s does not exist / is not enabled.', $args[0] ) );
		}

		// TODO: mailhog_enabled fnuction after db changes
		// if($this->mailhog_enabled()){
		// 	Then only run this...
		// }

		$this->check_mailhog_available();
		EE::docker()::stop_container( 'mailhog' );
		EE::exec( 'docker-compose exec postfix postconf -e \'relayhost =\'' );

	}

	/**
	 * Function to check if mailhog is present in docker-compose.yml or not.
	 */
	private function check_mailhog_available() {

		chdir( $this->db->site_fs_path );
		$launch   = EE::launch( 'docker-compose config --services' );
		$services = explode( PHP_EOL, trim( $launch->stdout ) );
		if ( ! in_array( 'mailhog', $services, true ) ) {
			EE::debug( 'Site type: ' . $this->db->site_type );
			EE::debug( 'Site command: ' . $this->db->app_sub_type );
			EE::error( sprintf( '%s site does not have support to enable/disable mailhog.' ) );
		}
	}

}
