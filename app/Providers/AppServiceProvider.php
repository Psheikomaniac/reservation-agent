<?php

namespace App\Providers;

use App\Services\AI\Contracts\ReplyGenerator;
use App\Services\AI\OpenAiReplyGenerator;
use App\Services\Email\Contracts\ImapMailboxFactory;
use App\Services\Email\WebklexImapMailboxFactory;
use App\Services\Exports\Contracts\ExportGenerator;
use App\Services\Exports\FormatRoutingExportGenerator;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\ServiceProvider;
use OpenAI\Contracts\ClientContract;
use OpenAI\Laravel\Exceptions\ApiKeyIsMissing;
use Psr\Log\LoggerInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ImapMailboxFactory::class, WebklexImapMailboxFactory::class);
        $this->app->bind(ExportGenerator::class, FormatRoutingExportGenerator::class);

        // The generator needs two timeout regimes: the default 30 s client
        // for the async job (`generate`), and a short-budget client for the
        // PRD-014 sync-confirm path (`generateSync`). The factory builds the
        // latter on demand with the requested timeout.
        $this->app->bind(OpenAiReplyGenerator::class, fn ($app): OpenAiReplyGenerator => new OpenAiReplyGenerator(
            $app->make(ClientContract::class),
            $app->make(LoggerInterface::class),
            fn (int $timeout): ClientContract => $this->buildTimeoutBoundOpenAiClient($timeout),
        ));
        $this->app->bind(ReplyGenerator::class, OpenAiReplyGenerator::class);
    }

    /**
     * Build an OpenAI client whose HTTP timeout is `$timeout` seconds,
     * mirroring the openai-php Laravel ServiceProvider construction but with
     * the sync hard-limit instead of the global `openai.request_timeout`.
     */
    private function buildTimeoutBoundOpenAiClient(int $timeout): ClientContract
    {
        $apiKey = config('openai.api_key');
        $organization = config('openai.organization');
        $project = config('openai.project');
        $baseUri = config('openai.base_uri');

        if (! is_string($apiKey) || ($organization !== null && ! is_string($organization))) {
            throw ApiKeyIsMissing::create();
        }

        $factory = \OpenAI::factory()
            ->withApiKey($apiKey)
            ->withOrganization($organization)
            ->withHttpClient(new GuzzleClient(['timeout' => $timeout]));

        if (is_string($project)) {
            $factory->withProject($project);
        }

        if (is_string($baseUri)) {
            $factory->withBaseUri($baseUri);
        }

        return $factory->make();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
