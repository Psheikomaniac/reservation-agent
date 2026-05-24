<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\Tonality;
use App\Http\Requests\Onboarding\OpeningHoursRequest;
use App\Http\Requests\Onboarding\TonalityRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class OnboardingFormRequestsTest extends TestCase
{
    private function fails(array $rules, array $data): bool
    {
        return Validator::make($data, $rules)->fails();
    }

    public function test_opening_hours_accepts_a_valid_schedule(): void
    {
        $this->assertFalse($this->fails(
            (new OpeningHoursRequest)->rules(),
            ['opening_hours' => ['mon' => [['from' => '11:30', 'to' => '14:30']], 'tue' => []]],
        ));
    }

    public function test_opening_hours_rejects_bad_time(): void
    {
        $this->assertTrue($this->fails(
            (new OpeningHoursRequest)->rules(),
            ['opening_hours' => ['mon' => [['from' => '25:00', 'to' => '14:30']]]],
        ));
    }

    public function test_opening_hours_rejects_an_unknown_day(): void
    {
        $this->assertTrue($this->fails(
            (new OpeningHoursRequest)->rules(),
            ['opening_hours' => ['funday' => [['from' => '10:00', 'to' => '12:00']]]],
        ));
    }

    public function test_opening_hours_requires_at_least_one_open_block(): void
    {
        $this->assertTrue($this->fails(
            (new OpeningHoursRequest)->rules(),
            ['opening_hours' => ['mon' => [], 'tue' => []]],
        ));
    }

    public function test_tonality_rejects_an_invalid_value(): void
    {
        $rules = (new TonalityRequest)->rules();

        $this->assertFalse($this->fails($rules, ['tonality' => Tonality::Casual->value]));
        $this->assertTrue($this->fails($rules, ['tonality' => 'screaming']));
    }
}
