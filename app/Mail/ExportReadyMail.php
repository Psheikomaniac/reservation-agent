<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\ExportFormat;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Notifies the operator that an async export (PRD-009 § Async-Generator)
 * is ready to download. Carries the signed download URL the
 * `ExportController::download` route accepts and a localised line
 * stating when the link expires (24 hours from job-completion time).
 */
final class ExportReadyMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $downloadUrl,
        public readonly ExportFormat $format,
        public readonly Carbon $expiresAt,
        public readonly int $recordCount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Reservierungs-Export bereit (%s)', $this->format->label()),
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.exports.export-ready',
            with: [
                'downloadUrl' => $this->downloadUrl,
                'format' => $this->format,
                'expiresAt' => $this->expiresAt,
                'recordCount' => $this->recordCount,
            ],
        );
    }
}
