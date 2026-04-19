<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Grep invariant: no logging call under app/ may carry password material.
 *
 * Guarantees we never accidentally feed the IMAP password — or any other
 * "password" field — into Log::*, logger() or $logger->{level}() context
 * arrays. Regression guard for PRD-003 / issue #34.
 */
class ImapPasswordLogHygieneTest extends TestCase
{
    public function test_no_logging_call_in_app_contains_password_material(): void
    {
        $offences = [];

        foreach ($this->phpFilesUnder(__DIR__.'/../../../app') as $path) {
            $source = (string) file_get_contents($path);

            foreach ($this->extractLoggingCalls($source) as $call) {
                if ($this->mentionsPassword($call['text'])) {
                    $offences[] = sprintf(
                        '%s:%d – logging call contains password material: %s',
                        $path,
                        $call['line'],
                        $this->compact($call['text']),
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $offences,
            "Logging calls must never reference password fields:\n".implode("\n", $offences),
        );
    }

    /**
     * @return iterable<string>
     */
    private function phpFilesUnder(string $directory): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    /**
     * Extracts every logging invocation from $source with balanced parentheses.
     *
     * @return list<array{text: string, line: int}>
     */
    private function extractLoggingCalls(string $source): array
    {
        $pattern = '/(?:Log::(?:error|warning|info|debug|notice|critical|alert|emergency|log)'
            .'|logger\s*\('
            .'|->(?:error|warning|info|debug|notice|critical|alert|emergency|log)\s*\()/';

        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $calls = [];
        foreach ($matches[0] as [$_, $offset]) {
            $open = strpos($source, '(', $offset);
            if ($open === false) {
                continue;
            }

            $depth = 0;
            $length = strlen($source);
            for ($i = $open; $i < $length; $i++) {
                $char = $source[$i];
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $text = substr($source, $offset, $i - $offset + 1);
                        $calls[] = [
                            'text' => $text,
                            'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                        ];
                        break;
                    }
                }
            }
        }

        return $calls;
    }

    private function mentionsPassword(string $call): bool
    {
        return preg_match('/password/i', $call) === 1;
    }

    private function compact(string $text): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $text) ?? $text;

        return strlen($collapsed) > 160 ? substr($collapsed, 0, 157).'...' : $collapsed;
    }
}
