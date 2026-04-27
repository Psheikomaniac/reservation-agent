<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AI\OpenAiAuthenticationException;
use App\Exceptions\AI\OpenAiRateLimitException;
use App\Services\AI\Contracts\ReplyGenerator;
use OpenAI\Contracts\ClientContract;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stateless service that turns a deterministic Context-JSON (produced by
 * `ReservationContextBuilder`) into a German reply text via OpenAI Chat
 * Completions.
 *
 * Error matrix (PRD-005 / #71):
 *   - HTTP 401 or RateLimit/Server errors are classified as
 *     `OpenAiAuthenticationException` / `OpenAiRateLimitException` and
 *     RETHROWN so the job layer can drive the admin notification (#76)
 *     and the one-retry policy.
 *   - Every other error path — empty completion, network failure, 5xx,
 *     timeout, malformed payload — returns the neutral fallback so the
 *     caller never raises.
 *
 * Logging hygiene: only `$e->getMessage()` is logged. No API key, no
 * Authorization header, no full context payload, no guest data.
 */
final class OpenAiReplyGenerator implements ReplyGenerator
{
    public const string FALLBACK_TEXT = 'Vielen Dank für Ihre Anfrage. Wir melden uns in Kürze persönlich bei Ihnen.';

    private const float TEMPERATURE = 0.4;

    public function __construct(
        private readonly ClientContract $client,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $context  the JSON produced by
     *                                         ReservationContextBuilder::build()
     */
    public function generate(array $context): string
    {
        try {
            $tonality = $this->extractTonality($context);

            $response = $this->client->chat()->create([
                'model' => config('reservations.ai.openai_model', 'gpt-4o-mini'),
                'temperature' => self::TEMPERATURE,
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt($tonality)],
                    ['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
                ],
            ]);

            $content = trim((string) ($response->choices[0]->message->content ?? ''));

            return $content !== '' ? $content : self::FALLBACK_TEXT;
        } catch (ErrorException $e) {
            $this->logger->warning('openai reply generation failed', [
                'status' => $e->getStatusCode(),
                'error' => $e->getErrorMessage(),
            ]);

            if ($e->getStatusCode() === 401) {
                throw new OpenAiAuthenticationException;
            }

            return self::FALLBACK_TEXT;
        } catch (RateLimitException $e) {
            $this->logger->warning('openai reply generation rate-limited', [
                'error' => $e->getMessage(),
            ]);

            throw new OpenAiRateLimitException;
        } catch (Throwable $e) {
            $this->logger->warning('openai reply generation failed', [
                'error' => $e->getMessage(),
            ]);

            return self::FALLBACK_TEXT;
        }
    }

    /**
     * Build the system prompt from the tonality block plus the constant
     * rules that apply regardless of tonality (German-only, no invented
     * numbers, salutation/sign-off, etc.).
     */
    private function systemPrompt(string $tonality): string
    {
        /** @var array<string, string> $tonalityPrompts */
        $tonalityPrompts = config('reservations.ai.tonality_prompts', []);
        /** @var list<string> $rules */
        $rules = config('reservations.ai.system_prompt_rules', []);

        $tonalityBlock = $tonalityPrompts[$tonality] ?? '';
        $rulesBlock = implode("\n", array_map(static fn (string $rule): string => '- '.$rule, $rules));

        return trim($tonalityBlock."\n\n".$rulesBlock);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function extractTonality(array $context): string
    {
        $tonality = $context['restaurant']['tonality'] ?? null;

        return is_string($tonality) ? $tonality : 'casual';
    }
}
