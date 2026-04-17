<?php

namespace Tests\Feature\Models;

use App\Enums\Tonality;
use App\Models\Restaurant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RestaurantTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_valid_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->assertTrue($restaurant->exists);
        $this->assertNotEmpty($restaurant->name);
        $this->assertNotEmpty($restaurant->slug);
        $this->assertSame('Europe/Berlin', $restaurant->timezone);
        $this->assertIsInt($restaurant->capacity);
        $this->assertInstanceOf(Tonality::class, $restaurant->tonality);
    }

    public function test_opening_hours_are_cast_to_an_array(): void
    {
        $hours = [
            'mon' => [['from' => '11:30', 'to' => '14:30']],
            'tue' => [],
            'wed' => [['from' => '18:00', 'to' => '22:00']],
            'thu' => [],
            'fri' => [['from' => '18:00', 'to' => '23:00']],
            'sat' => [['from' => '11:30', 'to' => '15:00'], ['from' => '18:00', 'to' => '23:00']],
            'sun' => [],
        ];

        $restaurant = Restaurant::factory()->create(['opening_hours' => $hours]);

        $this->assertSame($hours, $restaurant->fresh()->opening_hours);
    }

    public function test_imap_password_is_encrypted_at_rest_and_decrypted_on_read(): void
    {
        $secret = 'super-secret-imap-password-123';

        $restaurant = Restaurant::factory()->create(['imap_password' => $secret]);

        $rawValue = DB::table('restaurants')
            ->where('id', $restaurant->id)
            ->value('imap_password');

        $this->assertNotSame($secret, $rawValue, 'imap_password must not be stored in cleartext');
        $this->assertSame($secret, Crypt::decryptString($rawValue));
        $this->assertSame($secret, $restaurant->fresh()->imap_password);
    }

    public function test_tonality_is_cast_to_the_enum(): void
    {
        $restaurant = Restaurant::factory()->create(['tonality' => Tonality::Formal]);

        $this->assertSame(Tonality::Formal, $restaurant->fresh()->tonality);
        $this->assertSame('formal', DB::table('restaurants')->where('id', $restaurant->id)->value('tonality'));
    }

    public function test_slug_must_be_unique(): void
    {
        Restaurant::factory()->create(['slug' => 'bella-italia']);

        $this->expectException(QueryException::class);

        Restaurant::factory()->create(['slug' => 'bella-italia']);
    }

    public function test_imap_password_is_hidden_from_array_serialization(): void
    {
        $restaurant = Restaurant::factory()->create(['imap_password' => 'sensitive']);

        $this->assertArrayNotHasKey('imap_password', $restaurant->toArray());
    }

    public function test_timezone_defaults_to_europe_berlin_when_not_provided(): void
    {
        DB::table('restaurants')->insert([
            'name' => 'Test Restaurant',
            'slug' => 'test-restaurant',
            'capacity' => 20,
            'opening_hours' => json_encode([]),
            'tonality' => Tonality::Casual->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('Europe/Berlin', Restaurant::query()->sole()->timezone);
    }

    public function test_null_imap_password_is_handled_gracefully(): void
    {
        $restaurant = Restaurant::factory()->create(['imap_password' => null]);

        $this->assertNull($restaurant->fresh()->imap_password);
        $this->assertNull(DB::table('restaurants')->where('id', $restaurant->id)->value('imap_password'));
    }
}
