<?php

declare(strict_types=1);

namespace ForgePanel\Agent;

/**
 * Kontekst izvršavanja operacije: progress/output izvještavanje.
 * Za sinkrone operacije callbackovi su no-op; TaskWorker ih veže na tablicu tasks.
 */
final class TaskContext
{
    /** @var ?\Closure(int): void */
    private ?\Closure $on_progress;
    /** @var ?\Closure(string): void */
    private ?\Closure $on_output;
    /** @var ?\Closure(string): void */
    private ?\Closure $on_output_raw;

    public function __construct(?\Closure $on_progress = null, ?\Closure $on_output = null, ?\Closure $on_output_raw = null)
    {
        $this->on_progress = $on_progress;
        $this->on_output = $on_output;
        $this->on_output_raw = $on_output_raw;
    }

    /** Doslovan dodatak u output (bez rtrim/newline) — za live streaming (npr. claude tokeni). */
    public function outputRaw(string $chunk): void
    {
        if ($this->on_output_raw !== null) {
            ($this->on_output_raw)($chunk);
        }
    }

    public function progress(int $percent): void
    {
        if ($this->on_progress !== null) {
            ($this->on_progress)(max(0, min(100, $percent)));
        }
    }

    public function output(string $line): void
    {
        if ($this->on_output !== null) {
            ($this->on_output)(rtrim($line) . "\n");
        }
    }
}
