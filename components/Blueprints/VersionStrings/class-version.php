<?php

namespace WordPress\Blueprints\VersionStrings;

interface Version {
	public function compare_to( Version $other ): int;

	public function is( string $comparison, Version $other ): bool;

	public function __toString(): string;
}
