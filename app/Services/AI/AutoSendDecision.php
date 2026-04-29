<?php

declare(strict_types=1);

namespace App\Services\AI;

use InvalidArgumentException;

/**
 * Outcome of the AutoSendDecider for a given draft (PRD-007).
 *
 * Stored on `reservation_replies.auto_send_decision` (json column) so the
 * dashboard can surface why a draft did or did not auto-send, and so the
 * audit trail can re-render historical decisions independent of any
 * later schema changes.
 */
final readonly class AutoSendDecision
{
    public const string DECISION_MANUAL = 'manual';

    public const string DECISION_SHADOW = 'shadow';

    public const string DECISION_AUTO_SEND = 'auto_send';

    private const array ALLOWED_DECISIONS = [
        self::DECISION_MANUAL,
        self::DECISION_SHADOW,
        self::DECISION_AUTO_SEND,
    ];

    public function __construct(
        public string $decision,
        public string $reason,
    ) {
        if (! in_array($decision, self::ALLOWED_DECISIONS, true)) {
            throw new InvalidArgumentException("Unknown AutoSendDecision: {$decision}");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('AutoSendDecision must carry a non-empty reason for the audit trail.');
        }
    }

    public static function manual(string $reason): self
    {
        return new self(self::DECISION_MANUAL, $reason);
    }

    public static function shadow(string $reason): self
    {
        return new self(self::DECISION_SHADOW, $reason);
    }

    public static function autoSend(string $reason): self
    {
        return new self(self::DECISION_AUTO_SEND, $reason);
    }

    /**
     * @return array{decision: string, reason: string}
     */
    public function toArray(): array
    {
        return [
            'decision' => $this->decision,
            'reason' => $this->reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $decision = $payload['decision'] ?? null;
        $reason = $payload['reason'] ?? null;

        if (! is_string($decision) || ! is_string($reason)) {
            throw new InvalidArgumentException('AutoSendDecision payload must contain string `decision` and `reason`.');
        }

        return new self($decision, $reason);
    }
}
