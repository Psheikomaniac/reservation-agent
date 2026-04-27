<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Events\OpenAiAuthenticationFailed;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\AI\OpenAiReplyGenerator;
use App\Support\OpenAiKeyHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * PRD-005 / issue #76 — owner-only "OpenAI-Key prüfen" banner.
 *
 * The signal is global (V1.0 has one app-wide API key); the listener
 * stashes it to a cache flag, the dashboard surfaces it for users with
 * the Owner role only, and a successful OpenAI call auto-clears it.
 */
class OpenAi401AdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        OpenAiKeyHealth::clear();
    }

    public function test_listener_flags_the_key_as_rejected_when_event_fires(): void
    {
        $this->assertNull(OpenAiKeyHealth::rejectedAt());

        OpenAiAuthenticationFailed::dispatch();

        $this->assertNotNull(OpenAiKeyHealth::rejectedAt());
    }

    public function test_dashboard_surfaces_the_banner_for_owner_users(): void
    {
        OpenAiKeyHealth::flagAsRejected();

        $restaurant = Restaurant::factory()->create();
        $owner = User::factory()->create(['restaurant_id' => $restaurant->id, 'role' => UserRole::Owner]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('openaiKeyRejectedAt', fn ($v) => is_string($v)));
    }

    public function test_dashboard_does_not_surface_the_banner_for_staff_users(): void
    {
        OpenAiKeyHealth::flagAsRejected();

        $restaurant = Restaurant::factory()->create();
        $staff = User::factory()->create(['restaurant_id' => $restaurant->id, 'role' => UserRole::Staff]);

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('openaiKeyRejectedAt', null));
    }

    public function test_successful_generator_call_clears_the_flag(): void
    {
        OpenAiKeyHealth::flagAsRejected();
        $this->assertNotNull(OpenAiKeyHealth::rejectedAt());

        $fake = OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop'],
                ],
            ]),
        ]);

        (new OpenAiReplyGenerator($fake, new NullLogger))->generate([
            'restaurant' => ['name' => 'X', 'tonality' => 'casual'],
            'request' => ['guest_name' => 'X', 'party_size' => 2, 'desired_at' => '2026-05-13 19:00', 'message' => null],
            'availability' => [
                'is_open_at_desired_time' => true,
                'seats_free_at_desired' => 4,
                'alternative_slots' => [],
                'closed_reason' => null,
            ],
        ]);

        $this->assertNull(OpenAiKeyHealth::rejectedAt());
    }
}
