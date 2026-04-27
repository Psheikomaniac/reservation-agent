<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AppUrlMismatchWarningTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_warning_when_accessed_url_differs_from_app_url_in_local_env(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['app.url' => 'http://configured-host:1234']);
        Log::spy();

        $this->get('http://accessed-host:5678/');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'APP_URL mismatch')
                && ($context['configured_app_url'] ?? null) === 'http://configured-host:1234'
                && ($context['accessed_url'] ?? null) === 'http://accessed-host:5678'
            )
            ->atLeast()->once();
    }

    public function test_does_not_log_when_accessed_url_matches_app_url(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['app.url' => 'http://matching-host:8000']);
        Log::spy();

        $this->get('http://matching-host:8000/');

        Log::shouldNotHaveReceived('warning');
    }

    public function test_does_not_log_in_non_local_environments(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'http://configured-host:1234']);
        Log::spy();

        $this->get('http://accessed-host:5678/');

        Log::shouldNotHaveReceived('warning');
    }
}
