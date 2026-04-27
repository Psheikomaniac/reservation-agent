<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Domain event emitted when the configured OpenAI API key is rejected
 * (HTTP 401). The dashboard admin notification (issue #76) listens for
 * this event to surface "OpenAI key check" to operators.
 */
final class OpenAiAuthenticationFailed
{
    use Dispatchable;
}
