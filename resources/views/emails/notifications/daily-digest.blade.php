<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Tageszusammenfassung — {{ $summary->restaurantName }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f4f5;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#111;">
    <table cellpadding="0" cellspacing="0" border="0" width="600" align="center"
           style="max-width:600px;background:#ffffff;border-radius:8px;border:1px solid #e4e4e7;">
        <tr>
            <td style="padding:24px;">
                <h1 style="margin:0 0 8px 0;font-size:18px;color:#111;">{{ $summary->restaurantName }}</h1>
                <p style="margin:0 0 24px 0;font-size:14px;color:#52525b;">Tageszusammenfassung der Reservierungsanfragen</p>

                <table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation">
                    <tr>
                        <td width="50%" style="padding:8px;">
                            <div style="border:1px solid #e4e4e7;border-radius:6px;padding:16px;">
                                <p style="margin:0;font-size:12px;color:#71717a;text-transform:uppercase;letter-spacing:0.05em;">Heute gesamt</p>
                                <p style="margin:4px 0 0 0;font-size:24px;font-weight:600;color:#111;">{{ $summary->totalToday }}</p>
                            </div>
                        </td>
                        <td width="50%" style="padding:8px;">
                            <div style="border:1px solid #e4e4e7;border-radius:6px;padding:16px;">
                                <p style="margin:0;font-size:12px;color:#71717a;text-transform:uppercase;letter-spacing:0.05em;">Bestätigt</p>
                                <p style="margin:4px 0 0 0;font-size:24px;font-weight:600;color:#047857;">{{ $summary->confirmed }}</p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td width="50%" style="padding:8px;">
                            <div style="border:1px solid #e4e4e7;border-radius:6px;padding:16px;">
                                <p style="margin:0;font-size:12px;color:#71717a;text-transform:uppercase;letter-spacing:0.05em;">Offen</p>
                                <p style="margin:4px 0 0 0;font-size:24px;font-weight:600;color:#1d4ed8;">{{ $summary->pending }}</p>
                            </div>
                        </td>
                        <td width="50%" style="padding:8px;">
                            <div style="border:1px solid #e4e4e7;border-radius:6px;padding:16px;">
                                <p style="margin:0;font-size:12px;color:#71717a;text-transform:uppercase;letter-spacing:0.05em;">Manuelle Prüfung</p>
                                <p style="margin:4px 0 0 0;font-size:24px;font-weight:600;color:#b45309;">{{ $summary->needsReview }}</p>
                            </div>
                        </td>
                    </tr>
                </table>

                <p style="margin:24px 0 0 0;text-align:center;">
                    <a href="{{ $summary->dashboardUrl }}"
                       style="display:inline-block;padding:12px 20px;background:#111;color:#fff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:500;">
                        Zum Dashboard
                    </a>
                </p>
            </td>
        </tr>
        <tr>
            <td style="padding:16px 24px;border-top:1px solid #e4e4e7;font-size:12px;color:#71717a;text-align:center;">
                Du erhältst diese Mail, weil der Tages-Digest für dein Konto aktiv ist.
                <a href="{{ $settingsUrl }}" style="color:#71717a;">Einstellungen ändern</a>.
            </td>
        </tr>
    </table>
</body>
</html>
