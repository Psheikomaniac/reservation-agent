<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RestaurantIntegrationColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_secret_columns_are_encrypted_at_rest_and_hidden(): void
    {
        $restaurant = Restaurant::factory()->create([
            'openai_api_key' => 'sk-secret-key',
            'smtp_password' => 'smtp-secret',
            'smtp_host' => 'smtp.bella.test',
            'smtp_port' => 587,
            'smtp_username' => 'mailer@bella.test',
            'smtp_from_address' => 'hallo@bella.test',
            'smtp_from_name' => 'Bella',
        ]);

        // Decrypts through the cast.
        $this->assertSame('sk-secret-key', $restaurant->fresh()->openai_api_key);
        $this->assertSame('smtp-secret', $restaurant->fresh()->smtp_password);
        $this->assertSame(587, $restaurant->fresh()->smtp_port);

        // Encrypted at rest.
        $raw = DB::table('restaurants')->where('id', $restaurant->id)->first();
        $this->assertNotSame('sk-secret-key', $raw->openai_api_key);
        $this->assertSame('sk-secret-key', Crypt::decryptString($raw->openai_api_key));
        $this->assertSame('smtp-secret', Crypt::decryptString($raw->smtp_password));

        // Never serialized.
        $array = $restaurant->toArray();
        $this->assertArrayNotHasKey('openai_api_key', $array);
        $this->assertArrayNotHasKey('smtp_password', $array);
    }

    public function test_secret_columns_default_to_null(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->assertNull($restaurant->openai_api_key);
        $this->assertNull($restaurant->smtp_host);
        $this->assertNull($restaurant->smtp_password);
    }
}
