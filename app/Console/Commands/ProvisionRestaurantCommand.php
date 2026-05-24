<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RestaurantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class ProvisionRestaurantCommand extends Command
{
    protected $signature = 'restaurant:provision
        {--name= : Restaurant display name}
        {--slug= : Public URL slug (unique)}
        {--email= : Owner email address (unique)}
        {--timezone=Europe/Berlin : IANA timezone}';

    protected $description = 'Create a restaurant, its owner and an owner invitation, and print the acceptance link.';

    public function handle(RestaurantProvisioner $provisioner): int
    {
        $name = (string) $this->option('name');
        $slug = (string) $this->option('slug');
        $email = (string) $this->option('email');
        $timezone = (string) $this->option('timezone');

        if ($name === '' || $slug === '' || $email === '') {
            $this->error('--name, --slug and --email are required.');

            return self::FAILURE;
        }

        try {
            $result = $provisioner->provision($name, $slug, $email, $timezone);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $link = route('onboarding.accept', ['token' => $result->plainToken]);

        $this->info(sprintf('Provisioned "%s" (#%d).', $result->restaurant->name, $result->restaurant->id));
        $this->line('Owner: '.$result->owner->email);
        $this->newLine();
        $this->line('Acceptance link (valid 7 days, shown once):');
        $this->line($link);

        return self::SUCCESS;
    }
}
