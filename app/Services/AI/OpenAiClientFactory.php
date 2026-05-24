<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Restaurant;
use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use OpenAI\Contracts\ClientContract;
use OpenAI\Laravel\Exceptions\ApiKeyIsMissing;

/**
 * Builds an OpenAI client for a given restaurant, using its own BYOK key when
 * set and falling back to the global `.env` key otherwise. Mirrors the
 * openai-php/laravel ServiceProvider build so org/project/base-uri stay
 * honoured, and accepts an optional per-call timeout for the sync path.
 */
final class OpenAiClientFactory
{
    public function resolveKey(?Restaurant $restaurant): ?string
    {
        $perRestaurant = $restaurant?->openai_api_key;
        if (is_string($perRestaurant) && $perRestaurant !== '') {
            return $perRestaurant;
        }

        $global = config('openai.api_key');

        return is_string($global) ? $global : null;
    }

    public function clientFor(?Restaurant $restaurant, ?int $timeout = null): ClientContract
    {
        $apiKey = $this->resolveKey($restaurant);
        $organization = config('openai.organization');
        $project = config('openai.project');
        $baseUri = config('openai.base_uri');

        if (! is_string($apiKey) || ($organization !== null && ! is_string($organization))) {
            throw ApiKeyIsMissing::create();
        }

        $factory = OpenAI::factory()
            ->withApiKey($apiKey)
            ->withOrganization($organization)
            ->withHttpClient(new GuzzleClient([
                'timeout' => $timeout ?? config('openai.request_timeout', 30),
            ]));

        if (is_string($project)) {
            $factory->withProject($project);
        }

        if (is_string($baseUri)) {
            $factory->withBaseUri($baseUri);
        }

        return $factory->make();
    }
}
