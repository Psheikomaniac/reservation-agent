# PRD-009: Export CSV/PDF

**Produkt:** reservation-agent
**Version:** V2.0
**Priorität:** P2 – operativ nützlich, unabhängig von PRD-006/007/008
**Entwicklungsphase:** V2 (parallel zu Phase 2/3)

---

## Problem

Owner brauchen Reservierungslisten regelmäßig **außerhalb** des Dashboards:

- **Service-Vorbereitung** – heute Abend, alle bestätigten Reservierungen mit Uhrzeit und Personenzahl auf einen Ausdruck
- **Buchhaltung / Steuerberater** – monatliche Liste aller Reservierungen für Abrechnung oder Statistik
- **Dokumentation gegenüber Personal** – Plan für die Schicht, ohne dass jeder Zugriff auf das System braucht

In V1.0 ist die einzige Antwort darauf manuelles Abschreiben aus dem Dashboard. Das ist fehleranfällig und der naheliegendste Mehrwert für Pilotrestaurants.

## Ziel

Aus der gefilterten Dashboard-Ansicht heraus kann der Owner die aktuelle Liste als CSV oder PDF exportieren. CSV ist Excel-kompatibel (UTF-8 mit BOM, Semikolon-getrennt für deutsche Locales). PDF ist eine schlichte druckbare Tabelle mit Restaurant-Header, Datum und Liste – kein Marketing, keine Markenkonkurrenz mit dem Restaurant.

Bei großen Listen (> 100 Records) läuft der Export asynchron als Job; das Ergebnis kommt per Mail mit Download-Link, der 24 Stunden gültig ist.

---

## Scope V2.0

### In Scope

- Export-Button im Dashboard-Header mit Dropdown „Als CSV" / „Als PDF"
- Filter aus aktuellem Dashboard-State werden 1:1 übernommen (Status, Quelle, Datumsbereich, Suche)
- CSV via `league/csv`, UTF-8 mit BOM, `;`-Separator (Excel-DE-kompatibel)
- PDF via `barryvdh/laravel-dompdf` mit minimalem Template
- Sync-Pfad bei ≤ 100 Records: direkter Download
- Async-Pfad bei > 100 Records: Job dispatchen, Mail mit signed URL (24 h Lebensdauer)
- Mailable `ExportReadyMail` mit Download-Link
- Storage: `storage/app/exports/{user_id}/{filename}`, Lifecycle-Cleanup nach 24 h
- Audit-Log: `export_audits` (wer, wann, welcher Filter, welches Format)
- Mandantentrennung über bestehenden `RestaurantScope`

### Out of Scope

- Geplante / wiederkehrende Exporte („jeden Montag Mail mit letzter Woche") – V3.0
- Export der Analytics-Reports aus PRD-008 als PDF – V2.1 (eigenes Issue, falls gewünscht)
- Excel-Format `.xlsx` – V3.0 (nur falls Pilot-Feedback es fordert; CSV deckt Excel-Import ab)
- Custom Spalten-Auswahl im UI – V3.0
- Versand der Export-Datei direkt an externe Mail (Steuerberater-Mailadresse) – V3.0

---

## Technische Anforderungen

### Routing

```php
Route::post('/exports/csv', [ExportController::class, 'csv'])->name('exports.csv');
Route::post('/exports/pdf', [ExportController::class, 'pdf'])->name('exports.pdf');
Route::get('/exports/download/{token}', [ExportController::class, 'download'])
    ->name('exports.download')
    ->middleware('signed');
```

### Controller

`app/Http/Controllers/ExportController.php`:

```php
public function csv(ExportRequest $request, ExportDispatcher $dispatcher): Response|RedirectResponse
{
    $filters = $request->validated();
    return $dispatcher->dispatch(ExportFormat::Csv, $filters, $request->user());
}

public function pdf(ExportRequest $request, ExportDispatcher $dispatcher): Response|RedirectResponse
{
    $filters = $request->validated();
    return $dispatcher->dispatch(ExportFormat::Pdf, $filters, $request->user());
}
```

`ExportRequest` valdiert dasselbe Filter-Schema wie `DashboardFilterRequest` aus PRD-004 – per Trait `WithDashboardFilters` geteilt.

`ExportDispatcher` entscheidet anhand der Record-Anzahl synchron oder asynchron:

```php
final class ExportDispatcher
{
    public function dispatch(ExportFormat $format, array $filters, User $user): Response|RedirectResponse
    {
        $count = ReservationRequest::query()
            ->filter($filters)
            ->count();

        $auditId = ExportAudit::open($user, $format, $filters, $count);

        if ($count <= 100) {
            return $this->generator->generateSync($format, $filters, $user, $auditId);
        }

        ExportReservationsJob::dispatch($format, $filters, $user->id, $auditId);

        return back()->with('flash', 'Der Export wird vorbereitet. Sie erhalten in Kürze eine E-Mail mit dem Download-Link.');
    }
}
```

### Sync-Generator

`ExportGenerator::generateSync(...)` liefert direkt eine `StreamedResponse`:

- CSV: `league/csv\Writer::createFromString()` mit BOM `"\xEF\xBB\xBF"` voran, Spalten siehe unten
- PDF: View `mail.exports.reservations-pdf` rendern, dompdf streamen

### Async-Generator

`ExportReservationsJob`:

```php
public function handle(ExportGenerator $generator): void
{
    $user = User::findOrFail($this->userId);
    $path = $generator->generateToFile($this->format, $this->filters, $user, $this->auditId);

    $url = URL::temporarySignedRoute(
        'exports.download',
        now()->addHours(24),
        ['token' => $this->auditId]
    );

    Mail::to($user)->send(new ExportReadyMail($url, $this->format, expiresAt: now()->addHours(24)));
}
```

`ExportController::download` lädt das File anhand des `ExportAudit`-Tokens und prüft, dass `auth()->id() === audit->user_id`. Signierte Route deckt Manipulation der URL ab; zusätzliche Owner-Prüfung deckt Login-Wechsel ab.

### CSV-Spalten

| Spalte             | Quelle                              |
|--------------------|-------------------------------------|
| ID                 | `reservation_requests.id`           |
| Eingegangen        | `created_at` (Restaurant-TZ)        |
| Status             | `status` (Enum-Label, deutsch)      |
| Quelle             | `source` (Enum-Label, deutsch)      |
| Wunschdatum        | `desired_at` (Restaurant-TZ)        |
| Personen           | `party_size`                        |
| Name               | `guest_name`                        |
| E-Mail             | `guest_email`                       |
| Telefon            | `guest_phone`                       |
| Manuelle Prüfung   | `needs_manual_review` (Ja/Nein)     |
| Letzte Antwort     | `replies->last()->status` (Label)   |

DSGVO-Hinweis im UI: „Der Export enthält personenbezogene Daten. Verarbeitung gemäß Datenschutzerklärung des Restaurants."

### PDF-Template

`resources/views/exports/reservations-pdf.blade.php`:

```blade
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #1a1a1a; }
    h1   { font-size: 14pt; margin: 0 0 4mm 0; }
    .meta{ font-size: 9pt; color: #555; margin-bottom: 6mm; }
    table{ width: 100%; border-collapse: collapse; }
    th, td { border-bottom: 0.4pt solid #999; padding: 2mm 1.5mm; text-align: left; }
    th   { background: #f5f5f5; font-weight: 600; }
    .small{ font-size: 8pt; color: #666; }
  </style>
</head>
<body>
  <h1>{{ $restaurant->name }}</h1>
  <div class="meta">
    Reservierungs-Export &middot; erstellt {{ $generatedAt->format('d.m.Y H:i') }}<br>
    Filter: {{ $filterSummary }}
  </div>
  <table>
    <thead>
      <tr>
        <th>Datum</th><th>Zeit</th><th>Pers.</th><th>Name</th>
        <th>Status</th><th>Quelle</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($reservations as $r)
        <tr>
          <td>{{ optional($r->desired_at)->copy()->setTimezone($restaurant->timezone)->format('d.m.Y') }}</td>
          <td>{{ optional($r->desired_at)->copy()->setTimezone($restaurant->timezone)->format('H:i') }}</td>
          <td>{{ $r->party_size }}</td>
          <td>{{ $r->guest_name }}</td>
          <td>{{ $r->status->label() }}</td>
          <td>{{ $r->source->label() }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
  <p class="small">Seite {PAGE_NUM} / {PAGE_COUNT}</p>
</body>
</html>
```

dompdf paginiert automatisch. Bei sehr großen Listen (> 500 Records): Header pro Seite via `@page` und CSS-Property repeat.

### Audit & Cleanup

`export_audits`:

| Spalte         | Typ           |
|----------------|---------------|
| id             | bigint PK     |
| restaurant_id  | bigint FK     |
| user_id        | bigint FK     |
| format         | string        | `csv` \| `pdf` |
| filter_snapshot| json          | für Reproduzierbarkeit + DSGVO-Auskunft |
| record_count   | int           |
| storage_path   | string null   | gesetzt bei Async-Pfad                   |
| downloaded_at  | datetime null |                                          |
| expires_at     | datetime null | bei Async-Pfad: created_at + 24 h        |
| created_at     | timestamp     |                                          |

**Cleanup-Job** `PurgeExpiredExportsJob` läuft alle 6 Stunden:

- Löscht Files unter `storage/app/exports/*` mit `expires_at < now()`
- Setzt `storage_path = null`, behält Audit-Eintrag (audit-trail-Pflicht)

---

## Akzeptanzkriterien

- [ ] Dashboard-Header zeigt Export-Dropdown
- [ ] CSV-Download enthält alle gefilterten Reservierungen mit korrekten Spalten
- [ ] CSV mit deutschen Umlauten ist Excel-DE-kompatibel (UTF-8 BOM, `;`-Separator)
- [ ] PDF-Download zeigt Restaurant-Name, Generierungszeit, Filter-Zusammenfassung, Tabelle
- [ ] Sync-Pfad bei ≤ 100 Records, kein Job, direkter Download
- [ ] Async-Pfad bei > 100 Records, Job dispatched, Mail mit signed URL (24 h)
- [ ] Signed Download-URL läuft nach 24 h ab → 403
- [ ] Anderer User kann Download-URL nicht missbrauchen → 403 (User-Mismatch)
- [ ] Mandantentrennung: User aus Restaurant B kann keine Reservierungen aus Restaurant A exportieren
- [ ] `PurgeExpiredExportsJob` löscht Files, behält Audit-Eintrag
- [ ] Datenschutz-Hinweis im UI sichtbar
- [ ] Alle Tests grün, Pint/ESLint/Prettier ohne Findings

---

## Tests

**Feature (`tests/Feature/Exports/CsvExportTest.php`)**

- `it streams a csv download for small result sets` (< 100 Records)
- `it includes BOM and semicolons for excel de`
- `it applies dashboard filters`
- `it includes guest email and party size`
- `it forbids cross-tenant export`

**Feature (`tests/Feature/Exports/PdfExportTest.php`)**

- `it streams a pdf for small result sets`
- `it shows restaurant name and generated date`
- `it shows filter summary in header`
- `it paginates large lists`

**Feature (`tests/Feature/Exports/AsyncExportTest.php`)**

- `it dispatches export job when record count exceeds threshold` (mit `Queue::fake()`)
- `it sends email with signed url after job completes` (mit `Mail::fake()`)
- `it allows download for owner of audit within signed window`
- `it rejects download after expiry`
- `it rejects download for different user`

**Feature (`tests/Feature/Exports/PurgeExpiredExportsJobTest.php`)**

- `it deletes files older than expiry`
- `it keeps audit record after file delete`
- `it sets storage_path to null after delete`

**Unit (`tests/Unit/Exports/ExportDispatcherTest.php`)**

- `it picks sync path under threshold`
- `it picks async path over threshold`
- `it writes audit row in both paths`

---

## Risiken & offene Fragen

- **DSGVO** – Exports enthalten personenbezogene Daten und müssen entsprechend dokumentiert werden. `export_audits` reicht für die Nachvollziehbarkeit. Hinweis im UI klärt den Owner. Eine Owner-Pflicht zur Vernichtung der Datei nach Verarbeitung lässt sich technisch nicht erzwingen; das wandert in die Datenschutzerklärung.
- **PDF-Größe bei tausenden Records** – dompdf hält das gesamte Dokument im Memory. Pragmatisch: Async-Pfad ab 100 Records puffert das Dispatch-Risiko, Job kann mehrere Sekunden laufen ohne Web-Timeout. Bei > 5.000 Records denken wir über Streaming-PDF (z. B. `mpdf`-Output-Buffering) nach – aber erst auf Bedarf.
- **Speicherort** – `storage/app/exports/` lokal ok, Prod sollte einen separaten Disk haben (`exports`-Disk in `config/filesystems.php`), idealerweise S3-kompatibel mit Lifecycle-Policy. Diese Infrastruktur-Entscheidung ist Teil der Prod-Setup-Doku, nicht Code-Scope von PRD-009.
- **Excel-`.xlsx` statt CSV** – Steuerberater fragen manchmal explizit nach `.xlsx`. CSV deckt > 90 % der Use-Cases. Wenn ein Pilot drauf besteht: `maatwebsite/excel` als Folge-Issue, nicht V2.0.
- **Filter-Snapshot ist groß** – wenn der Owner ungewöhnlich komplexe Filter setzt, wird das JSON groß. Akzeptabel; in der Praxis bleibt das im einstelligen KB-Bereich.
