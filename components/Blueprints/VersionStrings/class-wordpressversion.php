<?php

namespace WordPress\Blueprints\VersionStrings;

use InvalidArgumentException;

class WordPressVersion implements Version {
	/**
	 * @var string
	 */
	private $raw;
	/**
	 * @var int
	 */
	private $major;
	/**
	 * @var int
	 */
	private $minor;
	/**
	 * @var int
	 */
	private $patch;
	/**
	 * @var bool
	 */
	private $patchSpecified;
	/**
	 * @var int
	 */
	private $stageRank;
	/**
	 * @var int
	 */
	private $stageIndex;

	/**
	 * Parses a WordPress version string.
	 * 
	 * Return values:
	 * 
	 * * WordPressVersion
	 * * false – invalid version string
	 * * null – non-comparable version strings like beta, trunk, etc.
	 * 
	 * @return $this|false|null
	 */
	static public function fromString( string $raw ) {
		if(in_array($raw, ['beta','trunk','latest'], true)) {
			return null;
		}
		if(substr($raw, 0, 8) === 'https://') {
			return null;
		}

		$pattern = '/^\s*
            (?P<major>\d+)
            (?:\.(?P<minor>\d+))?
            (?:\.(?P<patch>\d+))?
            (?:[.\-]?(?P<label>[a-zA-Z]+)(?P<labelnum>\d*))?
            (?:[.\-]src)?                 # ignore “-src”
            (?:[.\-](?P<build>\d+))?
            \s*$/x';

		if ( ! preg_match( $pattern, $raw, $m ) ) {
			return false;
		}

		$stageWeights = [
			'dev'   => 0,
			'src'   => 0,
			'alpha' => 1,
			'a'     => 1,
			'beta'  => 2,
			'b'     => 2,
			'rc'    => 3,
			''      => 4,
			'pl'    => 5,
		];

		return new self(
			$raw,
			(int) $m['major'],
			(int) ( $m['minor'] ?? 0 ),
			(int) ( $m['patch'] ?? 0 ),
			isset( $m['patch'] ) && $m['patch'] !== '',
			$stageWeights[ strtolower( $m['label'] ?? '' ) ] ?? 0,
			(int) ( $m['labelnum'] ?? ( $m['build'] ?? 0 ) )
		);
	}

	private function __construct( $raw, $major, $minor, $patch, $patchSpecified, $stageRank, $stageIndex ) {
		$this->raw            = $raw;
		$this->major          = $major;
		$this->minor          = $minor;
		$this->patch          = $patch;
		$this->patchSpecified = $patchSpecified;
		$this->stageRank      = $stageRank;
		$this->stageIndex     = $stageIndex;
	}

	public function compareTo( Version $other ): int {
		foreach ( [ 'major', 'minor' ] as $part ) {
			if ( $this->$part !== $other->$part ) {
				return ( $this->$part < $other->$part ) ? - 1 : 1;
			}
		}

		if ( $this->patch !== $other->patch ) {
			if ( ! $this->patchSpecified || ! $other->patchSpecified ) {
				// do nothing – fall through to stage comparison
			} else {
				return ( $this->patch < $other->patch ) ? - 1 : 1;
			}
		}

		foreach ( [ 'stageRank', 'stageIndex' ] as $part ) {
			if ( $this->$part !== $other->$part ) {
				return ( $this->$part < $other->$part ) ? - 1 : 1;
			}
		}

		return 0;
	}

	public function is( string $comparison, Version $other ): bool {
		switch ( $comparison ) {
			case '>':
				return $this->compareTo( $other ) > 0;
			case '<':
				return $this->compareTo( $other ) < 0;
			case '>=':
				return $this->compareTo( $other ) >= 0;
			case '<=':
				return $this->compareTo( $other ) <= 0;
			case '==':
				return $this->compareTo( $other ) == 0;
			default:
				throw new InvalidArgumentException( "Invalid comparison operator: {$comparison}" );
		}
	}

	public function __toString(): string {
		return $this->raw;
	}

}
