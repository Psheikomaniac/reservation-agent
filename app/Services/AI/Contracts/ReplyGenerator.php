<?php

declare(strict_types=1);

namespace App\Services\AI\Contracts;

/**
 * Producer of a German reply text from a deterministic Context-JSON.
 *
 * Implementations MUST never throw; on every error path they return the
 * neutral fallback string. This contract is the seam used by the job
 * layer (and tests) to substitute in a real OpenAI client, a stub, or a
 * future provider (Azure / Anthropic) without changing call sites.
 */
interface ReplyGenerator
{
    /**
     * @param  array<string, mixed>  $context  the JSON produced by
     *                                         ReservationContextBuilder::build()
     */
    public function generate(array $context): string;
}
