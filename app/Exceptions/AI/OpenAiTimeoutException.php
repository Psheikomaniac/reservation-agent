<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

use RuntimeException;

/**
 * Signals that the synchronous reply generation (PRD-014 web sync-confirm)
 * exceeded its hard timeout budget or otherwise failed transport-side.
 *
 * The sync path runs inside the public submit latency, so the caller
 * catches this and falls back to the V1 path (`status = new`, normal
 * owner review) instead of surfacing a UI error. Unlike the async
 * `generate`, the sync path never returns the neutral fallback text — a
 * confirmation must always carry a real AI reply.
 */
final class OpenAiTimeoutException extends RuntimeException
{
    public function __construct(string $message = 'OpenAI sync reply generation timed out.')
    {
        parent::__construct($message);
    }
}
