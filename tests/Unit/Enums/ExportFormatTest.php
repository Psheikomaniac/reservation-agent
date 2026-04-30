<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\ExportFormat;
use Tests\TestCase;

class ExportFormatTest extends TestCase
{
    public function test_csv_metadata(): void
    {
        $format = ExportFormat::Csv;

        $this->assertSame('csv', $format->value);
        $this->assertSame('CSV', $format->label());
        $this->assertSame('text/csv; charset=UTF-8', $format->mimeType());
        $this->assertSame('csv', $format->extension());
    }

    public function test_pdf_metadata(): void
    {
        $format = ExportFormat::Pdf;

        $this->assertSame('pdf', $format->value);
        $this->assertSame('PDF', $format->label());
        $this->assertSame('application/pdf', $format->mimeType());
        $this->assertSame('pdf', $format->extension());
    }

    public function test_only_csv_and_pdf_are_valid(): void
    {
        $cases = ExportFormat::cases();
        $this->assertCount(2, $cases);

        $values = array_map(fn (ExportFormat $f) => $f->value, $cases);
        $this->assertSame(['csv', 'pdf'], $values);
    }
}
