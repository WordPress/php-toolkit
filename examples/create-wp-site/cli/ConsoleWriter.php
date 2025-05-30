<?php

interface ConsoleWriter {
    /**
     * Write text at the current cursor position
     * 
     * @param string $text Text to write
     */
    public function write(string $text): void;

    /**
     * Move cursor to beginning of line and clear everything after
     */
    public function clearLine(): void;

    /**
     * Replace current line with new text
     * 
     * @param string $text New text for the line
     */
    public function replaceLine(string $text): void;

    /**
     * Write multiple lines, optionally replacing previous output
     * 
     * @param array $lines Array of text lines to write
     * @param bool $replace Whether to replace previous output
     */
    public function writeLines(array $lines, bool $replace = false): void;
}

class PlaygroundConsoleWriter implements ConsoleWriter {
    private PlaygroundProtocolClient $client;

    public function __construct() {
        $this->client = PlaygroundProtocolClient::getInstance();
    }

    public function write(string $text): void {
        $this->client->write($text);
    }

    public function clearLine(): void {
        $this->client->cursorTo(0);
        $this->client->clearLine();
    }

    public function replaceLine(string $text): void {
        $this->clearLine();
        $this->write($text);
    }

    public function writeLines(array $lines, bool $replace = false): void {
        if ($replace) {
            // Move up by number of lines and clear them
            foreach ($lines as $i => $line) {
                if ($i > 0) {
                    $this->client->write("\033[1A"); // Move up one line
                }
                $this->clearLine();
            }
        }
        
        foreach ($lines as $line) {
            $this->write($line . PHP_EOL);
        }
    }
}

class PhpConsoleWriter implements ConsoleWriter {
    private $stdout;

    public function __construct() {
        $this->stdout = fopen('php://stdout', 'w');
    }

    public function __destruct() {
        fclose($this->stdout);
    }

    public function write(string $text): void {
        fwrite($this->stdout, $text);
    }

    public function clearLine(): void {
        if (!$this->isTty()) {
            return;
        }
        fwrite($this->stdout, "\r\033[K"); // Return to start + clear to end
    }

    public function replaceLine(string $text): void {
        $this->clearLine();
        $this->write($text);
    }

    public function writeLines(array $lines, bool $replace = false): void {
        if ($replace && $this->isTty()) {
            // Move up by number of lines and clear them
            foreach ($lines as $i => $line) {
                if ($i > 0) {
                    fwrite($this->stdout, "\033[1A"); // Move up one line
                }
                $this->clearLine();
            }
        }
        
        foreach ($lines as $line) {
            $this->write($line . PHP_EOL);
        }
    }

    private function isTty(): bool {
        return stream_isatty($this->stdout);
    }
}
