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
	private $patch_specified;
	/**
	 * @var int
	 */
	private $stage_rank;
	/**
	 * @var int
	 */
	private $stage_index;

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

		$stage_weights = [
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
			$stage_weights[ strtolower( $m['label'] ?? '' ) ] ?? 0,
			(int) ( $m['labelnum'] ?? ( $m['build'] ?? 0 ) )
		);
	}

	private function __construct( $raw, $major, $minor, $patch, $patch_specified, $stage_rank, $stage_index ) {
		$this->raw            = $raw;
		$this->major          = $major;
		$this->minor          = $minor;
		$this->patch          = $patch;
		$this->patch_specified = $patch_specified;
		$this->stage_rank      = $stage_rank;
		$this->stage_index     = $stage_index;
	}

	public function compareTo( Version $other ): int {
		foreach ( [ 'major', 'minor' ] as $part ) {
			if ( $this->$part !== $other->$part ) {
				return ( $this->$part < $other->$part ) ? - 1 : 1;
			}
		}

		if ( $this->patch !== $other->patch ) {
			if ( ! $this->patch_specified || ! $other->patch_specified ) {
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
