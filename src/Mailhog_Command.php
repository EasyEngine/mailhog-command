<?php


use EE\Model\Site;
use function EE\Site\Utils\auto_site_name;

/**
 * Manages mailhog on a site.
 *
 * @package ee-cli
 */
class Mailhog_Command extends EE_Command {

	/**
	 * @var array $db Object containing essential site related information.
	 */
	private $site_data;

	/**
	 * Enables mailhog on given site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to enable mailhog on.
	 *
	 * [--force]
	 * : Force enabling of mailhog for a site.
	 *
	 * ## EXAMPLES
	 *
	 *     # Enable mailhog for site
	 *     $ ee mailhog enable example.com
	 *
	 */
	public function enable( $args, $assoc_args ) {

		\EE\Auth\Utils\init_global_admin_tools_auth();

		EE\Utils\delem_log( 'mailhog' . __FUNCTION__ . ' start' );
		$args            = auto_site_name( $args, 'mailhog', __FUNCTION__ );
		$force           = EE\Utils\get_flag_value( $assoc_args, 'force' );
		$this->site_data = Site::find( EE\Utils\remove_trailing_slash( $args[0] ) );
		if ( ! $this->site_data || ! $this->site_data->site_enabled ) {
			EE::error( sprintf( 'Site %s does not exist / is not enabled.', $args[0] ) );
		}

		if ( ! $force && $this->mailhog_enabled() ) {
			EE::error( 'Mailhog is already up.' );
		}
		EE::docker()::docker_compose_up( $this->site_data->site_fs_path, [ 'mailhog' ] );
		EE::exec( "docker-compose exec postfix postconf -e 'relayhost = mailhog:1025'" );
		EE::exec( 'docker-compose restart postfix' );

		$this->site_data->mailhog_enabled = 1;
		$this->site_data->save();

		EE::success( sprintf( 'Mailhog enabled for %s site', $this->site_data->site_url ) );
	}

	/**
	 * Disables mailhog on given site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to disable mailhog on.
	 *
	 * ## EXAMPLES
	 *
	 *     # Disable mailhog for site
	 *     $ ee mailhog disable example.com
	 *
	 */
	public function disable( $args, $assoc_args ) {

		EE\Utils\delem_log( 'mailhog' . __FUNCTION__ . ' start' );
		$args            = auto_site_name( $args, 'mailhog', __FUNCTION__ );
		$this->site_data = Site::find( EE\Utils\remove_trailing_slash( $args[0] ) );
		if ( ! $this->site_data || ! $this->site_data->site_enabled ) {
			EE::error( sprintf( 'Site %s does not exist / is not enabled.', $args[0] ) );
		}

		if ( ! $this->mailhog_enabled() ) {
			EE::error( 'Mailhog is already down.' );
		}
		EE::exec( 'docker-compose stop mailhog' );
		EE::exec( 'docker-compose exec postfix postconf -e \'relayhost =\'' );
		EE::exec( 'docker-compose restart postfix' );

		$this->site_data->mailhog_enabled = 0;
		$this->site_data->save();

		EE::success( sprintf( 'Mailhog disabled for %s site', $this->site_data->site_url ) );
	}

	/**
	 * Outputs status of mailhog for a site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to know mailhog status for.
	 *
	 * ## EXAMPLES
	 *
	 *     # Check mailhog status on site
	 *     $ ee mailhog status example.com
	 *
	 */
	public function status( $args, $assoc_args ) {

		EE\Utils\delem_log( 'mailhog' . __FUNCTION__ . ' start' );
		$args            = auto_site_name( $args, 'mailhog', __FUNCTION__ );
		$this->site_data = Site::find( EE\Utils\remove_trailing_slash( $args[0] ) );
		if ( ! $this->site_data || ! $this->site_data->site_enabled ) {
			EE::error( sprintf( 'Site %s does not exist / is not enabled.', $args[0] ) );
		}

		if ( $this->mailhog_enabled() ) {
			EE::log( sprintf( 'Mailhog is UP for %s site.', $this->site_data->site_url ) );
		} else {
			EE::log( sprintf( 'Mailhog is DOWN for %s site.', $this->site_data->site_url ) );
		}
	}

	/**
	 * Function to check if mailhog is present in docker-compose.yml or not.
	 */
	private function check_mailhog_available() {

		EE::debug( 'Site type: ' . $this->site_data->site_type );
		EE::debug( 'Site command: ' . $this->site_data->app_sub_type );
		if ( EE::docker()::service_exists( 'mailhog', $this->site_data->site_fs_path ) ) {
			return;
		}
		EE::error( sprintf( '%s site does not have support to enable/disable mailhog.', $this->site_data->site_url ) );
	}

	/**
	 * Function to check if mailhog is possible for the site-type and is enabled or not.
	 *
	 * @return bool Status of mailhog enabled or not.
	 */
	private function mailhog_enabled() {

		$this->check_mailhog_available();
		$launch = EE::launch( 'docker-compose ps -q mailhog' );
		$id     = trim( $launch->stdout );
		if ( empty( $id ) ) {
			return false;
		}

		return 'running' === EE::docker()::container_status( $id );
	}

}
