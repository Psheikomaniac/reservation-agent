<?php

declare(strict_types=1);

namespace App\Services\AI\Contracts;

/**
 * Producer of a German reply text from a deterministic Context-JSON.
 *
 * Implementations of `generate` MUST never throw; on every error path they
 * return the neutral fallback string. This contract is the seam used by the
 * job layer (and tests) to substitute in a real OpenAI client, a stub, or a
 * future provider (Azure / Anthropic) without changing call sites.
 *
 * Concrete classes may expose additional, non-interface methods with their
 * own contracts — e.g. `OpenAiReplyGenerator::generateSync` (PRD-014), which
 * deliberately throws on every failure. Such methods are outside this
 * never-throw guarantee and are not part of the provider-swap seam.
 */
interface ReplyGenerator
{
    /**
     * @param  array<string, mixed>  $context  the JSON produced by
     *                                         ReservationContextBuilder::build()
     */
    public function generate(array $context): string;
}
