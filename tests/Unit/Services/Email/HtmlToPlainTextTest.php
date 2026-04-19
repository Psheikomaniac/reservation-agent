<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Services\Email\HtmlToPlainText;
use Tests\TestCase;

class HtmlToPlainTextTest extends TestCase
{
    private HtmlToPlainText $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new HtmlToPlainText;
    }

    public function test_it_returns_empty_string_for_empty_input(): void
    {
        $this->assertSame('', $this->converter->convert(''));
    }

    public function test_it_strips_simple_tags(): void
    {
        $html = '<p>Hallo Welt</p>';

        $this->assertSame('Hallo Welt', $this->converter->convert($html));
    }

    public function test_it_preserves_paragraph_breaks_as_blank_lines(): void
    {
        $html = '<p>Erste Zeile</p><p>Zweite Zeile</p>';

        $result = $this->converter->convert($html);

        $this->assertStringContainsString('Erste Zeile', $result);
        $this->assertStringContainsString('Zweite Zeile', $result);
        $this->assertMatchesRegularExpression('/Erste Zeile\s*\n\s*\n?\s*Zweite Zeile/', $result);
    }

    public function test_it_converts_br_tags_to_newlines(): void
    {
        $html = 'Zeile 1<br>Zeile 2<br/>Zeile 3';

        $result = $this->converter->convert($html);

        $this->assertSame("Zeile 1\nZeile 2\nZeile 3", $result);
    }

    public function test_it_treats_block_tags_as_line_breaks(): void
    {
        $html = '<div>A</div><div>B</div><h1>C</h1>';

        $result = $this->converter->convert($html);

        $this->assertMatchesRegularExpression('/A\s*\n+\s*B\s*\n+\s*C/', $result);
    }

    public function test_it_converts_list_items_to_lines(): void
    {
        $html = '<ul><li>Eins</li><li>Zwei</li></ul>';

        $result = $this->converter->convert($html);

        $this->assertStringContainsString('Eins', $result);
        $this->assertStringContainsString('Zwei', $result);
        $this->assertMatchesRegularExpression('/Eins\s*\n+\s*Zwei/', $result);
    }

    public function test_it_converts_table_rows_to_lines(): void
    {
        $html = '<table><tr><td>Name</td><td>Anna</td></tr><tr><td>Zeit</td><td>19:00</td></tr></table>';

        $result = $this->converter->convert($html);

        $this->assertMatchesRegularExpression('/Anna\s*\n+\s*Zeit/', $result);
    }

    public function test_it_strips_script_and_style_blocks_including_content(): void
    {
        $html = '<style>.x { color: red; }</style><p>Hallo</p><script>alert("x")</script>';

        $result = $this->converter->convert($html);

        $this->assertSame('Hallo', $result);
    }

    public function test_it_preserves_umlauts(): void
    {
        $html = '<p>Für 4 Personen um 20:00 Uhr, Grüße Jürgen Müller</p>';

        $result = $this->converter->convert($html);

        $this->assertStringContainsString('Für', $result);
        $this->assertStringContainsString('Grüße', $result);
        $this->assertStringContainsString('Jürgen Müller', $result);
    }

    public function test_it_preserves_emoji(): void
    {
        $html = '<p>Freue mich 🎉</p>';

        $this->assertStringContainsString('🎉', $this->converter->convert($html));
    }

    public function test_it_decodes_html_entities(): void
    {
        $html = '<p>Tisch f&uuml;r 2 &amp; ein Kind &ndash; 19:00</p>';

        $result = $this->converter->convert($html);

        $this->assertStringContainsString('für', $result);
        $this->assertStringContainsString('&', $result);
        $this->assertStringNotContainsString('&amp;', $result);
        $this->assertStringContainsString('–', $result);
    }

    public function test_it_replaces_nbsp_with_regular_space(): void
    {
        $html = "<p>Tisch\u{00A0}für\u{00A0}2</p>";

        $result = $this->converter->convert($html);

        $this->assertStringNotContainsString("\u{00A0}", $result);
        $this->assertStringContainsString('Tisch für 2', $result);
    }

    public function test_it_normalizes_crlf_line_endings(): void
    {
        $html = "Zeile 1<br>\r\nZeile 2";

        $result = $this->converter->convert($html);

        $this->assertStringNotContainsString("\r", $result);
    }

    public function test_it_collapses_runs_of_blank_lines(): void
    {
        $html = '<p>A</p><p></p><p></p><p>B</p>';

        $result = $this->converter->convert($html);

        $this->assertDoesNotMatchRegularExpression('/\n{3,}/', $result);
    }

    public function test_it_trims_leading_and_trailing_whitespace(): void
    {
        $html = "\n\n  <p>Hallo</p>  \n\n";

        $this->assertSame('Hallo', $this->converter->convert($html));
    }
}
