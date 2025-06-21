<?php

namespace WordPress\Blueprints;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\Logger\NoopLogger;

class RunnerConfiguration {
	// Permission constants
	public const PERMISSION_LOCAL_FILESYSTEM_ACCESS = 'read-local-fs';

	// Array of all available permissions
	public const ALL_PERMISSIONS = [
		self::PERMISSION_LOCAL_FILESYSTEM_ACCESS,
	];

	/**
	 * @var DataReference|mixed[]
	 */
	private $blueprintRef;
	/**
	 * @var string
	 */
	private $mode = Runner::EXECUTION_MODE_CREATE_NEW_SITE;
	/**
	 * @var string
	 */
	private $rootDir = '';
	/**
	 * @var string
	 */
	private $siteUrl = '';
	/**
	 * @var string
	 */
	private $databaseEngine = 'mysql';
	/**
	 * @var mixed[]
	 */
	private $databaseCredentials = [];
	private $progressObserver = null;
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
	private $sqliteIntegrationPlugin;

	public function __construct() {
		$this->sqliteIntegrationPlugin = DataReference::create( 'https://downloads.wordpress.org/plugin/sqlite-database-integration.zip' );
		$this->logger                  = new NoopLogger();
		$this->permissions             = [
			self::PERMISSION_LOCAL_FILESYSTEM_ACCESS => false,
		];
	}

	/**
	 * @param  DataReference|mixed[]  $r
	 */
	public function setBlueprint( $r ): self {
		$this->blueprintRef = $r;

		return $this;
	}

	/**
	 * @return DataReference|mixed[]
	 */
	public function getBlueprint() {
		return $this->blueprintRef;
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
		$this->rootDir = $d;

		return $this;
	}

	public function getTargetSiteRoot(): string {
		return $this->rootDir;
	}

	public function setTargetSiteUrl( string $u ): self {
		$this->siteUrl = $u;

		return $this;
	}

	public function getTargetSiteUrl(): string {
		return $this->siteUrl;
	}

	/**
	 * Sets the database engine.
	 *
	 * @param  string  $databaseEngine  Database engine to use ('mysql' or 'sqlite')
	 *
	 * @return self
	 * @throws InvalidArgumentException If the database engine is invalid
	 */
	public function setDatabaseEngine( string $databaseEngine ): self {
		if ( ! in_array( $databaseEngine, [ 'mysql', 'sqlite' ] ) ) {
			throw new InvalidArgumentException( "Invalid database engine: {$databaseEngine}" );
		}

		$this->databaseEngine = $databaseEngine;

		return $this;
	}

	public function getDatabaseEngine(): string {
		return $this->databaseEngine;
	}

	/**
	 * Sets the database credentials.
	 *
	 * @param  array  $databaseCredentials  Connection parameters for the database
	 *
	 * @return self
	 */
	public function setDatabaseCredentials( array $databaseCredentials ): self {
		$this->databaseCredentials = $databaseCredentials;

		return $this;
	}

	public function getDatabaseCredentials(): array {
		return $this->databaseCredentials;
	}

	/**
	 * Sets a callback function to be called to report progress during execution.
	 *
	 * @param  callable|null  $callback  A function that accepts progress information
	 *
	 * @return self
	 */
	public function setProgressObserver( ProgressObserver $observer ): self {
		$this->progressObserver = $observer;

		return $this;
	}

	/**
	 * Gets the progress callback function.
	 *
	 * @return callable|null
	 */
	public function getProgressObserver() {
		return $this->progressObserver;
	}

	/**
	 * Set a custom DataReference for the sqlite-database-integration plugin.
	 *
	 * @param  DataReference  $ref
	 *
	 * @return self
	 */
	public function setSqliteIntegrationPlugin( DataReference $ref ): self {
		$this->sqliteIntegrationPlugin = $ref;

		return $this;
	}

	/**
	 * Get the DataReference for the sqlite-database-integration plugin, or null if not set.
	 *
	 * @return DataReference|null
	 */
	public function getSqliteIntegrationPlugin(): ?DataReference {
		return $this->sqliteIntegrationPlugin;
	}

	/**
	 * Enables the runner to source the execution context files from the local filesystem.
	 *
	 * @param  bool  $allow  True to allow filesystem access, false to deny.
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
	 * @param  string  $permission  One of the PERMISSION_* constants
	 *
	 * @return string The CLI flag name
	 */
	public static function getPermissionCliFlag( string $permission ): string {
		return $permission;
	}

	public function isRunningAsPhar(): bool {
		return \Phar::running(false) !== '';
	}
}
