<?php

namespace Database\Seeders;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\Tonality;
use App\Enums\UserRole;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed a deterministic demo tenant for local development.
     *
     * Idempotent: re-running the seeder reuses the existing restaurant, owner
     * and reservations (matched by slug / email / guest_email + desired_at).
     */
    public function run(): void
    {
        $restaurant = Restaurant::firstOrCreate(
            ['slug' => 'demo-restaurant'],
            [
                'name' => 'Demo Restaurant',
                'timezone' => 'Europe/Berlin',
                'capacity' => 40,
                'opening_hours' => [
                    'mon' => [['from' => '11:30', 'to' => '14:30'], ['from' => '18:00', 'to' => '22:30']],
                    'tue' => [],
                    'wed' => [['from' => '11:30', 'to' => '14:30'], ['from' => '18:00', 'to' => '22:30']],
                    'thu' => [['from' => '11:30', 'to' => '14:30'], ['from' => '18:00', 'to' => '22:30']],
                    'fri' => [['from' => '11:30', 'to' => '14:30'], ['from' => '18:00', 'to' => '23:00']],
                    'sat' => [['from' => '18:00', 'to' => '23:00']],
                    'sun' => [['from' => '11:30', 'to' => '15:00']],
                ],
                'tonality' => Tonality::Casual,
            ]
        );

        User::firstOrCreate(
            ['email' => 'owner@demo.test'],
            [
                'name' => 'Demo Owner',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'restaurant_id' => $restaurant->id,
                'role' => UserRole::Owner,
            ]
        );

        $demoRequests = [
            [
                'status' => ReservationStatus::New,
                'guest_name' => 'Anna Becker',
                'guest_email' => 'anna.becker@demo.test',
                'party_size' => 2,
                'desired_at' => now()->addDays(1)->setTime(19, 0),
                'message' => 'Gern am Fenster, falls möglich.',
            ],
            [
                'status' => ReservationStatus::InReview,
                'guest_name' => 'Familie Huber',
                'guest_email' => 'familie.huber@demo.test',
                'party_size' => 5,
                'desired_at' => now()->addDays(3)->setTime(18, 30),
                'message' => 'Kinderstuhl benötigt.',
            ],
            [
                'status' => ReservationStatus::Confirmed,
                'guest_name' => 'Klaus Schmidt',
                'guest_email' => 'klaus.schmidt@demo.test',
                'party_size' => 4,
                'desired_at' => now()->addDays(7)->setTime(20, 0),
                'message' => null,
            ],
        ];

        foreach ($demoRequests as $data) {
            $exists = ReservationRequest::query()
                ->withoutGlobalScopes()
                ->where('restaurant_id', $restaurant->id)
                ->where('guest_email', $data['guest_email'])
                ->exists();

            if ($exists) {
                continue;
            }

            ReservationRequest::factory()
                ->forRestaurant($restaurant)
                ->create([
                    ...$data,
                    'source' => ReservationSource::WebForm,
                ]);
        }
    }
}
