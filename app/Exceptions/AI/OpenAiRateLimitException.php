<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

use RuntimeException;

/**
 * Signals that OpenAI returned HTTP 429. The job retries exactly once
 * after a 60-second delay; if the second attempt is also rate-limited,
 * the job falls back as for any other failure.
 */
final class OpenAiRateLimitException extends RuntimeException
{
    public function __construct(string $message = 'OpenAI rate limit hit (HTTP 429).')
    {
        parent::__construct($message);
    }
}
