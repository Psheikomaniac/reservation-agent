<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Events\OpenAiAuthenticationFailed;
use App\Listeners\RecordOpenAiKeyRejected;
use App\Support\OpenAiKeyHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PerRestaurantKeyHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_is_scoped_per_restaurant(): void
    {
        OpenAiKeyHealth::flagAsRejected(7);

        $this->assertNotNull(OpenAiKeyHealth::rejectedAt(7));
        $this->assertNull(OpenAiKeyHealth::rejectedAt(8));
        $this->assertNull(OpenAiKeyHealth::rejectedAt()); // global key unaffected

        OpenAiKeyHealth::clear(7);
        $this->assertNull(OpenAiKeyHealth::rejectedAt(7));
    }

    public function test_the_listener_flags_the_event_restaurant(): void
    {
        (new RecordOpenAiKeyRejected)->handle(new OpenAiAuthenticationFailed(42));

        $this->assertNotNull(OpenAiKeyHealth::rejectedAt(42));
        $this->assertNull(OpenAiKeyHealth::rejectedAt(43));
    }
}
