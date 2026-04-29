<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Services\AI\AutoSendDecision;
use InvalidArgumentException;
use Tests\TestCase;

class AutoSendDecisionTest extends TestCase
{
    public function test_static_factory_constructs_each_decision_kind(): void
    {
        $manual = AutoSendDecision::manual('operator chose manual mode');
        $shadow = AutoSendDecision::shadow('shadow mode is active for this restaurant');
        $auto = AutoSendDecision::autoSend('all hard-gates passed');

        $this->assertSame(AutoSendDecision::DECISION_MANUAL, $manual->decision);
        $this->assertSame(AutoSendDecision::DECISION_SHADOW, $shadow->decision);
        $this->assertSame(AutoSendDecision::DECISION_AUTO_SEND, $auto->decision);

        $this->assertSame('operator chose manual mode', $manual->reason);
        $this->assertSame('shadow mode is active for this restaurant', $shadow->reason);
        $this->assertSame('all hard-gates passed', $auto->reason);
    }

    public function test_constructor_rejects_unknown_decision(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AutoSendDecision('unknown_decision', 'irrelevant');
    }

    public function test_constructor_rejects_empty_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AutoSendDecision::manual('   ');
    }

    public function test_serialization_roundtrip_preserves_decision_and_reason(): void
    {
        $original = AutoSendDecision::shadow('shadow mode active');

        $payload = $original->toArray();
        $rehydrated = AutoSendDecision::fromArray($payload);

        $this->assertSame($original->decision, $rehydrated->decision);
        $this->assertSame($original->reason, $rehydrated->reason);
    }

    public function test_from_array_rejects_payloads_missing_decision_or_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AutoSendDecision::fromArray(['decision' => 'manual']);
    }

    public function test_from_array_rejects_non_string_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AutoSendDecision::fromArray(['decision' => 123, 'reason' => 'x']);
    }

    public function test_full_json_column_roundtrip_preserves_value_object(): void
    {
        $original = AutoSendDecision::autoSend('all hard-gates passed');

        $encoded = json_encode($original->toArray(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        $rehydrated = AutoSendDecision::fromArray($decoded);

        $this->assertSame($original->decision, $rehydrated->decision);
        $this->assertSame($original->reason, $rehydrated->reason);
    }
}
