<?php

declare(strict_types=1);

namespace WordPressPhpToolkit\MonorepoBuilder;

use PharIo\Version\Version;
use Symplify\MonorepoBuilder\DevMasterAliasUpdater;
use Symplify\MonorepoBuilder\FileSystem\ComposerJsonProvider;
use Symplify\MonorepoBuilder\Release\Contract\ReleaseWorker\ReleaseWorkerInterface;
use Symplify\MonorepoBuilder\Release\Process\ProcessRunner;
use Symplify\MonorepoBuilder\Utils\VersionUtils;
use Symplify\MonorepoBuilder\ValueObject\Option;
use Symplify\PackageBuilder\Parameter\ParameterProvider;

/**
 * Custom PushNextDevReleaseWorker that respects the configured default branch.
 *
 * This worker is a replacement for the default PushNextDevReleaseWorker from
 * symplify/monorepo-builder, which hardcodes "master" as the branch name.
 */
final class CustomPushNextDevReleaseWorker implements ReleaseWorkerInterface {

	/**
	 * Process runner.
	 *
	 * @var ProcessRunner
	 */
	private $processRunner;

	/**
	 * Version utilities.
	 *
	 * @var VersionUtils
	 */
	private $versionUtils;

	/**
	 * Composer JSON provider.
	 *
	 * @var ComposerJsonProvider
	 */
	private $composerJsonProvider;

	/**
	 * Dev master alias updater.
	 *
	 * @var DevMasterAliasUpdater
	 */
	private $devMasterAliasUpdater;

	/**
	 * Parameter provider.
	 *
	 * @var ParameterProvider
	 */
	private $parameterProvider;

	/**
	 * Constructor.
	 *
	 * @param ProcessRunner         $processRunner         Process runner.
	 * @param VersionUtils          $versionUtils          Version utilities.
	 * @param ComposerJsonProvider  $composerJsonProvider  Composer JSON provider.
	 * @param DevMasterAliasUpdater $devMasterAliasUpdater Dev master alias updater.
	 * @param ParameterProvider     $parameterProvider     Parameter provider.
	 */
	public function __construct(
		ProcessRunner $processRunner,
		VersionUtils $versionUtils,
		ComposerJsonProvider $composerJsonProvider,
		DevMasterAliasUpdater $devMasterAliasUpdater,
		ParameterProvider $parameterProvider
	) {
		$this->processRunner         = $processRunner;
		$this->versionUtils          = $versionUtils;
		$this->composerJsonProvider  = $composerJsonProvider;
		$this->devMasterAliasUpdater = $devMasterAliasUpdater;
		$this->parameterProvider     = $parameterProvider;
	}

	/**
	 * Perform the work of pushing the next dev release.
	 *
	 * @param Version $version Version to release.
	 */
	public function work( Version $version ): void {
		// Get the configured default branch from parameters.
		$defaultBranch = $this->parameterProvider->provideParameter( Option::DEFAULT_BRANCH );

		$versionInString = $this->versionUtils->getRequiredNextAliasFormat( $version );

		$gitAddCommitCommand = sprintf(
			'git add . && git commit --allow-empty -m "open %s" && git push origin "%s"',
			$versionInString,
			$defaultBranch
		);

		$this->processRunner->run( $gitAddCommitCommand );
	}

	/**
	 * Get the description of this release worker.
	 *
	 * @param Version $version Version to release.
	 * @return string Description.
	 */
	public function getDescription( Version $version ): string {
		$versionInString = $this->versionUtils->getRequiredNextAliasFormat( $version );

		// Get the configured default branch from parameters.
		$defaultBranch = $this->parameterProvider->provideParameter( Option::DEFAULT_BRANCH );

		return sprintf( 'Push "%s" open to remote repository on branch "%s"', $versionInString, $defaultBranch );
	}
}
