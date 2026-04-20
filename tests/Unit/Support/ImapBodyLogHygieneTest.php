<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Grep invariant: no logging call in the IMAP ingestion module may reference
 * mail body / header material.
 *
 * Enforces PRD-003 acceptance criterion "mail bodies only at debug level and
 * only in local env" by forbidding body logging outright — any future debug
 * hook belongs behind an explicit `app()->environment('local')` gate and must
 * not use the Log facade directly for body material.
 */
class ImapBodyLogHygieneTest extends TestCase
{
    private const IMAP_DIRECTORIES = [
        __DIR__.'/../../../app/Jobs',
        __DIR__.'/../../../app/Services/Email',
    ];

    /**
     * Identifiers that carry raw mail payload and must never appear inside a
     * logging invocation.
     */
    private const FORBIDDEN_IDENTIFIERS = [
        'rawBody',
        'rawHeaders',
        'getTextBody',
        'getHTMLBody',
        'getRawBody',
        '->body',
        '$body',
    ];

    public function test_no_logging_call_in_imap_module_references_mail_body_fields(): void
    {
        $offences = [];

        foreach (self::IMAP_DIRECTORIES as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach ($this->phpFilesUnder($directory) as $path) {
                $source = (string) file_get_contents($path);

                foreach ($this->extractLoggingCalls($source) as $call) {
                    $hit = $this->findForbiddenIdentifier($call['text']);
                    if ($hit !== null) {
                        $offences[] = sprintf(
                            '%s:%d – logging call references "%s": %s',
                            $path,
                            $call['line'],
                            $hit,
                            $this->compact($call['text']),
                        );
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offences,
            "IMAP logging must never include mail body or header content:\n".implode("\n", $offences),
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

    private function findForbiddenIdentifier(string $call): ?string
    {
        foreach (self::FORBIDDEN_IDENTIFIERS as $needle) {
            if (str_contains($call, $needle)) {
                return $needle;
            }
        }

        return null;
    }

    private function compact(string $text): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $text) ?? $text;

        return strlen($collapsed) > 160 ? substr($collapsed, 0, 157).'...' : $collapsed;
    }
}
