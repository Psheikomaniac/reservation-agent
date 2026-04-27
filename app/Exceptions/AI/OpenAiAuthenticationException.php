<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

use RuntimeException;

/**
 * Signals that OpenAI rejected the API key (HTTP 401). The job MUST NOT
 * retry — the only resolution is operator action — and SHOULD surface a
 * dashboard notification (see #76).
 */
final class OpenAiAuthenticationException extends RuntimeException
{
    public function __construct(string $message = 'OpenAI rejected the API key (HTTP 401).')
    {
        parent::__construct($message);
    }
}
