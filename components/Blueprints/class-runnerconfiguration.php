<?php

namespace WordPress\Blueprints;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
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
	public function setBlueprint( $r ): self {
		$this->blueprint_ref = $r;

		return $this;
	}

	/**
	 * @return DataReference|mixed[]
	 */
	public function getBlueprint() {
		return $this->blueprint_ref;
	}

	public function setLogger( LoggerInterface $logger ): self {
		$this->logger = $logger;

		return $this;
	}

	public function getLogger(): LoggerInterface {
		return $this->logger;
	}

	public function setExecutionMode( string $m ): self {
		$this->mode = $m;

		return $this;
	}

	public function getExecutionMode(): string {
		return $this->mode;
	}

	public function setTargetSiteRoot( string $d ): self {
		$this->root_dir = $d;

		return $this;
	}

	public function getTargetSiteRoot(): string {
		return $this->root_dir;
	}

	public function setTargetSiteUrl( string $u ): self {
		$this->site_url = $u;

		return $this;
	}

	public function getTargetSiteUrl(): string {
		return $this->site_url;
	}

	/**
	 * Sets the database engine.
	 *
	 * @param  string $databaseEngine  Database engine to use ('mysql' or 'sqlite')
	 *
	 * @return self
	 * @throws InvalidArgumentException If the database engine is invalid.
	 */
	public function setDatabaseEngine( string $database_engine ): self {
		if ( ! in_array( $database_engine, array( 'mysql', 'sqlite' ) ) ) {
			throw new InvalidArgumentException( "Invalid database engine: {$database_engine}" );
		}

		$this->database_engine = $database_engine;

		return $this;
	}

	public function getDatabaseEngine(): string {
		return $this->database_engine;
	}

	/**
	 * Sets the database credentials.
	 *
	 * @param  array $databaseCredentials  Connection parameters for the database
	 *
	 * @return self
	 */
	public function setDatabaseCredentials( array $database_credentials ): self {
		$this->database_credentials = $database_credentials;

		return $this;
	}

	public function getDatabaseCredentials(): array {
		return $this->database_credentials;
	}

	/**
	 * Sets a callback function to be called to report progress during execution.
	 *
	 * @param  callable|null $callback  A function that accepts progress information
	 *
	 * @return self
	 */
	public function setProgressObserver( ProgressObserver $observer ): self {
		$this->progress_observer = $observer;

		return $this;
	}

	/**
	 * Gets the progress callback function.
	 *
	 * @return callable|null
	 */
	public function getProgressObserver() {
		return $this->progress_observer;
	}

	/**
	 * Set a custom DataReference for the sqlite-database-integration plugin.
	 *
	 * @param  DataReference $ref
	 *
	 * @return self
	 */
	public function setSqliteIntegrationPlugin( DataReference $ref ): self {
		$this->sqlite_integration_plugin = $ref;

		return $this;
	}

	/**
	 * Get the DataReference for the sqlite-database-integration plugin, or null if not set.
	 *
	 * @return DataReference|null
	 */
	public function getSqliteIntegrationPlugin(): ?DataReference {
		return $this->sqlite_integration_plugin;
	}

	/**
	 * Set a custom DataReference for the WP-CLI phar file.
	 *
	 * @param  DataReference $ref
	 *
	 * @return self
	 */
	public function setWpCliReference( DataReference $ref ): self {
		$this->wp_cli_reference = $ref;

		return $this;
	}

	/**
	 * Get the DataReference for the WP-CLI phar file.
	 *
	 * @return DataReference
	 */
	public function getWpCliReference(): DataReference {
		return $this->wp_cli_reference;
	}

	/**
	 * Enables the runner to source the execution context files from the local filesystem.
	 *
	 * @param  bool $allow  True to allow filesystem access, false to deny.
	 *
	 * @return self
	 */
	public function setAllowLocalFilesystemAccess( bool $allow ): self {
		$this->permissions[ self::PERMISSION_LOCAL_FILESYSTEM_ACCESS ] = $allow;

		return $this;
	}

	/**
	 * Checks if general access to the local filesystem is allowed.
	 *
	 * @return bool True if filesystem access is allowed, false otherwise.
	 */
	public function isAllowedLocalFilesystemAccess(): bool {
		return $this->permissions[ self::PERMISSION_LOCAL_FILESYSTEM_ACCESS ];
	}

	/**
	 * Gets the CLI flag that corresponds to a permission constant.
	 *
	 * @param  string $permission  One of the PERMISSION_* constants
	 *
	 * @return string The CLI flag name
	 */
	public static function getPermissionCliFlag( string $permission ): string {
		return $permission;
	}

	public function isRunningAsPhar(): bool {
		return '' !== \Phar::running( false );
	}
}
