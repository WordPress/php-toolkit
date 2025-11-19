<?php

declare(strict_types=1);

use Symplify\MonorepoBuilder\Config\MBConfig;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\AddTagToChangelogReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\PushTagReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\SetCurrentMutualDependenciesReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\SetNextMutualDependenciesReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\TagVersionReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\UpdateBranchAliasReleaseWorker;
use Symplify\MonorepoBuilder\Release\ReleaseWorker\UpdateReplaceReleaseWorker;
use WordPressPhpToolkit\MonorepoBuilder\CustomPushNextDevReleaseWorker;

return static function ( MBConfig $config ): void {
	// Where packages live.
	$config->packageDirectories(
		array(
			__DIR__ . '/components',
		)
	);
	$config->defaultBranch( 'trunk' );
	// Release workers - in order to execute.
	$config->workers(
		array(
			UpdateReplaceReleaseWorker::class,
			SetCurrentMutualDependenciesReleaseWorker::class,
			AddTagToChangelogReleaseWorker::class,
			TagVersionReleaseWorker::class,
			PushTagReleaseWorker::class,
			SetNextMutualDependenciesReleaseWorker::class,
			UpdateBranchAliasReleaseWorker::class,
			CustomPushNextDevReleaseWorker::class,
		)
	);
};
