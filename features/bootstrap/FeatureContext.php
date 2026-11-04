<?php
/**
 * Behat tests.
 *
 * @package ee-cli
 */

/* Start: Loading required files to enable EE::launch() in tests. */
define( 'EE_ROOT', __DIR__ . '/../..' );

include_once( EE_ROOT . '/php/class-ee.php' );
include_once( EE_ROOT . '/php/EE/Runner.php' );
include_once( EE_ROOT . '/php/utils.php' );

define( 'EE', true );
define( 'EE_VERSION', trim( file_get_contents( EE_ROOT . '/VERSION' ) ) );

$root_alias = '/opt';
if ( 'Darwin' === php_uname( 's' ) ) {
	$sys_name   = posix_getpwuid(posix_geteuid())['name'];
	if ( ! empty( $sys_name ) ) {
		$root_alias = '/Users/' . $sys_name;
	}
}
// php/init-ee.php may already have defined it (PHP 8 warns on redefinition).
if ( ! defined( 'EE_ROOT_DIR' ) ) {
	define( 'EE_ROOT_DIR', $root_alias . '/easyengine' );
}

require_once EE_ROOT . '/php/bootstrap.php';

if ( ! class_exists( 'EE\Runner' ) ) {
	require_once EE_ROOT . '/php/EE/Runner.php';
}

if ( ! class_exists( 'EE\Configurator' ) ) {
	require_once EE_ROOT . '/php/EE/Configurator.php';
}

$logger_dir = EE_ROOT . '/php/EE/Loggers';
$iterator   = new \DirectoryIterator( $logger_dir );

// Make sure the base class is declared first.
include_once "$logger_dir/Base.php";

foreach ( $iterator as $filename ) {
	if ( '.php' !== pathinfo( $filename, PATHINFO_EXTENSION ) ) {
		continue;
	}

	include_once "$logger_dir/$filename";
}
$runner = \EE::get_runner();
$runner->init_logger();
$runner->init_config();
/* End. Loading required files to enable EE::launch() in tests. */

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterFeatureScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeFeatureScope;

use Behat\Gherkin\Node\PyStringNode,
	Behat\Gherkin\Node\TableNode;

define( 'EE_SITE_ROOT', EE_ROOT_DIR . '/sites' );

class FeatureContext implements Context
{
	public $command;
	public $commands = [];
	public $webroot_path;
	public $ee_path;

	/**
	 * Only sites whose name starts with this prefix are ever deleted by the hooks; the features use it in every site name.
	 */
	const SITE_PREFIX = 'bh-mailhog-';

	/** @var bool Whether "ee phar is generated" ran, so the cleanup may remove ee.phar and ee-old.phar. */
	private static $pharGenerated = false;

	/**
	 * Working directory for `I run` steps (null: the core root).
	 *
	 * @var string|null
	 */
	public $cwd = null;

	/**
	 * Last HTTP response: [ 'code' => int, 'headers' => string, 'body' => string, 'url' => string ].
	 *
	 * @var array|null
	 */
	public $response = null;

	/**
	 * IDs of containers stopped by the "I stop ... directly" step in the current scenario.
	 *
	 * @var string[]
	 */
	public $stoppedContainers = [];

	/**
	 * Initializes context.
	 */
	public function __construct()
	{
		$this->commands = [];
		$this->ee_path = getcwd();
	}

	/**
	 * @Given ee phar is generated
	 */
	public function eePharIsPresent()
	{
		self::$pharGenerated = true;
		// Checks if phar already exists, replaces it
		if(file_exists('ee-old.phar')) {
			// Below exec call is required as currenly `ee cli update` is ran with root
			// which updates ee.phar with root privileges.
			exec("sudo rm ee.phar");
			copy('ee-old.phar','ee.phar');
			return 0;
		}
		exec("php -dphar.readonly=0 utils/make-phar.php ee.phar", $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception("Unable to generate phar" . $return_status);
		}

		// Cache generaed phar as it is expensive to generate one
		copy('ee.phar','ee-old.phar');
	}

	/**
	 * @Given :command is installed
	 */
	public function isInstalled($command)
	{
		exec("type " . $command, $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception($command . " is not installed! Exit code is:" . $return_status);
		}
	}

	/**
	 * Anchored: the upstream `/I run '(.*)'|"(.*)"/` matched every step containing double quotes.
	 *
	 * @When /^I run '(.*)'$/
	 * @When /^I run "(.*)"$/
	 */
	public function iRun($command)
	{
		$this->commands[] = $this->launch( $command );
	}

	/**
	 * @Then return value should be 0
	 */
	public function returnValueShouldBe0()
	{
		if ( 0 !== $this->commands[0]->return_code ) {
			throw new Exception("Actual return code is not zero: \n" . $this->command);
		}
	}

	/**
	 * @Then return value of command :index should be 0
	 */
	public function returnValueOfCommandShouldBe0($index)
	{
		if ( 0 !== $this->commands[ $index - 1 ]->return_code ) {
			throw new Exception("Actual return code is not zero: \n" . $this->command);
		}
	}

	/**
	 * @Then After delay of :time seconds
	 */
	public function afterDelayOfSeconds( $time )
	{
		sleep( $time );
	}

	/**
	 * @Then /(STDOUT|STDERR) should return exactly/
	 */
	public function stdoutShouldReturnExactly($output_stream, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[0]->stdout : $this->commands[0]->stderr;

		$command_output = str_replace(["\033[1;31m","\033[0m"],'',$command_output);

		if ( $expected_output->getRaw() !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /(STDOUT|STDERR) of command :index should return exactly/
	 */
	public function stdoutOfCommandShouldReturnExactly($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[ $index - 1 ]->stdout : $this->commands[ $index - 1 ]->stderr;

		$command_output = str_replace(["\033[1;31m","\033[0m"],'',$command_output);

		if ( $expected_output->getRaw() !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /(STDOUT|STDERR) should return something like/
	 */
	public function stdoutShouldReturnSomethingLike($output_stream, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[0]->stdout : $this->commands[0]->stderr;

		if (strpos($command_output, $expected_output->getRaw()) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}


	/**
	 * @Then /(STDOUT|STDERR) of command :index should return something like/
	 */
	public function stdoutOfCommandShouldReturnSomethingLike($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[ $index - 1 ]->stdout : $this->commands[ $index - 1 ]->stderr;

		if (strpos($command_output, $expected_output->getRaw()) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then The site :site should be :type multisite
	 */
	public function theSiteShouldBeMultisite( $site, $type )
	{
		$result = EE::launch( "cd " . EE_SITE_ROOT . "/$site && docker-compose exec --user='www-data' php sh -c 'wp config get SUBDOMAIN_INSTALL'", false, true );

		if( $result->stderr ) {
			throw new Exception("Found error while executing command: $result->stderr");
		}

		if($type === 'subdomain' && trim($result->stdout) !== '1') {
			throw new Exception("Expecting SUBDOMAIN_INSTALL to be 1. Got: $result->stdout");
		}
		else if($type === 'subdir' && trim($result->stdout) !== '') {
			throw new Exception("Expecting SUBDOMAIN_INSTALL to be empty. Got: $result->stdout");
		}
		else if($type !== 'subdomain' && $type !== 'subdir') {
			throw new Exception("Found unknown site type: $type");
		}
	}

	/**
	 * @Then The :site db entry should be removed
	 */
	public function theDbEntryShouldBeRemoved($site)
	{
		$out = shell_exec("sudo bin/ee site list");
		if (strpos($out, $site) !== false) {
			throw new Exception("$site db entry not been removed!");
		}

	}

	/**
	 * @Then The :site webroot should be removed
	 */
	public function theWebrootShouldBeRemoved($site)
	{
		if ( file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been removed!");
		}
	}

	/**
	 * @Then Following containers of site :site should be removed:
	 */
	public function followingContainersOfSiteShouldBeRemoved($site, TableNode $table)
	{
		// Upstream looked for compose v1 names (<site>_<service>_1), which compose v2 never creates, so it always passed.
		foreach ( $table->getHash() as $container ) {
			$service    = $container['container'];
			$containers = self::serviceContainers( $site, $service );
			if ( ! empty( $containers ) ) {
				throw new Exception( "The $service container of $site has not been removed: " . json_encode( $containers ) );
			}
		}
	}

	/**
	 * @Then The site :site should have webroot
	 */
	public function theSiteShouldHaveWebroot($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been created!");
		}
	}

	/**
	 * @Then The site :site should have WordPress
	 */
	public function theSiteShouldHaveWordpress($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site . '/app/wp-config.php' ) ) {
			throw new Exception("WordPress data not found!");
		}
	}

	/**
	 * @Then Request on :site should contain following headers:
	 */
	public function requestOnShouldContainFollowingHeaders($site, TableNode $table)
	{
		$url = 'http://' . $site;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);

		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @Then Request on :host with header :header should contain following headers:
	 */
	public function requestOnWithHeaderShouldContainFollowingHeaders($host, $header, TableNode $table)
	{

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [ $header ]);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);

		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @Then Request on :host with resolve option :resolve should contain following headers:
	 */
	public function requestOnWithResolveOptionShouldContainFollowingHeaders($host, $resolve, TableNode $table)
	{

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RESOLVE, [ $resolve ]);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);
		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @AfterScenario
	 */
	public function cleanupScenario(AfterScenarioScope $scope)
	{
		// A scenario that failed after stopping a container behind ee's back must not leave it down for the next one.
		if ( ! $scope->getTestResult()->isPassed() ) {
			foreach ( $this->stoppedContainers as $id ) {
				EE::launch( 'docker start ' . escapeshellarg( $id ), false, true );
			}
		}
		$this->stoppedContainers = [];
		$this->commands = [];
		$this->cwd      = null;
		$this->response = null;
		chdir($this->ee_path);
	}

	/**
	 * @Then There should be :expected_running_containers containers with labels
	 */
	public function thereShouldBeContainersWithLabel($expected_running_containers, PyStringNode $pyStringNode)
	{
		$filters = '';
		foreach ( array_filter( array_map( 'trim', $pyStringNode->getStrings() ) ) as $label ) {
			$filters .= ' --filter ' . escapeshellarg( 'label=' . $label );
		}
		if ( '' === $filters ) {
			throw new Exception( 'No labels given.' );
		}

		$result = EE::launch( "docker ps -q$filters", false, true );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "docker ps failed.\n" . self::describe( $result ) );
		}
		$running_containers = count( array_filter( explode( "\n", trim( $result->stdout ) ) ) );

		// Upstream compared the string argument with an int using === and threw on a match, so it could never fail.
		if ( (int) $expected_running_containers !== $running_containers ) {
			throw new Exception( "Expected $expected_running_containers running containers. Found: $running_containers\n" . $result->stdout );
		}
	}

	/**
	 * @BeforeFeature
	 */
	public static function cleanupBeforeFeature(BeforeFeatureScope $scope)
	{
		// Leftovers of an aborted run would break "fresh site" scenarios.
		self::deletePrefixedSites();
	}

	/**
	 * @AfterFeature
	 */
	public static function cleanup(AfterFeatureScope $scope)
	{
		self::deletePrefixedSites();

		// Only phars built by "ee phar is generated", never a build of the checkout's owner.
		if ( self::$pharGenerated ) {
			foreach ( [ 'ee.phar', 'ee-old.phar' ] as $phar ) {
				if ( file_exists( $phar ) ) {
					unlink( $phar );
				}
			}
		}
	}

	/**
	 * Deletes only the sites that carry this suite's prefix (never "all sites").
	 */
	public static function deletePrefixedSites()
	{
		$prefix = self::SITE_PREFIX;
		$result = EE::launch( self::eeBin() . ' site list --format=text', false, true );

		foreach ( explode( "\n", $result->stdout ) as $site ) {
			$site = trim( $site );
			if ( '' !== $site && 0 === strpos( $site, $prefix ) ) {
				EE::launch( self::eeBin() . ' site delete ' . escapeshellarg( $site ) . ' --yes', false, true );
			}
		}
	}

	/*
	 * ==========================================================================
	 * Shared generic steps (mailhog-command suite).
	 *
	 * Written to be shareable by the other package suites: they only rely on bin/ee, docker, the sqlite DB and curl.
	 * They act on the LAST command run (unlike the upstream steps above, which read command 1).
	 * ==========================================================================
	 */

	public static function eeBin()
	{
		return escapeshellarg( realpath( EE_ROOT ) . '/bin/ee' );
	}

	/**
	 * Runs a shell command from the core root (or $this->cwd, with a leading `bin/ee` made absolute).
	 */
	private function launch( $command, $cwd = null )
	{
		$cwd = null === $cwd ? $this->cwd : $cwd;
		if ( null !== $cwd ) {
			$command = preg_replace( '#^bin/ee\b#', self::eeBin(), $command );
		}

		return EE::launch( $command, false, true, [], null, $cwd );
	}

	private function lastCommand()
	{
		if ( empty( $this->commands ) ) {
			throw new Exception( 'No command has been run in this scenario.' );
		}

		return end( $this->commands );
	}

	private static function describe( $result )
	{
		return sprintf(
			"Command: %s\nExit code: %s\nSTDOUT:\n%s\nSTDERR:\n%s",
			$result->command,
			$result->return_code,
			$result->stdout,
			$result->stderr
		);
	}

	private static function stripColors( $text )
	{
		return preg_replace( '/\e\[[0-9;]*m/', '', $text );
	}

	private function lastStream( $stream )
	{
		$result = $this->lastCommand();

		return self::stripColors( 'STDOUT' === $stream ? $result->stdout : $result->stderr );
	}

	private static function siteRoot( $site )
	{
		return EE_SITE_ROOT . '/' . $site;
	}

	private static function assertSiteName( $site )
	{
		if ( ! preg_match( '/^[a-z0-9][a-z0-9.\-]*$/', $site ) ) {
			throw new Exception( "Refusing to use unsafe site name: $site" );
		}
	}

	/**
	 * @Given I am in the directory of site :site
	 */
	public function iAmInTheDirectoryOfSite( $site )
	{
		$dir = self::siteRoot( $site );
		if ( ! is_dir( $dir ) ) {
			throw new Exception( "Site directory $dir does not exist." );
		}
		$this->cwd = $dir;
	}

	/**
	 * @Then the command should succeed
	 */
	public function theCommandShouldSucceed()
	{
		$result = $this->lastCommand();
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Expected exit code 0.\n" . self::describe( $result ) );
		}
	}

	/**
	 * @Then the command should fail
	 */
	public function theCommandShouldFail()
	{
		$result = $this->lastCommand();
		if ( 0 === $result->return_code ) {
			throw new Exception( "Expected a non-zero exit code.\n" . self::describe( $result ) );
		}
	}

	/**
	 * @Then the exit code should be :code
	 */
	public function theExitCodeShouldBe( $code )
	{
		$result = $this->lastCommand();
		if ( (int) $code !== $result->return_code ) {
			throw new Exception( "Expected exit code $code.\n" . self::describe( $result ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should contain "([^"]*)"$/
	 */
	public function streamShouldContain( $stream, $text )
	{
		if ( false === strpos( $this->lastStream( $stream ), $text ) ) {
			throw new Exception( "$stream does not contain: $text\n" . self::describe( $this->lastCommand() ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should not contain "([^"]*)"$/
	 */
	public function streamShouldNotContain( $stream, $text )
	{
		if ( false !== strpos( $this->lastStream( $stream ), $text ) ) {
			throw new Exception( "$stream unexpectedly contains: $text\n" . self::describe( $this->lastCommand() ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should contain:$/
	 */
	public function streamShouldContainBlock( $stream, PyStringNode $text )
	{
		$this->streamShouldContain( $stream, $text->getRaw() );
	}

	/**
	 * @Then /^(STDOUT|STDERR) should be "([^"]*)"$/
	 */
	public function streamShouldBe( $stream, $text )
	{
		if ( trim( $this->lastStream( $stream ) ) !== $text ) {
			throw new Exception( "$stream is not exactly: $text\n" . self::describe( $this->lastCommand() ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should be empty$/
	 */
	public function streamShouldBeEmpty( $stream )
	{
		if ( '' !== trim( $this->lastStream( $stream ) ) ) {
			throw new Exception( "$stream is not empty.\n" . self::describe( $this->lastCommand() ) );
		}
	}

	/**
	 * @Then STDOUT should be valid json
	 */
	public function stdoutShouldBeValidJson()
	{
		json_decode( $this->lastStream( 'STDOUT' ) );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new Exception( 'STDOUT is not valid JSON: ' . json_last_error_msg() . "\n" . self::describe( $this->lastCommand() ) );
		}
	}

	/*
	 * --- Sites and the EE database ---
	 */

	private static function dbRow( $site )
	{
		$options = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ];
		if ( defined( 'PDO::SQLITE_ATTR_OPEN_FLAGS' ) ) {
			$options[ PDO::SQLITE_ATTR_OPEN_FLAGS ] = PDO::SQLITE_OPEN_READONLY;
		}
		$pdo  = new PDO( 'sqlite:' . EE_ROOT_DIR . '/db/ee.sqlite', null, null, $options );
		$stmt = $pdo->prepare( 'SELECT * FROM sites WHERE site_url = ?' );
		$stmt->execute( [ $site ] );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		return false === $row ? null : $row;
	}

	/**
	 * Creates the site unless it exists, and re-enables it if a previous scenario left it disabled.
	 *
	 * @Given the site :site exists with flags :flags
	 */
	public function theSiteExistsWithFlags( $site, $flags )
	{
		self::assertSiteName( $site );
		$row = self::dbRow( $site );

		if ( null === $row ) {
			$result = $this->launch( 'bin/ee site create ' . $site . ' ' . $flags, EE_ROOT );
			if ( 0 !== $result->return_code || null === self::dbRow( $site ) ) {
				throw new Exception( "Could not create $site.\n" . self::describe( $result ) );
			}
		} elseif ( ! (int) $row['site_enabled'] ) {
			$result = $this->launch( 'bin/ee site enable ' . $site, EE_ROOT );
			if ( 0 !== $result->return_code ) {
				throw new Exception( "Could not enable $site.\n" . self::describe( $result ) );
			}
		}
	}

	/**
	 * @Given the site :site is disabled
	 */
	public function theSiteIsDisabled( $site )
	{
		self::assertSiteName( $site );
		$row = self::dbRow( $site );
		if ( null === $row ) {
			throw new Exception( "Site $site does not exist." );
		}
		if ( (int) $row['site_enabled'] ) {
			$result = $this->launch( 'bin/ee site disable ' . $site, EE_ROOT );
			if ( 0 !== $result->return_code ) {
				throw new Exception( "Could not disable $site.\n" . self::describe( $result ) );
			}
		}
	}

	/**
	 * @Then the site :site should exist
	 */
	public function theSiteShouldExist( $site )
	{
		if ( null === self::dbRow( $site ) ) {
			throw new Exception( "Site $site has no row in the sites table." );
		}
	}

	/**
	 * @Then the site :site should not exist
	 */
	public function theSiteShouldNotExist( $site )
	{
		if ( null !== self::dbRow( $site ) ) {
			throw new Exception( "Site $site still has a row in the sites table." );
		}
	}

	/**
	 * @Then the site :site should have :column set to :value
	 */
	public function theSiteShouldHaveColumnSetTo( $site, $column, $value )
	{
		$row = self::dbRow( $site );
		if ( null === $row ) {
			throw new Exception( "Site $site has no row in the sites table." );
		}
		if ( ! array_key_exists( $column, $row ) ) {
			throw new Exception( "The sites table has no column $column. Columns: " . implode( ', ', array_keys( $row ) ) );
		}
		if ( (string) $row[ $column ] !== (string) $value ) {
			throw new Exception( sprintf( 'Expected sites.%s of %s to be %s, got %s.', $column, $site, var_export( $value, true ), var_export( $row[ $column ], true ) ) );
		}
	}

	/*
	 * --- Files ---
	 */

	private static function readFileOrFail( $path )
	{
		if ( ! is_file( $path ) ) {
			throw new Exception( "File $path does not exist." );
		}

		return file_get_contents( $path );
	}

	/**
	 * @Then the file :path should exist
	 */
	public function theFileShouldExist( $path )
	{
		self::readFileOrFail( $path );
	}

	/**
	 * @Then the file :path should contain :text
	 */
	public function theFileShouldContain( $path, $text )
	{
		$content = self::readFileOrFail( $path );
		if ( false === strpos( $content, $text ) ) {
			throw new Exception( "$path does not contain: $text\nContent:\n$content" );
		}
	}

	/**
	 * @Then the file :path should not contain :text
	 */
	public function theFileShouldNotContain( $path, $text )
	{
		$content = self::readFileOrFail( $path );
		if ( false !== strpos( $content, $text ) ) {
			throw new Exception( "$path unexpectedly contains: $text\nContent:\n$content" );
		}
	}

	/**
	 * @Then the file :path should match :regex
	 */
	public function theFileShouldMatch( $path, $regex )
	{
		$content = self::readFileOrFail( $path );
		if ( ! preg_match( $regex, $content ) ) {
			throw new Exception( "$path does not match $regex\nContent:\n$content" );
		}
	}

	/**
	 * @Then the file :relative of site :site should match :regex
	 */
	public function theFileOfSiteShouldMatch( $relative, $site, $regex )
	{
		$this->theFileShouldMatch( self::siteRoot( $site ) . '/' . ltrim( $relative, '/' ), $regex );
	}

	/**
	 * @Then the file :relative of site :site should not match :regex
	 */
	public function theFileOfSiteShouldNotMatch( $relative, $site, $regex )
	{
		$path    = self::siteRoot( $site ) . '/' . ltrim( $relative, '/' );
		$content = self::readFileOrFail( $path );
		if ( preg_match( $regex, $content ) ) {
			throw new Exception( "$path unexpectedly matches $regex\nContent:\n$content" );
		}
	}

	/**
	 * @Then the file :relative of site :site should contain :text
	 */
	public function theFileOfSiteShouldContain( $relative, $site, $text )
	{
		$this->theFileShouldContain( self::siteRoot( $site ) . '/' . ltrim( $relative, '/' ), $text );
	}

	/*
	 * --- Docker (containers are found by labels, so compose's naming scheme does not matter) ---
	 */

	/**
	 * @return array List of [ 'id' => string, 'state' => string ].
	 */
	private static function serviceContainers( $site, $service )
	{
		$result = EE::launch(
			sprintf(
				"docker ps -a --filter %s --filter %s --format '{{.ID}} {{.State}}'",
				escapeshellarg( 'label=io.easyengine.site=' . $site ),
				escapeshellarg( 'label=com.docker.compose.service=' . $service )
			),
			false,
			true
		);
		if ( 0 !== $result->return_code ) {
			throw new Exception( "docker ps failed.\n" . self::describe( $result ) );
		}

		$containers = [];
		foreach ( array_filter( explode( "\n", trim( $result->stdout ) ) ) as $line ) {
			list( $id, $state ) = array_pad( explode( ' ', trim( $line ), 2 ), 2, '' );
			$containers[]       = [ 'id' => $id, 'state' => $state ];
		}

		return $containers;
	}

	private static function serviceContainerId( $site, $service )
	{
		$containers = self::serviceContainers( $site, $service );
		if ( empty( $containers ) ) {
			throw new Exception( "No $service container found for site $site." );
		}

		return $containers[0]['id'];
	}

	private static function isServiceRunning( $site, $service )
	{
		foreach ( self::serviceContainers( $site, $service ) as $container ) {
			if ( 'running' === $container['state'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @Then the :service service of site :site should be running
	 */
	public function theServiceOfSiteShouldBeRunning( $service, $site )
	{
		if ( ! self::isServiceRunning( $site, $service ) ) {
			throw new Exception( "The $service container of $site is not running. Containers: " . json_encode( self::serviceContainers( $site, $service ) ) );
		}
	}

	/**
	 * @Then the :service service of site :site should not be running
	 */
	public function theServiceOfSiteShouldNotBeRunning( $service, $site )
	{
		if ( self::isServiceRunning( $site, $service ) ) {
			throw new Exception( "The $service container of $site is running." );
		}
	}

	/**
	 * @Then the :service service of site :site should not exist
	 */
	public function theServiceOfSiteShouldNotExist( $service, $site )
	{
		$containers = self::serviceContainers( $site, $service );
		if ( ! empty( $containers ) ) {
			throw new Exception( "The $service container of $site still exists: " . json_encode( $containers ) );
		}
	}

	/**
	 * @Then the :service service of site :site should have env :env
	 */
	public function theServiceOfSiteShouldHaveEnv( $service, $site, $env )
	{
		$id     = self::serviceContainerId( $site, $service );
		$result = EE::launch( "docker inspect -f '{{json .Config.Env}}' $id", false, true );
		$envs   = (array) json_decode( trim( $result->stdout ), true );
		if ( ! in_array( $env, $envs, true ) ) {
			throw new Exception( "The $service container of $site lacks env $env. Env: " . implode( ', ', $envs ) );
		}
	}

	/**
	 * Order- and duplicate-insensitive check of a comma-separated env value such as VIRTUAL_HOST.
	 *
	 * @Then the :service service of site :site should have env :name listing :values
	 */
	public function theServiceOfSiteShouldHaveEnvListing( $service, $site, $name, $values )
	{
		$id     = self::serviceContainerId( $site, $service );
		$result = EE::launch( "docker inspect -f '{{json .Config.Env}}' $id", false, true );
		$actual = null;
		foreach ( (array) json_decode( trim( $result->stdout ), true ) as $env ) {
			if ( 0 === strpos( $env, $name . '=' ) ) {
				$actual = substr( $env, strlen( $name ) + 1 );
			}
		}
		if ( null === $actual ) {
			throw new Exception( "The $service container of $site has no $name env." );
		}
		$items   = array_map( 'trim', explode( ',', $actual ) );
		$missing = array_diff( array_map( 'trim', explode( ',', $values ) ), $items );
		$extra   = array_diff( $items, array_map( 'trim', explode( ',', $values ) ) );
		if ( ! empty( $missing ) || ! empty( $extra ) ) {
			throw new Exception( "$name of the $service container of $site is '$actual', expected the entries '$values'." );
		}
	}

	/**
	 * @Then the :service service of site :site should have label :label
	 */
	public function theServiceOfSiteShouldHaveLabel( $service, $site, $label )
	{
		$id     = self::serviceContainerId( $site, $service );
		$result = EE::launch( "docker inspect -f '{{json .Config.Labels}}' $id", false, true );
		$labels = (array) json_decode( trim( $result->stdout ), true );
		list( $key, $value ) = array_pad( explode( '=', $label, 2 ), 2, null );
		if ( ! array_key_exists( $key, $labels ) || ( null !== $value && $labels[ $key ] !== $value ) ) {
			throw new Exception( "The $service container of $site lacks label $label. Labels: " . json_encode( $labels ) );
		}
	}

	/**
	 * Changes container state behind ee's back, to simulate drift.
	 *
	 * @When /^I (stop|start|restart) the "([^"]*)" container of site "([^"]*)" directly$/
	 */
	public function iActionTheServiceContainerOfSiteDirectly( $action, $service, $site )
	{
		$id     = self::serviceContainerId( $site, $service );
		$result = EE::launch( "docker $action $id", false, true );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "docker $action failed.\n" . self::describe( $result ) );
		}
		if ( 'stop' === $action ) {
			$this->stoppedContainers[] = $id;
		}
	}

	/**
	 * Runs a command inside a site service (docker compose exec -T, from the site root). Not "I run": that would clash with the `I run "..."` step.
	 *
	 * @When I execute :command in the :service service of site :site
	 */
	public function iExecuteInTheServiceOfSite( $command, $service, $site )
	{
		self::assertSiteName( $site );
		$this->commands[] = EE::launch(
			sprintf( 'docker-compose exec -T %s sh -c %s', escapeshellarg( $service ), escapeshellarg( $command ) ),
			false,
			true,
			[],
			null,
			self::siteRoot( $site )
		);
	}

	/*
	 * --- HTTP (the URL host is resolved with CURLOPT_RESOLVE, so SNI and the Host header both match) ---
	 */

	public static function publicIp()
	{
		static $ip = null;
		if ( null === $ip ) {
			$ip = getenv( 'BEHAT_PUBLIC_IP' );
			if ( ! $ip ) {
				$ip = trim( (string) shell_exec( "hostname -I | awk '{print \$1}'" ) );
			}
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				throw new Exception( "Could not determine the public IP (got '$ip'). Set BEHAT_PUBLIC_IP." );
			}
		}

		return $ip;
	}

	/**
	 * @return array [ 'user' => string, 'pass' => string ]
	 */
	public static function adminToolsCredentials()
	{
		$result = EE::launch( self::eeBin() . ' auth list global --format=json', false, true );
		$auths  = json_decode( trim( $result->stdout ), true );
		if ( ! is_array( $auths ) || empty( $auths ) ) {
			throw new Exception( "Could not read the global admin-tools auth.\n" . self::describe( $result ) );
		}
		// The proxy prefers htpasswd/default_admin_tools over htpasswd/default.
		usort(
			$auths,
			function ( $a, $b ) {
				return ( 'Admin Tools Auth' === $b['site_url'] ) - ( 'Admin Tools Auth' === $a['site_url'] );
			}
		);

		return [ 'user' => $auths[0]['username'], 'pass' => $auths[0]['password'] ];
	}

	private function request( $url, $ip, $credentials = null, $headers = [] )
	{
		$parts = parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			throw new Exception( "Invalid URL: $url" );
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'http';
		$port   = isset( $parts['port'] ) ? $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HEADER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
		if ( null !== $ip ) {
			curl_setopt( $ch, CURLOPT_RESOLVE, [ sprintf( '%s:%d:%s', $parts['host'], $port, $ip ) ] );
		}
		if ( null !== $credentials ) {
			curl_setopt( $ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC );
			curl_setopt( $ch, CURLOPT_USERPWD, $credentials['user'] . ':' . $credentials['pass'] );
		}
		if ( ! empty( $headers ) ) {
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		}

		$raw         = curl_exec( $ch );
		$error       = curl_error( $ch );
		$code        = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$header_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		curl_close( $ch );

		$this->response = [
			'url'     => $url . ( null !== $ip ? " (via $ip)" : '' ),
			'code'    => $code,
			'headers' => false === $raw ? '' : substr( $raw, 0, $header_size ),
			'body'    => false === $raw ? '' : (string) substr( $raw, $header_size ),
			'error'   => $error,
		];

		return $this->response;
	}

	private function describeResponse()
	{
		if ( null === $this->response ) {
			return 'No request has been sent.';
		}

		return sprintf(
			"URL: %s\nCode: %d%s\nHeaders:\n%s\nBody (first 2000 bytes):\n%s",
			$this->response['url'],
			$this->response['code'],
			$this->response['error'] ? "\ncurl error: " . $this->response['error'] : '',
			$this->response['headers'],
			substr( $this->response['body'], 0, 2000 )
		);
	}

	/**
	 * Local requests reach nginx-proxy as 10.1.0.1, which the global auth whitelist lets through.
	 *
	 * @When I send a GET request to :url
	 */
	public function iSendAGetRequestTo( $url )
	{
		$this->request( $url, '127.0.0.1' );
	}

	/**
	 * Requests to the public IP are not whitelisted, so HTTP auth applies.
	 *
	 * @When I send a GET request to :url from the public IP
	 */
	public function iSendAGetRequestToFromThePublicIp( $url )
	{
		$this->request( $url, self::publicIp() );
	}

	/**
	 * @When I send a GET request to :url from the public IP with the admin-tools credentials
	 */
	public function iSendAGetRequestToFromThePublicIpWithTheAdminToolsCredentials( $url )
	{
		$this->request( $url, self::publicIp(), self::adminToolsCredentials() );
	}

	/**
	 * @When I send a GET request to :url from the public IP with credentials :user and :pass
	 */
	public function iSendAGetRequestToFromThePublicIpWithCredentials( $url, $user, $pass )
	{
		$this->request( $url, self::publicIp(), [ 'user' => $user, 'pass' => $pass ] );
	}

	/**
	 * @Then a GET request to :url with Host :host should return :code
	 */
	public function aGetRequestToWithHostShouldReturn( $url, $host, $code )
	{
		$url = str_replace( '{PUBLIC_IP}', self::publicIp(), $url );
		$this->request( $url, null, null, [ 'Host: ' . $host ] );
		$this->theResponseCodeShouldBe( $code );
	}

	/**
	 * @Then the response code should be :code
	 */
	public function theResponseCodeShouldBe( $code )
	{
		if ( null === $this->response || (int) $code !== $this->response['code'] ) {
			throw new Exception( "Expected HTTP $code.\n" . $this->describeResponse() );
		}
	}

	/**
	 * @Then the response body should contain :text
	 */
	public function theResponseBodyShouldContain( $text )
	{
		if ( null === $this->response || false === strpos( $this->response['body'], $text ) ) {
			throw new Exception( "Response body does not contain: $text\n" . $this->describeResponse() );
		}
	}

	/**
	 * @Then the response body should not contain :text
	 */
	public function theResponseBodyShouldNotContain( $text )
	{
		// A failed transfer has an empty body, which must not count as "does not contain".
		if ( null === $this->response || 0 === $this->response['code'] || '' !== $this->response['error'] || false !== strpos( $this->response['body'], $text ) ) {
			throw new Exception( "Response body unexpectedly contains: $text\n" . $this->describeResponse() );
		}
	}

	/**
	 * @Then the response body should be valid json
	 */
	public function theResponseBodyShouldBeValidJson()
	{
		json_decode( null === $this->response ? '' : $this->response['body'] );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new Exception( 'Response body is not valid JSON: ' . json_last_error_msg() . "\n" . $this->describeResponse() );
		}
	}

	/**
	 * @Then the response header :name should contain :text
	 */
	public function theResponseHeaderShouldContain( $name, $text )
	{
		$found = false;
		if ( null !== $this->response ) {
			foreach ( preg_split( '/\r?\n/', $this->response['headers'] ) as $line ) {
				if ( 0 === stripos( $line, $name . ':' ) && false !== strpos( $line, $text ) ) {
					$found = true;
					break;
				}
			}
		}
		if ( ! $found ) {
			throw new Exception( "No $name header containing: $text\n" . $this->describeResponse() );
		}
	}

	/**
	 * Polls instead of sleeping; docker-gen regenerates the proxy config asynchronously.
	 *
	 * @param callable $check Returns true when done.
	 */
	private function waitUntil( $seconds, callable $check, $what )
	{
		$deadline = microtime( true ) + (int) $seconds;
		do {
			if ( $check() ) {
				return;
			}
			usleep( 500000 );
		} while ( microtime( true ) < $deadline );

		throw new Exception( "Timed out after $seconds seconds waiting until $what.\nLast response:\n" . $this->describeResponse() );
	}

	/**
	 * @Then I wait up to :seconds seconds until a GET request to :url returns :code
	 */
	public function iWaitUntilAGetRequestReturns( $seconds, $url, $code )
	{
		$this->waitUntil(
			$seconds,
			function () use ( $url, $code ) {
				return (int) $code === $this->request( $url, '127.0.0.1' )['code'];
			},
			"$url returns $code"
		);
	}

	/**
	 * @Then I wait up to :seconds seconds until a GET request to :url contains :text
	 */
	public function iWaitUntilAGetRequestContains( $seconds, $url, $text )
	{
		$this->waitUntil(
			$seconds,
			function () use ( $url, $text ) {
				return false !== strpos( $this->request( $url, '127.0.0.1' )['body'], $text );
			},
			"$url contains '$text'"
		);
	}

	/**
	 * @Then I wait up to :seconds seconds until a GET request to :url does not contain :text
	 */
	public function iWaitUntilAGetRequestDoesNotContain( $seconds, $url, $text )
	{
		$this->waitUntil(
			$seconds,
			function () use ( $url, $text ) {
				$response = $this->request( $url, '127.0.0.1' );

				return 0 !== $response['code'] && '' === $response['error'] && false === strpos( $response['body'], $text );
			},
			"$url no longer contains '$text'"
		);
	}

	/*
	 * --- Auth (auth-command) ---
	 */

	/**
	 * @Then the global admin-tools auth should exist
	 */
	public function theGlobalAdminToolsAuthShouldExist()
	{
		$credentials = self::adminToolsCredentials();
		if ( '' === (string) $credentials['user'] || '' === (string) $credentials['pass'] ) {
			throw new Exception( 'The global admin-tools auth has an empty username or password.' );
		}
		$dir = EE_ROOT_DIR . '/services/nginx-proxy/htpasswd';
		if ( ! is_file( "$dir/default_admin_tools" ) && ! is_file( "$dir/default" ) ) {
			throw new Exception( "Neither $dir/default_admin_tools nor $dir/default exists." );
		}
	}

	/*
	 * ==========================================================================
	 * mailhog-command specific steps.
	 * ==========================================================================
	 */

	private function mailhogStatus( $site )
	{
		$result = $this->launch( 'bin/ee mailhog status ' . $site, EE_ROOT );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "ee mailhog status failed.\n" . self::describe( $result ) );
		}

		return false !== strpos( $result->stdout, 'Mailhog is UP' );
	}

	/**
	 * @Given mailhog is enabled on site :site
	 */
	public function mailhogIsEnabledOnSite( $site )
	{
		self::assertSiteName( $site );
		$row = self::dbRow( $site );
		if ( ! $this->mailhogStatus( $site ) || null === $row || ! (int) $row['mailhog_enabled'] ) {
			$result = $this->launch( 'bin/ee mailhog enable ' . $site . ' --force', EE_ROOT );
			if ( 0 !== $result->return_code || ! $this->mailhogStatus( $site ) ) {
				throw new Exception( "Could not enable mailhog on $site.\n" . self::describe( $result ) );
			}
		}
	}

	/**
	 * @Given mailhog is disabled on site :site
	 */
	public function mailhogIsDisabledOnSite( $site )
	{
		self::assertSiteName( $site );
		$row = self::dbRow( $site );
		if ( ! $this->mailhogStatus( $site ) && null !== $row && (int) $row['mailhog_enabled'] ) {
			// DB says enabled but the container is down: `disable` refuses that (it checks only the container), so bring it up first.
			$this->launch( 'bin/ee mailhog enable ' . $site . ' --force', EE_ROOT );
		}
		if ( $this->mailhogStatus( $site ) ) {
			$result = $this->launch( 'bin/ee mailhog disable ' . $site, EE_ROOT );
			if ( 0 !== $result->return_code || $this->mailhogStatus( $site ) ) {
				throw new Exception( "Could not disable mailhog on $site.\n" . self::describe( $result ) );
			}
		}
	}

	/**
	 * Sends a mail with wp_mail() through `ee shell`, i.e. php -> msmtp -> postfix -> (relayhost).
	 *
	 * @When I send a mail to :to with subject :subject from site :site via ee shell
	 */
	public function iSendAMailViaEeShell( $to, $subject, $site )
	{
		self::assertSiteName( $site );
		if ( ! preg_match( '/^[\w.@+\-]+$/', $to ) || ! preg_match( '/^[\w.\-]+$/', $subject ) ) {
			throw new Exception( 'Recipient and subject may only contain letters, digits and . _ - (@ + for the recipient).' );
		}

		$php    = sprintf( 'var_export( wp_mail( "%s", "%s", "Sent by the mailhog-command Behat suite." ) );', $to, $subject );
		$script = "wp eval '" . $php . "'";
		// ee wraps --command in `bash -c "..."` on the host shell, so it has to survive double-quote expansion.
		$command = addcslashes( $script, '"$`\\' );

		// mailhog enable/disable restart postfix and return before it listens again, so wait for port 25 first.
		$php_id   = self::serviceContainerId( $site, 'php' );
		$deadline = microtime( true ) + 30;
		do {
			$probe = EE::launch( sprintf( "docker exec %s php -r 'exit( @fsockopen( \"postfix\", 25 ) ? 0 : 1 );'", escapeshellarg( $php_id ) ), false, true );
			if ( 0 === $probe->return_code ) {
				break;
			}
			usleep( 500000 );
		} while ( microtime( true ) < $deadline );

		// No --user: the php image already runs as www-data, and --user with --command is quoted twice by ee shell and fails.
		$this->commands[] = $this->launch(
			sprintf( 'bin/ee shell %s --skip-tty --command=%s', $site, escapeshellarg( $command ) ),
			EE_ROOT
		);
	}

	/**
	 * @Then I wait up to :seconds seconds until mailhog of site :site has a mail with subject :subject
	 */
	public function iWaitUntilMailhogHasAMailWithSubject( $seconds, $site, $subject )
	{
		$url = "http://$site/ee-admin/mailhog/api/v2/messages";
		$this->waitUntil(
			$seconds,
			function () use ( $url, $subject ) {
				$response = $this->request( $url, '127.0.0.1' );
				$data     = json_decode( $response['body'], true );
				if ( ! is_array( $data ) || empty( $data['items'] ) ) {
					return false;
				}
				foreach ( $data['items'] as $item ) {
					if ( isset( $item['Content']['Headers']['Subject'] ) && in_array( $subject, $item['Content']['Headers']['Subject'], true ) ) {
						return true;
					}
				}

				return false;
			},
			"mailhog of $site has a mail with subject $subject"
		);
	}
}
