<?php

namespace WordPress\Blueprints\VersionStrings;

interface Version {
	public function compareTo( Version $other ): int;

	public function is( string $comparison, Version $other ): bool;

	public function __toString(): string;
}
