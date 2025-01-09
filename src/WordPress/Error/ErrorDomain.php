<?php

namespace WordPress\Error;

use \WP_Error;

/**
 * A Domain groups error handling into a container, similar to Node.js domains.
 * It allows grouping multiple different operations into one error handling context.
 */
class ErrorDomain {

    /**
     * @var callable|null The error handler registered for this domain
     */
    private $error_handler = null;

    /**
     * @var string|null A label for the domain, useful for debugging
     */
    private $label = null;

    /**
     * @var callable|null A fallback fallback error handler
     */
    private static $fallback_error_handler = null;

    private $last_return_value = null;

    /**
     * Creates a new domain instance with an optional label.
     *
     * @param string|null $label A label for the domain
     * @return self
     */
    public static function create($label = null) {
        $instance = new self();
        $instance->label = $label;
        return $instance;
    }

    /**
     * Sets a fallback fallback error handler.
     *
     * @param callable $handler The fallback error handler function
     * @return void
     */
    public static function set_fallback_error_handler($handler) {
        self::$fallback_error_handler = $handler;
    }

    public static function bail($exception) {
        throw $exception;
    }

    /**
     * Adds an error handler to this domain.
     *
     * @param callable $handler The error handler function
     * @return void
     */
    public function set_error_handler($handler) {
        $this->error_handler = $handler;
    }

    /**
     * Executes a callback within this domain's error handling context.
     *
     * @param callable $callback The code to execute
     * @return bool True if the callback executed successfully, false otherwise
     */
    public function safe_run(callable $callback) {
        try {
            $this->last_return_value = $callback($this);
            return true;
        } catch (\Throwable $exception) {
            if ($this->error_handler) {
                call_user_func($this->error_handler, $exception, $this);
            } else {
                call_user_func(self::$fallback_error_handler, $exception);
            }
            return false;
        }
    }

    public function get_last_return_value() {
        return $this->last_return_value;
    }

    /**
     * Gets the label of the domain.
     *
     * @return string|null The label of the domain
     */
    public function get_label() {
        return $this->label;
    }

}

// Set the default fallback error handler
ErrorDomain::set_fallback_error_handler(function($exception) {
    error_log('fallback handler: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString());
});
