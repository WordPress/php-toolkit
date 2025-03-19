<?php

class ProgressBar {
    private ConsoleWriter $writer;
    private ?int $total;
    private int $current;
    private int $width;
    private string $message;
    private float $startTime;
    private bool $started = false;
    private bool $indeterminate = false;

    public function __construct(ConsoleWriter $writer, ?int $total = 100, int $width = 50) {
        $this->writer = $writer;
        $this->total = $total;
        $this->indeterminate = ($total === null);
        $this->current = 0;
        $this->width = $width;
        $this->message = '';
    }

    public function start(): void {
        if ($this->started) {
            return;
        }
        $this->started = true;
        $this->startTime = microtime(true);
        $this->update();
    }

    public function advance(int $step = 1): void {
        $this->setCurrent($this->current + $step);
    }

    public function setCurrent(int $current): void {
        $this->current = $this->indeterminate ? $current : min($this->total, max(0, $current));
        $this->update();
    }

    public function setMessage(string $message): void {
        $this->message = $message;
        $this->update();
    }

    public function finish(): void {
        if (!$this->started) {
            return;
        }
        if (!$this->indeterminate) {
            $this->current = $this->total;
        }
        $this->update();
        $this->writer->write("\n");
    }

    private function update(): void {
        if (!$this->started) {
            return;
        }

        if ($this->indeterminate) {
            $this->updateIndeterminate();
        } else {
            $this->updateDeterminate();
        }
    }

    private function updateIndeterminate(): void {
        $elapsed = microtime(true) - $this->startTime;
        
        // Create a "moving" animation for indeterminate progress
        $position = (int)($elapsed * 5) % ($this->width * 2);
        if ($position >= $this->width) {
            $position = $this->width * 2 - $position;
        }

		$spaces_before = min(max(0, $position), $this->width - 3);
		$spaces_after = max(0, $this->width - $position - 3);
        
        $bar = str_repeat(' ', $spaces_before) . '<=>' . str_repeat(' ', $spaces_after);
        $status = sprintf(
            "[%s] %d items - %s",
            $bar,
            $this->current,
            $this->message
        );
        
        $this->writer->replaceLine($status);
    }

    private function updateDeterminate(): void {
        $percentage = $this->current / $this->total;
        $filled = (int)round($this->width * $percentage);
        $empty = $this->width - $filled;
        
        $bar = str_repeat('=', $filled);
        if ($empty > 0) {
            $bar .= '>';
            $bar .= str_repeat(' ', $empty - 1);
        }

		$status = sprintf(
			"[%s] %d/%d - %s",
			$bar,
			$this->current,
			$this->total,
			$this->message
		);

        $this->writer->replaceLine($status);
    }
} 