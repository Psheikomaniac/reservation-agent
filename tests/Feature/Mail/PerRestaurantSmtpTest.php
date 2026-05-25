<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Enums\ReservationReplyStatus;
use App\Jobs\SendReservationReplyJob;
use App\Mail\ReservationReplyMail;
use App\Mail\Support\RestaurantMailer;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class PerRestaurantSmtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reply_for_a_configured_restaurant_sends_via_its_mailer(): void
    {
        Mail::fake();

        $restaurant = Restaurant::factory()->create([
            'smtp_host' => 'smtp.bella.test',
            'smtp_port' => 587,
            'smtp_username' => 'mailer@bella.test',
            'smtp_password' => 'pw',
            'smtp_from_address' => 'hallo@bella.test',
            'smtp_from_name' => 'Bella',
        ]);
        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create(['guest_email' => 'guest@example.test']);
        $reply = ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => ReservationReplyStatus::Approved,
            'body' => 'Bestätigt.',
        ]);

        (new SendReservationReplyJob($reply->id))->handle();

        Mail::assertSent(ReservationReplyMail::class, fn (ReservationReplyMail $mail): bool => $mail->hasTo('guest@example.test'));
    }

    public function test_a_configured_restaurant_gets_its_own_mailer_and_from(): void
    {
        $restaurant = Restaurant::factory()->create([
            'smtp_host' => 'smtp.bella.test',
            'smtp_port' => 2525,
            'smtp_username' => 'mailer@bella.test',
            'smtp_password' => 'pw',
            'smtp_from_address' => 'hallo@bella.test',
            'smtp_from_name' => 'Bella',
        ]);

        $support = app(RestaurantMailer::class);
        $name = $support->resolve($restaurant);

        $this->assertSame('restaurant-'.$restaurant->id, $name);
        $this->assertSame('smtp.bella.test', config("mail.mailers.{$name}.host"));
        $this->assertSame(2525, config("mail.mailers.{$name}.port"));
        $this->assertSame('mailer@bella.test', config("mail.mailers.{$name}.username"));
        $this->assertSame(['address' => 'hallo@bella.test', 'name' => 'Bella'], $support->from($restaurant));
    }

    public function test_an_unconfigured_restaurant_uses_the_default_mailer_and_global_from(): void
    {
        config(['mail.from.address' => 'global@app.test', 'mail.from.name' => 'App']);
        $restaurant = Restaurant::factory()->create(['smtp_host' => null]);

        $support = app(RestaurantMailer::class);

        $this->assertNull($support->resolve($restaurant));
        $this->assertSame(['address' => 'global@app.test', 'name' => 'App'], $support->from($restaurant));
    }
}
