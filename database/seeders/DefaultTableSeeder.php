<?php

namespace Database\Seeders;

use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\Table;
use Illuminate\Database\Seeder;

class DefaultTableSeeder extends Seeder
{
    private const int FALLBACK_PARTY_SIZE = 4;

    private const int SEAT_HEADROOM = 4;

    public function run(): void
    {
        Restaurant::query()
            ->whereDoesntHave('tables')
            ->each(function (Restaurant $restaurant): void {
                $maxParty = (int) (ReservationRequest::query()
                    ->withoutGlobalScopes()
                    ->where('restaurant_id', $restaurant->id)
                    ->max('party_size') ?? self::FALLBACK_PARTY_SIZE);

                Table::create([
                    'restaurant_id' => $restaurant->id,
                    'label' => 'Tisch 1',
                    'seats' => $maxParty + self::SEAT_HEADROOM,
                    'sort_order' => 1,
                    'active' => true,
                ]);
            });
    }
}
