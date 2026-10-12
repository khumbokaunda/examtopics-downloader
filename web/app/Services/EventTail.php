<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Incrementally reads a JSON Lines progress file written by the tool. Only complete lines
 * are consumed; a line being written is picked up on the next read. Malformed lines are
 * skipped (the tool never writes them, but a truncated file after a crash might).
 */
final class EventTail
{
    private int $offset = 0;
    private string $partial = '';

    public function __construct(private string $path)
    {
    }

    /** @return list<array<string,mixed>> */
    public function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $fh = fopen($this->path, 'rb');
        if ($fh === false) {
            return [];
        }
        try {
            fseek($fh, $this->offset);
            $chunk = stream_get_contents($fh, 4 * 1024 * 1024);
        } finally {
            fclose($fh);
        }
        if ($chunk === false || $chunk === '') {
            return [];
        }
        $this->offset += strlen($chunk);
        $data = $this->partial . $chunk;
        $lines = explode("\n", $data);
        $this->partial = (string) array_pop($lines);

        $events = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $e = json_decode($line, true);
            if (is_array($e) && isset($e['event']) && is_string($e['event'])) {
                $events[] = $e;
            }
        }
        return $events;
    }
}
