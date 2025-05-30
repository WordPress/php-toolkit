<?php

/**
 * This file is part of the Cop package.
 *
 * (c) Phalcon Team <team@phalconphp.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phalcon\Cop;

/**
 * Phalcon\Cop\Parser
 *
 * @package Phalcon\Cop
 */
class Parser
{
    /** @var array */
    private array $parsedCommands = [];

    /**
     * Get value from parsed parameters.
     *
     * @param string|int $key     The parameter's "key"
     * @param mixed      $default A default value in case the key is not set
     *
     * @return mixed
     */
    public function get(int|string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->parsedCommands[$key];
    }

    /**
     * Get boolean from parsed parameters.
     *
     * @param string $key     The parameter's "key"
     * @param bool   $default A default value in case the key is not set
     *
     * @return bool
     */
    public function getBoolean(string $key, bool $default = false): bool
    {
        if (!$this->has($key)) {
            return $default;
        }

        if (
            is_bool($this->parsedCommands[$key]) ||
            is_int($this->parsedCommands[$key])
        ) {
            return (bool)$this->parsedCommands[$key];
        }

        return match ($this->parsedCommands[$key]) {
            'y',
            'yes',
            'true',
            '1',
            'on' => true,
            'n',
            'no',
            'false',
            '0',
            'off' => false,
            default => $default,
        };
    }

    /**
     *
     * @return array
     */
    public function getParsedCommands(): array
    {
        return $this->parsedCommands;
    }

    /**
     * Check if parsed parameters has param.
     *
     * @param string|int $key The parameter's "key"
     *
     * @return bool
     */
    public function has(int|string $key): bool
    {
        return isset($this->parsedCommands[$key]);
    }

	public function getArray(int|string $key): array {
		if(!$this->has($key)) {
			return [];
		}

		if(!is_array($this->parsedCommands[$key])) {
			return [$this->parsedCommands[$key]];
		}

		return $this->parsedCommands[$key];
	}

    /**
     * Parse console input.
     *
     * @param array $argv Arguments to parse. Defaults to empty array
     *
     * @return array
     */
    public function parse(array $argv = []): array
    {
        if (empty($argv)) {
            $argv = $this->getArgvFromServer();
        }

        array_shift($argv);
        $this->parsedCommands = [];

        return $this->handleArguments($argv);
    }

    /**
     * Gets array of arguments passed from the input.
     *
     * @return array
     */
    protected function getArgvFromServer(): array
    {
        return empty($_SERVER['argv']) ? [] : $_SERVER['argv'];
    }

    /**
     * @param string $arg   The argument passed
     * @param int    $eqPos The position of where the equals sign is located
     *
     * @return array
     */
    protected function getParamWithEqual(string $arg, int $eqPos): array
    {
		return [
			$this->stripSlashes(substr($arg, 0, $eqPos)),
			substr($arg, $eqPos + 1),
		];
    }

    /**
     * Handle received parameters
     *
     * @param array $argv The array with the arguments passed in the CLI
     *
     * @return array
     */
    protected function handleArguments(array $argv): array
    {
        $count = count($argv);
        for ($i = 0, $j = $count; $i < $j; $i++) {
            // --foo --bar=baz
            if (str_starts_with($argv[$i], '--')) {
                if ($this->parseAndMergeCommandWithEqualSign($argv[$i])) {// --bar=baz
                    continue;
                }

                $key = $this->stripSlashes($argv[$i]);
                if ($i + 1 < $j && $argv[$i + 1][0] !== '-') {// --foo value
                    $this->setValue($key, $argv[$i + 1]);
                    $i++;
                    continue;
                }
                $this->setValue($key, true); // --foo
                continue;
            }

            // -k=value -abc
            if (str_starts_with($argv[$i], '-')) {
                if ($this->parseAndMergeCommandWithEqualSign($argv[$i])) {// -k=value
                    continue;
                }

                // -a value1 -abc value2 -abc
                $hasNextElementDash = !($i + 1 < $j && $argv[$i + 1][0] !== '-');
                foreach (str_split(substr($argv[$i], 1)) as $char) {
                    $this->parsedCommands[$char] = $hasNextElementDash
                        ? true
                        : $argv[$i + 1];
                }

                if (!$hasNextElementDash) {// -a value1 -abc value2
                    $i++;
                }
                continue;
            }

            $this->parsedCommands[] = $argv[$i];
        }

        return $this->parsedCommands;
    }

    /**
     * Parse command `foo=bar`
     *
     * @param string $command
     *
     * @return bool
     */
    protected function parseAndMergeCommandWithEqualSign(string $command): bool
    {
        $eqPos = strpos($command, '=');

        if ($eqPos !== false) {
			list($key, $value) = $this->getParamWithEqual($command, $eqPos);
			$this->setValue($key, $value);

            return true;
        }

        return false;
    }

	protected function setValue($key, $value) {
		if(isset($this->parsedCommands[$key])) {
			if(!is_array($this->parsedCommands[$key])) {
				$this->parsedCommands[$key] = [$this->parsedCommands[$key]];
			}
			$this->parsedCommands[$key][] = $value;
		} else {
			$this->parsedCommands[$key] = $value;
		}
	}

    /**
     * Delete dashes from param
     *
     * @param string $argument
     *
     * @return string
     */
    protected function stripSlashes(string $argument): string
    {
        if (!str_starts_with($argument, '-')) {
            return $argument;
        }

        $argument = substr($argument, 1);

        return $this->stripSlashes($argument);
    }
}