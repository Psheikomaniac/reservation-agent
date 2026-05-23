<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AI\OpenAiAuthenticationException;
use App\Exceptions\AI\OpenAiRateLimitException;
use App\Exceptions\AI\OpenAiTimeoutException;
use App\Services\AI\Contracts\ReplyGenerator;
use App\Support\OpenAiKeyHealth;
use Closure;
use OpenAI\Contracts\ClientContract;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\TransporterException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Stateless service that turns a deterministic Context-JSON (produced by
 * `ReservationContextBuilder`) into a German reply text via OpenAI Chat
 * Completions.
 *
 * Async `generate` error matrix (PRD-005 / #71):
 *   - HTTP 401 or RateLimit/Server errors are classified as
 *     `OpenAiAuthenticationException` / `OpenAiRateLimitException` and
 *     RETHROWN so the job layer can drive the admin notification (#76)
 *     and the one-retry policy.
 *   - Every other error path — empty completion, network failure, 5xx,
 *     timeout, malformed payload — returns the neutral fallback so the
 *     caller never raises.
 *
 * The sync `generateSync` (PRD-014) has the OPPOSITE error contract: a
 * web sync-confirm must carry a real AI reply, so it NEVER returns the
 * fallback and instead throws on every failure, letting the caller fall
 * back to the V1 path. See its docblock for the per-error mapping.
 *
 * Logging hygiene: only the exception message (and, for classified HTTP
 * errors, the numeric status code) is logged. No API key, no
 * Authorization header, no full context payload, no guest data.
 */
final class OpenAiReplyGenerator implements ReplyGenerator
{
    public const string FALLBACK_TEXT = 'Vielen Dank für Ihre Anfrage. Wir melden uns in Kürze persönlich bei Ihnen.';

    private const float TEMPERATURE = 0.4;

    /**
     * @param  (Closure(int): ClientContract)|null  $syncClientFactory
     *                                                                  Builds a client whose HTTP timeout equals the sync hard-limit
     *                                                                  (PRD-014). Bound in production so `generateSync` can run a
     *                                                                  5 s-bounded call; left null in unit tests, where the injected
     *                                                                  client (a fake) is used directly so `OpenAI::fake()` applies.
     *                                                                  The async `generate` always uses the default 30 s `$client`.
     */
    public function __construct(
        private readonly ClientContract $client,
        private readonly LoggerInterface $logger,
        private readonly ?Closure $syncClientFactory = null,
    ) {}

    /**
     * @param  array<string, mixed>  $context  the JSON produced by
     *                                         ReservationContextBuilder::build()
     */
    public function generate(array $context): string
    {
        try {
            $response = $this->client->chat()->create($this->chatCreatePayload($context));

            $content = trim((string) ($response->choices[0]->message->content ?? ''));

            // Reaching this point means the call authenticated successfully —
            // auto-clear the admin "OpenAI key check" banner (#76) so the
            // dashboard doesn't strand stale alerts after a key rotation.
            OpenAiKeyHealth::clear();

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
     * Synchronous reply generation for the PRD-014 web sync-confirm path.
     *
     * Runs inside the public submit latency with a hard `$timeout`-second
     * budget enforced by the `syncClientFactory` client. Unlike `generate`,
     * it NEVER returns the neutral fallback: a confirmation must carry a
     * real AI reply, so every failure throws and the caller falls back to
     * the V1 path.
     *
     *   - transport failure / 5 s timeout → `OpenAiTimeoutException`
     *   - empty completion → `RuntimeException`
     *   - 401 / 429 / 5xx → the underlying OpenAI exception propagates
     *
     * Error mapping (every branch is logged and then thrown — the sync path
     * never returns the fallback):
     *   - 401 → `OpenAiAuthenticationException` (classified like `generate`,
     *     so the caller can surface the admin key-check notification)
     *   - 429 → `OpenAiRateLimitException`
     *   - transport failure / 5 s timeout → `OpenAiTimeoutException`
     *   - 5xx, empty completion, malformed payload → the originating
     *     exception propagates
     *
     * Logging hygiene matches `generate`: only the exception message (and,
     * for classified HTTP errors, the numeric status) is logged — never the
     * API key, headers, context payload, or guest data.
     *
     * @param  array<string, mixed>  $context  the JSON produced by
     *                                         ReservationContextBuilder::build()
     */
    public function generateSync(array $context, int $timeout = 5): string
    {
        $client = $this->syncClientFactory !== null
            ? ($this->syncClientFactory)($timeout)
            : $this->client;

        try {
            $response = $client->chat()->create($this->chatCreatePayload($context));

            $content = trim((string) ($response->choices[0]->message->content ?? ''));

            if ($content === '') {
                throw new RuntimeException('OpenAI returned an empty completion for the sync reply.');
            }

            // Successful authenticated call — clear the admin "OpenAI key
            // check" banner (#76), mirroring `generate`.
            OpenAiKeyHealth::clear();

            return $content;
        } catch (ErrorException $e) {
            $this->logger->warning('openai sync reply generation failed', [
                'status' => $e->getStatusCode(),
                'error' => $e->getErrorMessage(),
            ]);

            if ($e->getStatusCode() === 401) {
                throw new OpenAiAuthenticationException;
            }

            throw $e;
        } catch (RateLimitException $e) {
            $this->logger->warning('openai sync reply generation rate-limited', [
                'error' => $e->getMessage(),
            ]);

            throw new OpenAiRateLimitException;
        } catch (TransporterException $e) {
            // Guzzle connection timeout / network failure within the 5 s
            // budget surfaces here (openai-php wraps it).
            $this->logger->warning('openai sync reply generation timed out', [
                'error' => $e->getMessage(),
            ]);

            throw new OpenAiTimeoutException(previous: $e);
        } catch (Throwable $e) {
            // 5xx, empty completion, malformed payload — log for parity with
            // `generate`, then propagate so the caller falls back to V1
            // (never the neutral fallback text).
            $this->logger->warning('openai sync reply generation failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * The Chat Completions request body shared by the async `generate` and
     * the sync `generateSync`: same model, temperature, system prompt and
     * the deterministic context JSON as the user message.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function chatCreatePayload(array $context): array
    {
        $tonality = $this->extractTonality($context);

        return [
            'model' => config('reservations.ai.openai_model', 'gpt-4o-mini'),
            'temperature' => self::TEMPERATURE,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt($tonality)],
                ['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
            ],
        ];
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
