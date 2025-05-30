<?php
/**
 * The bootstrap sequence for the PHP Toolkit libraries.
 *
 * Yes, it inly requires the composer autoload file. The simplicity is
 * intentional. Everything that's required to run the libraries should
 * be autoloaded from one of the libraries, not hardcoded here.
 *
 * This file is currently required by:
 * * Scripts in bin/
 * * Box when building the phar file
 * * PHPUnit when running tests
 */

require_once __DIR__ . '/vendor/autoload.php';
