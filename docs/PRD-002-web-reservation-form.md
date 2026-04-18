# PRD-002: Öffentliches Web-Reservierungs-Formular

**Produkt:** reservation-agent
**Version:** V1.0
**Priorität:** P0 – erste echte Reservierungsquelle
**Entwicklungsphase:** Woche 2

---

## Problem

Restaurants brauchen einen einfachen Weg, Reservierungen von ihrer eigenen Webseite direkt ins System zu bringen – ohne Drittanbieter, ohne Provisionen und ohne manuelles Abtippen aus E-Mails. Ein öffentliches Formular ist die schnellste Quelle, um echte Daten ins Dashboard zu bekommen.

## Ziel

Pro Restaurant eine öffentliche URL (`/r/{slug}/reservations`), über die Gäste ohne Login eine Reservierungsanfrage stellen können. Die Anfrage landet sofort als `ReservationRequest` mit `source = web_form` im System. Das Formular ist gegen Bots und Missbrauch geschützt.

---

## Scope V1.0

### In Scope

- Öffentliche GET-Route: zeigt ein Formular (Inertia-Page, ohne Auth-Layout)
- Öffentliche POST-Route: nimmt Daten entgegen, validiert, speichert
- `StoreReservationRequest` FormRequest mit vollständiger Validierung
- Honeypot-Feld (`website`) als Bot-Schutz
- Rate-Limiting (`throttle:10,1` pro IP)
- Einfache Bestätigungsseite („Danke, wir melden uns")
- Event `ReservationRequestReceived` wird dispatched (in PRD-005 abgefangen)
- Feature-Tests

### Out of Scope

- Tischplan / Zeitslot-Picker (PRD-005 / V2.0)
- CAPTCHA (erst bei echtem Bot-Problem, siehe Risiken)
- Embeddable Widget / iframe-Variante (V2.0)
- Mehrsprachige Formulare (V2.0)
- Gäste-Login / Bestellhistorie (V3.0)

---

## Technische Anforderungen

### Routes

```php
// routes/web.php
Route::get('/r/{restaurant:slug}/reservations', [PublicReservationController::class, 'create'])
    ->name('public.reservations.create');

Route::post('/r/{restaurant:slug}/reservations', [PublicReservationController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('public.reservations.store');

Route::get('/r/{restaurant:slug}/reservations/thanks', [PublicReservationController::class, 'thanks'])
    ->name('public.reservations.thanks');
```

### FormRequest

`app/Http/Requests/StoreReservationRequest.php`:

```php
return [
    'guest_name'  => ['required', 'string', 'max:120'],
    'guest_email' => ['required', 'email:rfc,dns', 'max:190'],
    'guest_phone' => ['nullable', 'string', 'max:40'],
    'party_size'  => ['required', 'integer', 'min:1', 'max:20'],
    'desired_at'  => ['required', 'date', 'after:now'],
    'message'     => ['nullable', 'string', 'max:2000'],
    'website'     => ['nullable', 'size:0'], // Honeypot – MUSS leer sein
];
```

- Bei gefülltem Honeypot: FormRequest validiert OK (damit der Bot keinen Unterschied sieht), Controller persistiert aber **nicht** und rendert die Bestätigungsseite.
- `desired_at` wird als Restaurant-Lokalzeit vom Formular übergeben (`YYYY-MM-DD HH:mm`), der Controller konvertiert vor dem Speichern in UTC.

### Controller

`app/Http/Controllers/PublicReservationController.php`:

- `create(Restaurant $restaurant)` → Inertia `PublicReservationForm` mit geteilten Props (Restaurant-Name, Tonalität für Begrüßungstext)
- `store(StoreReservationRequest $request, Restaurant $restaurant)`:
  1. Honeypot-Check – bei Verdacht: redirect auf `thanks` ohne Persistenz
  2. `ReservationRequest::create([...])` mit `source = ReservationSource::WebForm`, `status = ReservationStatus::New`, `raw_payload = $request->validated()`
  3. `event(new ReservationRequestReceived($request))`
  4. redirect auf `thanks`
- `thanks(Restaurant $restaurant)` → einfache Inertia-Page mit Restaurant-Name

### Frontend

`resources/js/pages/Public/ReservationForm.vue`:

- Kein App-Layout, minimales Public-Layout
- Felder: Name, E-Mail, Telefon (optional), Personenzahl (1–20 Dropdown), Datum, Uhrzeit, Nachricht, Honeypot (visuell versteckt via `aria-hidden` + `tabindex="-1"`)
- Client-seitige Basis-Validierung als Ergänzung, aber **niemals als Ersatz** für Server-Validierung
- Bei Server-Fehlern Darstellung der Inertia-Error-Props

### Event

`app/Events/ReservationRequestReceived.php` – schlankes Event mit `public readonly ReservationRequest $request`. Listener registriert in PRD-005.

---

## Akzeptanzkriterien

- [ ] `GET /r/demo-restaurant/reservations` zeigt das Formular
- [ ] Gültiger POST erzeugt einen `ReservationRequest` mit `source = web_form`, `status = new`
- [ ] Nach erfolgreichem POST: Redirect auf Bestätigungsseite mit Restaurant-Name
- [ ] Validierungsfehler werden als Inertia-Errors auf dem Formular angezeigt (kein Verlust der Eingaben)
- [ ] Datum in der Vergangenheit → 422 mit klarer Fehlermeldung
- [ ] `party_size > 20` → 422
- [ ] Gefüllter Honeypot → 302 auf Bestätigungsseite, **kein** Datenbankeintrag
- [ ] 11. Request von derselben IP innerhalb einer Minute → 429
- [ ] Event `ReservationRequestReceived` wird dispatched (mit `Event::fake()` testbar)
- [ ] Owner sieht die neue Reservierung im Dashboard (Durchstich mit PRD-004)
- [ ] Alle Tests grün, Pint + ESLint + Prettier ohne Findings

---

## Tests

**Feature (`tests/Feature/PublicReservationTest.php`)**

- `it shows the public form for an existing restaurant slug`
- `it creates a reservation request on valid submission`
- `it dispatches ReservationRequestReceived` (mit `Event::fake()`)
- `it rejects past dates`
- `it rejects party sizes above 20`
- `it rejects malformed emails`
- `it silently ignores submissions with a filled honeypot`
- `it rate-limits after 10 requests per minute per IP`
- `it returns 404 for an unknown slug`
- `it persists desired_at in UTC even when input is local time`

**Unit (optional)**

- Helper, der lokales Datum + Restaurant-Zeitzone → UTC konvertiert (mit DST-Edge-Case)

---

## Risiken & offene Fragen

- **CAPTCHA Ja/Nein?** – Entschieden: kein CAPTCHA in V1.0, Honeypot + Rate-Limit reichen für den Pilot. Trigger-Kriterien und bevorzugter Anbieter (Cloudflare Turnstile) in [`decisions/captcha-v1.md`](decisions/captcha-v1.md).
- **Default-Öffnungszeiten-Check im Formular** – V1.0 validiert **nicht**, ob das Restaurant zum gewünschten Zeitpunkt überhaupt geöffnet ist. Diese Prüfung macht der KI-Assistent (PRD-005) und liefert dann z. B. einen Alternativ-Vorschlag. Begründung: wir wollen auch Anfragen außerhalb der Öffnungszeiten annehmen (Eventanfragen, geschlossene Gesellschaften).
- **Max-Personenzahl pro Restaurant individuell?** – Entschieden: in V1.0 hart auf 20 gecappt, zentralisiert als `ReservationRequest::MAX_PARTY_SIZE`. Trigger für Per-Restaurant-Override und Implementierungs-Skizze in [`decisions/party-size-cap.md`](decisions/party-size-cap.md).
