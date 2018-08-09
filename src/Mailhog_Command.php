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
	 * @var array $site Associative array containing essential site related information.
	 */
	private $site;

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
		$this->populate_site_info( $args );
		chdir( $this->site['root'] );

		// TODO: mailhog_enabled fnuction after db changes
		// if($this->mailhog_enabled()){
		// 	EE::error('Mailhog is already enabled.');
		// }

		chdir( $this->site['root'] );
		$this->check_mailhog_available();
		EE::docker()::docker_compose_up( $this->site['root'], [ 'mailhog' ] );
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
		$this->populate_site_info( $args );

		// TODO: mailhog_enabled fnuction after db changes
		// if($this->mailhog_enabled()){
		// 	Then only run this...
		// }

		chdir( $this->site['root'] );
		$this->check_mailhog_available();
		EE::docker()::stop_container( 'mailhog' );
		EE::exec( 'docker-compose exec postfix postconf -e \'relayhost =\'' );

	}

	/**
	 * Function to check if mailhog is present in docker-compose.yml or not.
	 */
	private function check_mailhog_available() {
		$launch   = EE::launch( 'docker-compose config --services' );
		$services = explode( PHP_EOL, trim( $launch->stdout ) );
		if ( ! in_array( 'mailhog', $services, true ) ) {
			EE::debug( 'Site type: ' . $this->site['type'] );
			EE::debug( 'Site command: ' . $this->site['command'] );
			EE::error( sprintf( '%s site does not have support to enable/disable mailhog.' ) );
		}
	}

	/**
	 * Populate basic site info from db.
	 */
	private function populate_site_info( $args ) {

		$this->site['name'] = EE\Utils\remove_trailing_slash( $args[0] );

		if ( EE::db()::site_in_db( $this->site['name'] ) ) {

			$db_select = EE::db()::select( [], [ 'sitename' => $this->site['name'] ], 'sites', 1 );

			$this->site['type']    = $db_select['site_type'];
			$this->site['root']    = $db_select['site_path'];
			$this->site['command'] = $db_select['site_command'];
		} else {
			EE::error( sprintf( 'Site %s does not exist.', $this->site['name'] ) );
		}
	}

}
