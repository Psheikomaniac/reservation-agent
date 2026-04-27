<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PerformanceBenchmarkSeeder extends Seeder
{
    /**
     * Restaurants over which the requests are distributed. Five tenants give
     * enough cardinality to verify the composite (restaurant_id, status,
     * created_at) index is useful while staying realistic for V1.0 (1–5
     * locations per pilot).
     */
    public const int RESTAURANT_COUNT = 5;

    /**
     * Total number of reservation_requests rows the seeder produces. Matches
     * the >10k target from issue #61.
     */
    public const int RESERVATION_COUNT = 10_000;

    private const int CHUNK_SIZE = 500;

    /**
     * Override the row count for a single run — used by tests that exercise
     * the seeder logic without paying the memory cost of inserting 10k rows.
     */
    public ?int $count = null;

    /**
     * Status mix roughly mirroring a mature dashboard: most rows are settled
     * (replied / confirmed / declined), with a steady backlog of new and
     * in_review. Numbers are weights, not percentages.
     *
     * @var array<string, int>
     */
    private const array STATUS_MIX = [
        ReservationStatus::New->value => 25,
        ReservationStatus::InReview->value => 15,
        ReservationStatus::Replied->value => 25,
        ReservationStatus::Confirmed->value => 25,
        ReservationStatus::Declined->value => 10,
    ];

    public function run(): void
    {
        $restaurantIds = $this->ensureRestaurants();
        $statusBag = $this->expandStatusMix();

        $batch = [];
        $now = Carbon::now();
        $target = $this->count ?? self::RESERVATION_COUNT;

        for ($i = 0; $i < $target; $i++) {
            $createdAt = $now->copy()->subMinutes(random_int(0, 90 * 24 * 60));
            $desiredAt = $createdAt->copy()->addDays(random_int(0, 30))->setTime(
                random_int(11, 22),
                random_int(0, 1) === 0 ? 0 : 30,
            );

            $batch[] = [
                'restaurant_id' => $restaurantIds[$i % count($restaurantIds)],
                'source' => $i % 3 === 0 ? ReservationSource::Email->value : ReservationSource::WebForm->value,
                'status' => $statusBag[$i % count($statusBag)],
                'guest_name' => 'Benchmark Guest '.$i,
                'guest_email' => 'guest'.$i.'@benchmark.test',
                'guest_phone' => null,
                'party_size' => random_int(1, 8),
                'desired_at' => $desiredAt->toDateTimeString(),
                'message' => null,
                'raw_payload' => null,
                'needs_manual_review' => $i % 50 === 0,
                'email_message_id' => null,
                'created_at' => $createdAt->toDateTimeString(),
                'updated_at' => $createdAt->toDateTimeString(),
            ];

            if (count($batch) >= self::CHUNK_SIZE) {
                DB::table('reservation_requests')->insert($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            DB::table('reservation_requests')->insert($batch);
        }
    }

    /**
     * @return list<int>
     */
    private function ensureRestaurants(): array
    {
        $existing = Restaurant::query()
            ->where('slug', 'like', 'benchmark-restaurant-%')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $needed = self::RESTAURANT_COUNT - count($existing);

        if ($needed <= 0) {
            return array_slice($existing, 0, self::RESTAURANT_COUNT);
        }

        $created = Restaurant::factory()
            ->count($needed)
            ->sequence(fn ($sequence) => [
                'slug' => 'benchmark-restaurant-'.($sequence->index + count($existing) + 1).'-'.Str::lower(Str::random(4)),
            ])
            ->create()
            ->pluck('id')
            ->all();

        return [...$existing, ...$created];
    }

    /**
     * Expand the weighted status mix into a flat array used for round-robin
     * assignment. Cheaper than calling `random_int` per row and produces a
     * stable distribution across runs.
     *
     * @return list<string>
     */
    private function expandStatusMix(): array
    {
        $bag = [];

        foreach (self::STATUS_MIX as $status => $weight) {
            for ($i = 0; $i < $weight; $i++) {
                $bag[] = $status;
            }
        }

        shuffle($bag);

        return $bag;
    }
}
