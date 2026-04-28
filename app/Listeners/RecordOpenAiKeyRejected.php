<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OpenAiAuthenticationFailed;
use App\Support\OpenAiKeyHealth;

/**
 * Auto-discovered listener for `OpenAiAuthenticationFailed`. The job
 * layer dispatches the event whenever OpenAI returns 401; this listener
 * persists the signal so the dashboard's owner-only banner can surface
 * it (issue #76).
 */
final class RecordOpenAiKeyRejected
{
    public function handle(OpenAiAuthenticationFailed $event): void
    {
        OpenAiKeyHealth::flagAsRejected();
    }
}
