<?php

namespace App\Services;

/**
 * Collects bounded, normalized streaming chunks for retained-content capture.
 *
 * The accumulator never sits between the upstream event and the client write:
 * events are appended in memory after they have been flushed, so streaming
 * latency and flush behaviour are unchanged. Once the configured byte or chunk
 * ceiling is reached the accumulator stops recording and marks the payload
 * truncated.
 */
final class StreamContentAccumulator
{
    /** @var list<string> */
    private array $chunks = [];

    private int $bytes = 0;

    private int $observed = 0;

    private bool $truncated = false;

    public function __construct(
        private readonly bool $enabled,
        private readonly int $maxBytes,
        private readonly int $maxChunks,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function append(array $event): void
    {
        if (! $this->enabled) {
            return;
        }
        $this->observed++;
        if ($this->truncated) {
            return;
        }
        if (count($this->chunks) >= $this->maxChunks) {
            $this->truncated = true;

            return;
        }
        $encoded = json_encode($event, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }
        if ($this->bytes + strlen($encoded) > $this->maxBytes) {
            $this->truncated = true;

            return;
        }
        $this->chunks[] = $encoded;
        $this->bytes += strlen($encoded);
    }

    public function observedChunks(): int
    {
        return $this->observed;
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }

    public function isEmpty(): bool
    {
        return $this->chunks === [];
    }

    /**
     * Normalized transcript of the stream: one JSON event per line, matching
     * the order the client received them.
     */
    public function transcript(): ?string
    {
        if (! $this->enabled || $this->chunks === []) {
            return null;
        }

        $transcript = implode("\n", $this->chunks);
        if ($this->truncated) {
            $transcript .= "\n".config('telemetry.content.truncation_marker');
        }

        return $transcript;
    }
}
