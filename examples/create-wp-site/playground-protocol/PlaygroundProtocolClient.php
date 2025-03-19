<?php

/**
 * A client for the Playground CLI protocol to allow the
 * sandboxed PHP code to control its own execution context.
 */
class PlaygroundProtocolClient {

    /**
     * @var PlaygroundProtocolClient|null Singleton instance
     */
    private static $instance = null;
    
    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        // Private constructor to enforce singleton pattern
    }
    
    /**
     * Gets the singleton instance of the PlaygroundProtocolClient
     *
     * @return PlaygroundProtocolClient The singleton instance
     */
    public static function getInstance(): PlaygroundProtocolClient {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }

	/**
	 * Exits the Playground CLI gracefully.
	 */
	public function exit(int $exitCode = 0): void {
		$this->sendMessage([
			'command' => 'exit',
			'exitCode' => $exitCode
		]);
	}
	
    /**
     * Sends a message to the JS handler and processes the response
     *
     * @param array $message The message to send
     * @return array The processed response
     * @throws PlaygroundCliException If the operation fails
     */
    private function sendMessage(array $message): array {
        $response = post_message_to_js(json_encode($message));
        
        if ($response === false) {
            throw new PlaygroundCliException('Failed to send message to JS handler');
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new PlaygroundCliException('Invalid JSON response from JS handler');
        }
        
        if (isset($result['error'])) {
            throw new PlaygroundCliException($result['error']);
        }
        
        return $result;
    }
    
    /**
     * Mounts a directory from the host system
     *
     * @param string $hostPath Relative path to the directory to mount from host
     * @param string $playgroundVfsPath Absolute path in the Playground VFS to mount at
     * @throws PlaygroundCliException If the mount operation fails
     */
    public function mountDirectory(string $hostPath, string $playgroundVfsPath): void {
		if(!is_dir($playgroundVfsPath)) {
			mkdir($playgroundVfsPath, 0777, true);
		}
        $this->sendMessage([
            'command' => 'mount',
            'hostPath' => $hostPath,
            'playgroundVfsPath' => $playgroundVfsPath
        ]);
    }
    
    /**
     * Moves the cursor to a specific position (TTY mode only)
     *
     * @param int $x The x coordinate
     * @throws PlaygroundCliException If the operation fails
     */
    public function cursorTo(int $x): void {
        $this->sendMessage([
            'command' => 'stdout',
            'method' => 'cursorTo',
            'x' => $x
        ]);
    }
    
    /**
     * Writes text to stdout (TTY mode only)
     *
     * @param string $text The text to write
     * @throws PlaygroundCliException If the operation fails
     */
    public function write(string $text): void {
        $this->sendMessage([
            'command' => 'stdout',
            'method' => 'write',
            'text' => $text
        ]);
    }
    
    /**
     * Clears the current line to the right of the cursor (TTY mode only)
     *
     * @throws PlaygroundCliException If the operation fails
     */
    public function clearLine(): void {
        $this->sendMessage([
            'command' => 'stdout',
            'method' => 'clearLine',
            'dir' => 1
        ]);
    }
}

class PlaygroundCliException extends Exception {}

if(!function_exists('post_message_to_js')) {
	/**
	 * Sends a message to the JavaScript environment and returns the response.
	 * 
	 * This function is implemented by the WordPress Playground runtime and allows
	 * PHP code to communicate with the JavaScript environment.
	 *
	 * @param string $message The message to send to JavaScript
	 * @return string|false The response from JavaScript, or false on failure
	 */
	function post_message_to_js(string $message) {
		// This is just a stub for the linter
		// The actual implementation is provided by the WordPress Playground runtime
		return false;
	}
}

