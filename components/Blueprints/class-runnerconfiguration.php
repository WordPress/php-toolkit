<?php

namespace WordPress\Blueprints;

use InvalidArgumentException;
use VendorPrefix\Psr\Log\LoggerInterface;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\Logger\NoopLogger;

class RunnerConfiguration {
	// Permission constants.
	public const PERMISSION_LOCAL_FILESYSTEM_ACCESS = 'read-local-fs';

	// Array of all available permissions.
	public const ALL_PERMISSIONS = array(
		self::PERMISSION_LOCAL_FILESYSTEM_ACCESS,
	);

	/**
	 * @var DataReference|mixed[]
	 */
	private $blueprint_ref;
	/**
	 * @var string
	 */
	private $mode = Runner::EXECUTION_MODE_CREATE_NEW_SITE;
	/**
	 * @var string
	 */
	private $root_dir = '';
	/**
	 * @var string
	 */
	private $site_url = '';
	/**
	 * @var string
	 */
	private $database_engine = 'mysql';
	/**
	 * @var mixed[]
	 */
	private $database_credentials = array();
	private $progress_observer    = null;
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var mixed[]
	 */
	private $permissions;

	/**
	 * @var DataReference|null
	 * Reference to the sqlite-database-integration plugin zip, if configured.
	 */
	private $sqlite_integration_plugin;

	/**
	 * @var DataReference|null
	 * Reference to the WP-CLI phar file, if configured.
	 */
	private $wp_cli_reference;

	public function __construct() {
		$this->sqlite_integration_plugin = DataReference::create( 'https://downloads.wordpress.org/plugin/sqlite-database-integration.zip' );
		$this->wp_cli_reference          = DataReference::create( 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar' );
		$this->logger                    = new NoopLogger();
		$this->permissions               = array(
			self::PERMISSION_LOCAL_FILESYSTEM_ACCESS => false,
		);
	}

	/**
	 * @param  DataReference|mixed[] $r
	 */
	public function set_blueprint( $r ): self {
		$this->blueprint_ref = $r;

		return $this;
	}

	/**
	 * @return DataReference|mixed[]
	 */
	public function get_blueprint() {
		return $this->blueprint_ref;
	}

	public function set_logger( LoggerInterface $logger ): self {
		$this->logger = $logger;

		return $this;
	}

	public function get_logger(): LoggerInterface {
		return $this->logger;
	}

	public function set_execution_mode( string $m ): self {
		$this->mode = $m;

		return $this;
	}

	public function get_execution_mode(): string {
		return $this->mode;
	}

	public function set_target_site_root( string $d ): self {
		$this->root_dir = $d;

		return $this;
	}

	public function get_target_site_root(): string {
		return $this->root_dir;
	}

	public function set_target_site_url( string $u ): self {
		$this->site_url = $u;

		return $this;
	}

	public function get_target_site_url(): string {
		return $this->site_url;
	}

	/**
	 * Sets the database engine.
	 *
	 * @param  string $database_engine  Database engine to use ('mysql' or 'sqlite')
	 *
	 * @return self
	 * @throws InvalidArgumentException If the database engine is invalid.
	 */
	public function set_database_engine( string $database_engine ): self {
		if ( ! in_array( $database_engine, array( 'mysql', 'sqlite' ) ) ) {
			throw new InvalidArgumentException( "Invalid database engine: {$database_engine}" );
		}

		$this->database_engine = $database_engine;

		return $this;
	}

	public function get_database_engine(): string {
		return $this->database_engine;
	}

	/**
	 * Sets the database credentials.
	 *
	 * @param  array $database_credentials  Connection parameters for the database
	 *
	 * @return self
	 */
	public function set_database_credentials( array $database_credentials ): self {
		$this->database_credentials = $database_credentials;

		return $this;
	}

	public function get_database_credentials(): array {
		return $this->database_credentials;
	}

	/**
	 * Sets a callback function to be called to report progress during execution.
	 *
	 * @param  callable|null $observer  A function that accepts progress information
	 *
	 * @return self
	 */
	public function set_progress_observer( ProgressObserver $observer ): self {
		$this->progress_observer = $observer;

		return $this;
	}

	/**
	 * Gets the progress callback function.
	 *
	 * @return callable|null
	 */
	public function get_progress_observer() {
		return $this->progress_observer;
	}

	/**
	 * Set a custom DataReference for the sqlite-database-integration plugin.
	 *
	 * @param  DataReference $ref
	 *
	 * @return self
	 */
	public function set_sqlite_integration_plugin( DataReference $ref ): self {
		$this->sqlite_integration_plugin = $ref;

		return $this;
	}

	/**
	 * Get the DataReference for the sqlite-database-integration plugin, or null if not set.
	 *
	 * @return DataReference|null
	 */
	public function get_sqlite_integration_plugin(): ?DataReference {
		return $this->sqlite_integration_plugin;
	}

	/**
	 * Set a custom DataReference for the WP-CLI phar file.
	 *
	 * @param  DataReference $ref
	 *
	 * @return self
	 */
	public function set_wp_cli_reference( DataReference $ref ): self {
		$this->wp_cli_reference = $ref;

		return $this;
	}

	/**
	 * Get the DataReference for the WP-CLI phar file.
	 *
	 * @return DataReference
	 */
	public function get_wp_cli_reference(): DataReference {
		return $this->wp_cli_reference;
	}

	/**
	 * Enables the runner to source the execution context files from the local filesystem.
	 *
	 * @param  bool $allow  True to allow filesystem access, false to deny.
	 *
	 * @return self
	 */
	public function set_allow_local_filesystem_access( bool $allow ): self {
		$this->permissions[ self::PERMISSION_LOCAL_FILESYSTEM_ACCESS ] = $allow;

		return $this;
	}

	/**
	 * Checks if general access to the local filesystem is allowed.
	 *
	 * @return bool True if filesystem access is allowed, false otherwise.
	 */
	public function is_allowed_local_filesystem_access(): bool {
		return $this->permissions[ self::PERMISSION_LOCAL_FILESYSTEM_ACCESS ];
	}

	/**
	 * Gets the CLI flag that corresponds to a permission constant.
	 *
	 * @param  string $permission  One of the PERMISSION_* constants
	 *
	 * @return string The CLI flag name
	 */
	public static function get_permission_cli_flag( string $permission ): string {
		return $permission;
	}

	public function is_running_as_phar(): bool {
		return '' !== \Phar::running( false );
	}
}
