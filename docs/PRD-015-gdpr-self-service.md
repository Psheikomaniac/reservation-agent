# PRD-015: DSGVO-Endkunden-UI (Art. 15 + 17)

**Produkt:** reservation-agent
**Version:** V3.0
**Priorität:** P0 – Marktreife für deutsche Restaurants
**Entwicklungsphase:** V3-parallel (kein Block durch PRD-011)
**Voraussetzungen:** PRD-003 (E-Mail-Pfad), PRD-005 (Bestätigungs-Mail-Template)

---

## Problem

Die DSGVO verlangt zwei Endkunden-Rechte, die in V1+V2 nur indirekt erfüllt sind:

- **Art. 15 (Auskunft)** – Betroffene haben Anspruch auf Auskunft, welche Daten ein Verantwortlicher zu ihrer Person verarbeitet. Heute kann ein Gast das nur per E-Mail an den Restaurant-Owner anfragen, der dann manuell in der DB nachschaut. Das ist möglich, aber für den Gast unsichtbar – kein Vertrauensbeweis nach außen.
- **Art. 17 (Löschung)** – Betroffene haben Anspruch auf Löschung. Heute kann der Owner einen Datensatz manuell per Tinker oder DB-Query löschen. Wieder: möglich, aber unsichtbar.

In `docs/decisions/failed-email-imports-retention.md` ist diese Lücke als „Komfort, kein Compliance-Engpass" dokumentiert. Das ist juristisch korrekt – die 30-Tage-Auto-Löschung der Quarantäne erfüllt Art. 17 für `failed_email_imports`. Aber für den **regulären** `reservation_requests`-Datensatz gibt es keine Auto-Löschung und keine sichtbare Self-Service-Funktion.

Das V3-Brainstorming am 2026-05-01 hat den Anspruch geschärft: **DSGVO muss „auf der höchsten Ebene für den Benutzer zur Verfügung stehen"** – sichtbar, nicht versteckt. Gleichzeitig ist der volle Workflow Art. 15–22 für V3 zu groß. Lösung: Reduktion auf die zwei Artikel, die in der Gastro-Praxis tatsächlich ausgelöst werden – Art. 15 + Art. 17 – als sichtbares Self-Service-UI. Art. 16/18/20/21/22 wandern in V4 (PRD-022), wo das Stammgast-Profil (PRD-016) ohnehin „alle Daten zu Person X" als sinnvolle Aggregation liefert.

## Ziel

Jede ausgehende Bestätigungs-Mail enthält einen signierten Self-Service-Link „Welche Daten haben wir von dir? Daten löschen?". Der Link führt auf eine öffentliche HTML-Seite – kein Login. Dort kann der Gast die Daten zu seiner Reservierung einsehen und – nach einem Confirm-Schritt – seine Daten hart löschen lassen.

Im Dashboard bekommt der Owner zusätzlich eine Such-Maske „Reservierungen zu E-Mail X" mit Bulk-Lösch-Button. Jede Aktion (View, Delete) wird in einer separaten Audit-Tabelle ohne PII protokolliert.

---

## Scope V3.0

### In Scope

- Neue Tabelle `gdpr_audits` (action, restaurant_id, created_at – ohne PII)
- Signed Self-Service-Link in jeder ausgehenden Reservierungs-Mail
- Öffentliche Inertia-Page `Public/GdprSelfService.vue` (kein Login, signed URL)
- Endpoint `GET /gdpr/{token}` – zeigt Daten der Reservierung
- Endpoint `POST /gdpr/{token}/delete` – Confirm-Schritt mit Eingabe des Reservierungs-Datums, dann hart löschen
- Hard-Delete von `reservation_request`, allen `reservation_messages`, `reservation_replies`, `reservation_table_assignments`
- Owner-Sicht: bestehende Filter-Funktion im Dashboard wird um Bulk-Lösch-Button für E-Mail-Treffer erweitert
- Audit-Log-Eintrag pro Aktion ohne PII (`action`, `restaurant_id`, `created_at`)

### Out of Scope

- **Art. 16 (Berichtigung)** – Gast ändert seine Daten selbst – V4 PRD-022
- **Art. 18 (Einschränkung)** – V4 PRD-022
- **Art. 20 (Übertragbarkeit)** – V4 PRD-022, braucht Stammgast-Profil
- **Art. 21 (Widerspruch)** – V4 PRD-022
- **Art. 22 (Automatisierte Entscheidung)** – V4 PRD-022, nicht akut: V1+V2-System trifft keine automatisierten Entscheidungen mit Rechtswirkung
- **Cookie-Banner / TTDSG-Compliance** – Web-Auftritt-Thema, nicht Reservierungs-System
- **DSGVO-Compliance des KI-Anbieters (OpenAI)** – Decision in `docs/decisions/openai-data-protection.md`, nicht hier
- **Auto-Lösch-Pfad nach X Monaten** – V4 oder V5, abhängig von Pilot-Feedback
- **Anonymisierung statt Löschung** (Aufbewahrung für Statistiken ohne PII) – V4

---

## Technische Anforderungen

### Datenmodell

**Neue Tabelle `gdpr_audits`:**

| Spalte         | Typ          | Notiz                                    |
|----------------|--------------|------------------------------------------|
| id             | bigint PK    |                                          |
| action         | string       | `view`, `delete`, `owner_bulk_delete`    |
| restaurant_id  | bigint FK    | Tenant-Trennung                          |
| created_at     | datetime     |                                          |

**Bewusst KEINE Spalten:** `guest_email`, `reservation_id`, IP-Adresse, User-Agent. Die Audit-Tabelle muss explizit **frei von PII** sein – sie beantwortet die Frage „Wie viele Auskunfts-/Löschanfragen gab es im Restaurant X im Zeitraum Y?", nicht „Wer hat was gelöscht?". Diese Trennung ist die Voraussetzung dafür, dass die Audit-Daten selbst nicht unter denselben Lösch-Anspruch fallen.

### Signed-URL-Strategie

Token: signed URL via Laravel `URL::signedRoute(...)` mit Lebensdauer 30 Tage.

```php
URL::temporarySignedRoute(
    'gdpr.self-service',
    now()->addDays(30),
    ['reservation' => $reservation->id]
);
```

Der Link kommt in **jede** ausgehende Reservierungs-Mail (PRD-005-Bestätigungen, PRD-007-Auto-Send, PRD-014-Sync-Confirm) per Mail-Footer:

```
─────────────────────────────────────────────
Datenschutz: Welche Daten haben wir von dir?
Hier ansehen oder löschen → https://app.example/gdpr/abc123…
─────────────────────────────────────────────
```

Wird die Mail mehrfach versendet (z. B. zweite outbound-Mail in einem Thread), wird der Link **erneuert** mit voller 30-Tage-Lebensdauer. Damit läuft der Link für aktive Threads nicht ab.

### Routing

```php
Route::get('/gdpr/{reservation}', [GdprSelfServiceController::class, 'show'])
    ->name('gdpr.self-service')
    ->middleware('signed');

Route::post('/gdpr/{reservation}/delete', [GdprSelfServiceController::class, 'delete'])
    ->name('gdpr.self-service.delete')
    ->middleware('signed');
```

Beide Routes sind `signed`-protected. Ohne gültige Signatur: 403.

### Controller

`app/Http/Controllers/GdprSelfServiceController.php`:

```php
public function show(ReservationRequest $reservation, GdprAudit $audit): Response
{
    $audit->record(action: 'view', restaurantId: $reservation->restaurant_id);

    return Inertia::render('Public/GdprSelfService', [
        'reservation' => [
            'guest_name' => $reservation->guest_name,
            'guest_email' => $reservation->guest_email,
            'guest_phone' => $reservation->guest_phone,
            'desired_at' => $reservation->desired_at,
            'party_size' => $reservation->party_size,
            'note' => $reservation->note,
            'status' => $reservation->status,
            'created_at' => $reservation->created_at,
        ],
        'restaurant' => [
            'name' => $reservation->restaurant->name,
        ],
        'deleteToken' => URL::temporarySignedRoute(
            'gdpr.self-service.delete',
            now()->addMinutes(15), // Confirm-Schritt mit kurzer Lebensdauer
            ['reservation' => $reservation->id],
        ),
    ]);
}

public function delete(GdprDeleteRequest $request, ReservationRequest $reservation, GdprAudit $audit): RedirectResponse
{
    // Confirm: User muss das Reservierungs-Datum als Anti-Bot-Schutz eingeben
    $expected = CarbonImmutable::parse($reservation->desired_at)->format('d.m.Y');
    if ($request->confirm_date !== $expected) {
        return back()->withErrors(['confirm_date' => 'Datum stimmt nicht.']);
    }

    DB::transaction(function () use ($reservation) {
        $reservation->messages()->delete();
        $reservation->replies()->delete();
        $reservation->tableAssignments()->delete();
        $reservation->delete();
    });

    $audit->record(action: 'delete', restaurantId: $reservation->restaurant_id);

    return Inertia::render('Public/GdprDeleted');
}
```

`GdprAudit`-Service ist ein dünner Wrapper um `GdprAudit::create([...])`, isoliert für einfaches Mocking in Tests.

### Public-Inertia-Seite

`resources/js/pages/Public/GdprSelfService.vue`:

```
┌──────────────────────────────────────────────────────────────┐
│  Deine Daten beim Restaurant „{{ restaurant.name }}"        │
├──────────────────────────────────────────────────────────────┤
│  Reservierung am ..................... {{ desired_at }}      │
│  Personen ............................ {{ party_size }}      │
│  Status .............................. {{ status }}          │
│                                                              │
│  Persönliche Daten                                           │
│  Name ................................ {{ guest_name }}     │
│  E-Mail .............................. {{ guest_email }}    │
│  Telefon ............................. {{ guest_phone }}    │
│  Anmerkung ........................... {{ note }}           │
│                                                              │
│  Eingegangen am ...................... {{ created_at }}      │
│                                                              │
│  ┌────────────────────────────────────────────────────────┐  │
│  │  Daten löschen                                         │  │
│  │  Bitte bestätige durch Eingabe des Reservierungs-      │  │
│  │  Datums (TT.MM.JJJJ):                                  │  │
│  │  [____________]                                        │  │
│  │  [Löschen bestätigen]                                  │  │
│  └────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────┘
```

`resources/js/pages/Public/GdprDeleted.vue`:

```
┌──────────────────────────────────────────────────────────────┐
│  ✅ Deine Daten wurden gelöscht.                             │
│                                                              │
│  Wir haben alle gespeicherten Daten zu deiner Reservierung  │
│  bei „{{ restaurant.name }}" entfernt.                       │
└──────────────────────────────────────────────────────────────┘
```

Beide Seiten ohne Layout-Sidebar – sind öffentlich, kein Auth-User.

### Dashboard-Bulk-Delete

Bestehender Filter im Dashboard (Suche nach E-Mail) bekommt einen Action-Knopf „Alle Treffer DSGVO-konform löschen". Bestätigungs-Modal, dann analog zum Self-Service-Pfad:

- Hard-Delete aller Treffer-Reservations samt Messages, Replies, Table-Assignments
- `gdpr_audits.action = 'owner_bulk_delete'`, `restaurant_id` gesetzt

Policy: nur Owner-Rolle, nicht Service-Rolle.

### Erweiterung der Mailables

`ReservationReplyMail` (PRD-005) bekommt einen `gdprLink: string`-Property, der im Mail-Template eingebettet wird:

```blade
<hr>
<p style="font-size: 12px; color: #666;">
    Datenschutz: <a href="{{ $gdprLink }}">Welche Daten haben wir von dir? Hier ansehen oder löschen.</a>
</p>
```

Das gilt für alle drei Mail-Wege: PRD-005 (manuelle Freigabe), PRD-007 (Auto-Send) und PRD-014 (Sync-Confirm). Der Link wird zentral in einem Trait `HasGdprLink` generiert, damit kein Mailable den Link vergisst.

---

## Akzeptanzkriterien

- [ ] Migration für `gdpr_audits` läuft vor- und rückwärts
- [ ] `gdpr_audits` enthält **keine** PII-Spalten
- [ ] Jede ausgehende Bestätigungs-Mail (PRD-005 / PRD-007 / PRD-014) enthält den Self-Service-Link
- [ ] Self-Service-Link ohne gültige Signatur → 403
- [ ] Self-Service-Link mit abgelaufener Signatur (>30 Tage) → 403 mit Hinweis „Link abgelaufen, neue Mail anfordern"
- [ ] `GET /gdpr/{token}` zeigt alle Reservierungs-Daten der genannten Reservation, kein Login nötig
- [ ] `POST /gdpr/{token}/delete` ohne korrektes `confirm_date` → Validierungsfehler, Daten bleiben
- [ ] `POST /gdpr/{token}/delete` mit korrektem `confirm_date` → harter DELETE aller verknüpften Records in einer Transaktion
- [ ] Nach Löschung: zweiter Aufruf des Links → 404 oder „bereits gelöscht"-Hinweis
- [ ] `gdpr_audits` bekommt einen Eintrag pro `view` und `delete`, ohne PII
- [ ] Dashboard-Bulk-Lösch-Button löscht alle E-Mail-Treffer in einer Transaktion und schreibt einen `owner_bulk_delete`-Audit-Eintrag
- [ ] Logs enthalten **keine** Mail-Adresse, Reservierungs-ID oder Restaurant-Name aus dem Self-Service-Pfad
- [ ] Cross-Tenant-Test: signed Link aus Restaurant A funktioniert nicht für Reservation aus Restaurant B (model-binding scoped)
- [ ] `./vendor/bin/pint --test`, `npm run lint`, `npm run format:check` ohne Findings

---

## Tests

**Feature (`tests/Feature/Gdpr/`)**

- `GdprSelfServiceTest::it_renders_self_service_page_with_valid_token`
- `GdprSelfServiceTest::it_returns_403_without_signature`
- `GdprSelfServiceTest::it_returns_403_with_expired_signature`
- `GdprSelfServiceTest::it_records_view_audit_without_pii`
- `GdprSelfServiceTest::it_rejects_delete_with_wrong_confirm_date`
- `GdprSelfServiceTest::it_hard_deletes_reservation_and_related_records`
- `GdprSelfServiceTest::it_records_delete_audit_without_pii`
- `GdprSelfServiceTest::deleted_reservation_returns_gone_on_subsequent_visit`
- `GdprSelfServiceTest::it_does_not_log_pii_on_any_action`

**Feature (`tests/Feature/Dashboard/`)**

- `DashboardBulkDeleteTest::owner_can_bulk_delete_email_matches`
- `DashboardBulkDeleteTest::service_user_cannot_bulk_delete`
- `DashboardBulkDeleteTest::bulk_delete_writes_owner_audit_entry`
- `DashboardBulkDeleteTest::bulk_delete_does_not_cross_tenant_boundary`

**Mailable (`tests/Feature/Mail/`)**

- `ReservationReplyMailGdprLinkTest::it_includes_gdpr_link_in_body`
- `ReservationReplyMailGdprLinkTest::link_is_signed_and_route_resolves`

---

## Risiken & offene Fragen

- **Spam-Bots, die Mail-Inboxen plündern und Lösch-Links anklicken** – Confirm-Schritt mit Datums-Eingabe schützt. Ein Bot, der über zigtausend Mails geht, kennt das spezifische Reservierungs-Datum nicht und scheitert am Confirm. Akzeptiertes Restrisiko: ein Mensch, der gezielt fremde Mails liest, kann auch Daten löschen. Das ist ein Mail-Sicherheits-Problem, nicht ein DSGVO-Tool-Problem.
- **Owner kann sich gegen Löschung sträuben** – „aber das war ein Stammgast, ich hätte gerne die Notizen behalten". Antwort: Art. 17 ist ein Anspruch des Gastes, kein Wunsch des Owners. Das Tool implementiert hart, der Owner muss damit umgehen. Falls Pilot-Feedback eine Anonymisierungs-Variante fordert (Daten behalten ohne PII), kommt das in V4.
- **Audit-Tabelle ohne PII begrenzt forensische Aufklärung** – wenn ein Sabotage-Vorfall passiert (jemand löscht massenhaft Daten), kann die Audit-Tabelle nur sagen „in Restaurant X gab es 50 Löschungen", nicht „durch wen". Das ist eine bewusste Trennung: forensische Aufklärung passiert über Web-Server-Logs (mit Auth-User-ID bei Bulk-Delete) plus Backups, nicht über die DSGVO-Audit-Tabelle.
- **30-Tage-Token-Lebensdauer vs. älterer Mail-Verlauf** – wenn ein Gast eine Bestätigungs-Mail aus 2024 ausgräbt und den Link klickt, ist der Token abgelaufen. Antwort: Restaurant kann auf Anfrage einen frischen Link generieren (Tinker-Route, kein UI). V3-Akzeptanz, V4 könnte ein „neuen Link anfordern"-UI ergänzen.
- **DSGVO-Erklärung in der Mail** – die Footer-Zeile „Welche Daten haben wir von dir?" muss in alle Mail-Templates. Wenn sie in einem Template fehlt, ist das ein Compliance-Loch. Mitigation: Trait `HasGdprLink` als zentraler Generator, plus Test `ReservationReplyMailGdprLinkTest::link_is_signed_and_route_resolves` pro Mailable.
- **Was passiert mit `failed_email_imports`?** – Gehört zur Quarantäne, nicht zu `reservation_requests`. Wird **nicht** vom Self-Service-Link gelöscht. Die existierende 30-Tage-Auto-Löschung deckt Art. 17 dort ab, siehe `docs/decisions/failed-email-imports-retention.md`. Wenn ein Gast trotzdem fragt „löscht meine Daten aus eurer Quarantäne", muss der Owner manuell ran. V3.0 lebt damit; V3.1 könnte die Quarantäne in den Bulk-Delete-Suchpfad einbeziehen.
