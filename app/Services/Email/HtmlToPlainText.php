<?php

declare(strict_types=1);

namespace App\Services\Email;

final class HtmlToPlainText
{
    public function convert(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $withoutInvisible = $this->stripInvisibleBlocks($html);
        $withLineBreaks = $this->insertLineBreaks($withoutInvisible);
        $plain = strip_tags($withLineBreaks);
        $decoded = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->normalizeWhitespace($decoded);
    }

    private function stripInvisibleBlocks(string $html): string
    {
        return (string) preg_replace(
            '#<(script|style)\b[^>]*>.*?</\1>#is',
            '',
            $html,
        );
    }

    private function insertLineBreaks(string $html): string
    {
        $html = (string) preg_replace('#<br\s*/?\s*>#i', "\n", $html);

        $paragraphTags = 'p|div|h[1-6]|tr|li|ul|ol|table|thead|tbody|blockquote|article|section|header|footer';

        return (string) preg_replace("#</?($paragraphTags)\\b[^>]*>#i", "\n\n", $html);
    }

    private function normalizeWhitespace(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $lines = array_map(fn (string $line): string => preg_replace('/[ \t]+/', ' ', trim($line)), explode("\n", $text));
        $collapsed = preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));

        return trim((string) $collapsed);
    }
}
