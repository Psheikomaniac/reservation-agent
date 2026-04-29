# PRD-008: Dashboard-Analytics

**Produkt:** reservation-agent
**Version:** V2.0
**Priorität:** P1 – baut auf PRD-006 + PRD-007 auf, wird ohne sie weniger interessant
**Entwicklungsphase:** V2-Phase 3 (nach PRD-007)

---

## Problem

In V1.0 sieht der Owner im Dashboard nur den **Ist-Zustand**: aktuelle Anfragen, ihre Status, ihre Quellen. Es fehlt jede Antwort auf Fragen wie:

- *„Wie viele Anfragen kamen letzten Monat?"*
- *„Wie schnell beantworten wir Anfragen im Durchschnitt?"*
- *„Bestätigt die KI-Antwort wirklich, oder lehnen wir mehr ab als sinnvoll?"*
- *„Wie oft musste ich den KI-Vorschlag editieren?"* (Vertrauensindikator)
- *„Im Shadow-Modus: hätte die KI wirklich verlässlich versendet?"* (Vorbereitung Wechsel auf `auto`)

Ohne diese Sicht ist der Wechsel von `shadow` auf `auto` (PRD-007) eine Bauchentscheidung, und der Owner kann V2.0-Verbesserungen gegenüber V1.0 nicht messen.

## Ziel

Eine eigene Analytics-Seite im Dashboard, die die wichtigsten KPIs als Zahlen und einfache Trend-Charts zeigt. Drei Zeitfenster (heute / 7 Tage / 30 Tage). Keine BI-Plattform, kein Custom-Reporting – nur die Sicht, die Owner und Pilotbetreuung tatsächlich brauchen.

Wichtig: PRD-008 ist **kein Datalake**. Aggregate werden direkt aus der OLTP-Tabelle gerechnet, mit Caching. Wenn Pilot-Volumen das später sprengt, wird ein Materialized-View-Pattern eingeführt – nicht jetzt.

---

## Scope V2.0

### In Scope

- Inertia-Page `resources/js/pages/Analytics.vue`
- Range-Toggle: `today`, `7d`, `30d` (Server-seitig validiert)
- Kennzahlen-Karten:
  - Anfragen gesamt
  - Anfragen pro Quelle (`web_form`, `email`)
  - Bestätigungsquote (`confirmed` / Gesamt)
  - Ablehnungsquote (`declined` / Gesamt)
  - Durchschnittliche Zeit bis Antwort (Median + p90, in Minuten)
  - Edit-Quote: Anteil der Drafts, deren Body nach Generation geändert wurde
- Bei aktivem `shadow`-Modus zusätzlich: Übernahme-Rate (`shadow_was_modified = false` / Shadow-Gesamt)
- Bei aktivem `auto`-Modus zusätzlich: Auto-Send-Quote, Anteil der Hard-Gate-Blockaden, häufigste Hard-Gate-Reasons (Top 3)
- Trend-Chart: Anfragen pro Tag über 30 Tage (Line)
- Trend-Chart: Bestätigungsquote pro Tag über 30 Tage (Line)
- Trend-Chart: Thread-Replies pro Tag (PRD-006), als Indikator für Gast-Engagement
- Server-seitige Aggregation in `AnalyticsAggregator`
- 5-Minuten-Cache pro `(restaurant_id, range)`
- Mandantentrennung über bestehenden `RestaurantScope` aus PRD-001 bzw. `whereHas('reservationRequest', ...)`-Joins für Models ohne eigene `restaurant_id`-Spalte (siehe Hinweis unter „Edit-Rate")
- **Erweiterung PRD-005-Snapshot**: Migration / Anpassung `OpenAiReplyGenerator`, sodass der ursprünglich generierte Body zusätzlich als `original_body` in `ai_prompt_snapshot` persistiert wird. Voraussetzung für Edit-Rate und Shadow-Übernahme-Statistik.

### Out of Scope

- Custom Time-Ranges (frei wählbares Datum) – V3.0
- Cohort- und Retention-Analytics – V4.0
- Vergleich zwischen Restaurants (Multi-Tenant-Crossing) – aktuell keine Use-Case
- Export der Analytics-Reports als PDF (eigenes Issue, könnte in PRD-009 mitlaufen, ist aber nicht Pflicht)
- Real-time Charts (WebSocket-Updates) – V3.0 mit Reverb

---

## Technische Anforderungen

### Service-Schicht

`app/Services/Analytics/AnalyticsAggregator.php`:

```php
final class AnalyticsAggregator
{
    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function aggregate(Restaurant $restaurant, AnalyticsRange $range): AnalyticsSnapshot
    {
        $cacheKey = sprintf('analytics:%d:%s', $restaurant->id, $range->value);

        return $this->cache->remember($cacheKey, now()->addMinutes(5), function () use ($restaurant, $range) {
            return new AnalyticsSnapshot(
                range: $range,
                totals: $this->totals($restaurant, $range),
                sources: $this->bySource($restaurant, $range),
                statusBreakdown: $this->byStatus($restaurant, $range),
                responseTime: $this->responseTime($restaurant, $range),
                editRate: $this->editRate($restaurant, $range),
                sendModeStats: $this->sendModeStats($restaurant, $range),
                trends: $this->trends($restaurant, $range),
            );
        });
    }
}
```

`AnalyticsRange` ist ein Enum: `Today`, `Last7Days`, `Last30Days` mit Methoden `startsAt(): Carbon`, `endsAt(): Carbon`, `bucketSize(): string` (Tages- vs. Stunden-Buckets).

`AnalyticsSnapshot` ist ein readonly DTO (kein Model), das direkt vom Controller in eine `AnalyticsSnapshotResource` geschickt wird.

### SQL-Aggregation (Beispiele)

**Totals:**

```php
ReservationRequest::query()
    ->where('restaurant_id', $restaurant->id)
    ->where('created_at', '>=', $range->startsAt())
    ->count();
```

**Response-Time (Median, p90):**

```php
$durations = ReservationRequest::query()
    ->where('restaurant_id', $restaurant->id)
    ->where('created_at', '>=', $range->startsAt())
    ->whereHas('replies', fn ($q) => $q->whereIn('status', [
        ReservationReplyStatus::Sent,
        ReservationReplyStatus::Approved,
    ]))
    ->with(['replies' => fn ($q) => $q->orderBy('sent_at')])
    ->get()
    ->map(fn ($r) => $r->created_at->diffInMinutes($r->replies->first()->sent_at))
    ->sort()
    ->values();

$median = $this->percentile($durations, 0.5);
$p90    = $this->percentile($durations, 0.9);
```

Bei MySQL 8 / PostgreSQL würde sich `PERCENTILE_CONT` anbieten – wird aber bewusst **nicht** genutzt, weil SQLite (lokal) das nicht hat. Stattdessen Aggregation in PHP. Performance bleibt akzeptabel bis ~10.000 Records pro Range; bei mehr wechseln wir auf DB-Aggregation hinter einem Repository-Interface.

**Edit-Rate (V2.0-spezifisch):**

`reservation_replies` hat per PRD-001-Schema **keine eigene `restaurant_id`-Spalte** – die Mandantentrennung erfolgt über die `reservation_request_id`-Beziehung. Aggregat-Queries müssen entsprechend joinen, statt direkt zu filtern. SQLite-kompatibel via `json_extract` statt MySQL-spezifischem `->>`-Operator.

```php
$base = ReservationReply::query()
    ->whereHas('reservationRequest', fn ($q) => $q->where('restaurant_id', $restaurant->id))
    ->where('created_at', '>=', $range->startsAt())
    ->whereIn('status', [ReservationReplyStatus::Sent, ReservationReplyStatus::Approved]);

$total    = (clone $base)->count();
$modified = (clone $base)
    ->whereColumn('body', '!=', DB::raw("json_extract(ai_prompt_snapshot, '\$.original_body')"))
    ->count();

$rate = $total > 0 ? round($modified / $total * 100, 1) : 0.0;
```

`json_extract(...)` funktioniert auf SQLite, MySQL 8+ und MariaDB. PostgreSQL braucht eine alternative Schreibweise (`ai_prompt_snapshot->>'original_body'`); falls Postgres als Prod-DB gewählt wird, kapselt der Aggregator das hinter einem Repository-Interface mit DB-spezifischen Implementationen. Solange MySQL 8 die Zielplattform bleibt (PRD-001 Risiken), reicht `json_extract`.

**Hinweis:** Diese Auswertung erfordert, dass `ai_prompt_snapshot` zusätzlich den **ursprünglich generierten Body** enthält (zum Vergleich). Das ist eine kleine Erweiterung von PRD-005 (Snapshot-Format) und wird **als Migrations- und Codeschritt in der In-Scope-Liste oben** (`Erweiterung PRD-005-Snapshot um original_body`) mitgeführt.

**Send-Mode-Stats:**

```php
// Nur relevant, wenn Restaurant aktuell shadow oder auto nutzt
if ($restaurant->send_mode === SendMode::Manual) {
    return null;
}

$shadow = ReservationReply::query()
    ->whereHas('reservationRequest', fn ($q) => $q->where('restaurant_id', $restaurant->id))
    ->where('created_at', '>=', $range->startsAt())
    ->where('send_mode_at_creation', SendMode::Shadow);

$auto = AutoSendAudit::where('restaurant_id', $restaurant->id)
    ->where('created_at', '>=', $range->startsAt())
    ->where('decision', 'auto_send');

$gates = AutoSendAudit::where('restaurant_id', $restaurant->id)
    ->where('created_at', '>=', $range->startsAt())
    ->where('decision', 'manual')
    ->whereNotIn('reason', ['mode_manual'])
    ->groupBy('reason')
    ->selectRaw('reason, COUNT(*) as count')
    ->orderByDesc('count')
    ->limit(3)
    ->get();
```

`AutoSendAudit` hat eine eigene `restaurant_id`-Spalte (siehe PRD-007-Datenmodell), deshalb dort der direkte Filter. `ReservationReply` joint über `reservationRequest`.

### Trend-Daten

Tagesgenaue Buckets über 30 Tage:

```php
ReservationRequest::query()
    ->where('restaurant_id', $restaurant->id)
    ->where('created_at', '>=', $range->startsAt())
    ->selectRaw('DATE(created_at) as day, COUNT(*) as count')
    ->groupBy('day')
    ->orderBy('day')
    ->get();
```

Der Aggregator liefert eine vollständige Tagesreihe (auch Tage mit 0) zurück, damit das Chart keine Lücken zeigt.

### Caching-Strategie

- TTL: 5 Minuten pro `(restaurant_id, range)`
- Invalidation: bewusst keine aktive Invalidation. Live-Daten warten max. 5 min – das ist akzeptabel, weil Analytics keine operative Sicht ist (operativ = Dashboard-Liste, polling 30 s).
- Cache-Driver: Standard-Driver der Laravel-Konfiguration (`array` lokal, `database`/`redis` in Prod – nicht in PRD-008 entscheiden, das ist Infrastruktur)

### Frontend

`resources/js/pages/Analytics.vue`:

- Range-Switch oben (drei Tabs: Heute / 7 Tage / 30 Tage), URL-Parameter `?range=7d`
- Grid mit Karten für jede KPI – Reka UI `<Card>`-Component
- Charts: leichte Library, Vorschlag **`vue-chartjs`** (Standard, gute Vue-3-Unterstützung, ~50 KB gzipped)
- Charts laden serverseitig vorbereitete Daten aus den Inertia-Props – keine eigenen Fetch-Calls
- Empty-State: wenn 0 Anfragen im Range → freundlicher Hinweis statt leerem Chart
- Mobile-Friendly: Charts kollabieren auf 1 Spalte unterhalb 768 px

Routing:

```php
Route::get('/analytics', [AnalyticsController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('analytics.index');
```

Sidebar-Eintrag im bestehenden Layout: „Analyse" mit Chart-Icon (Reka UI / lucide-vue-next).

### Performance-Budget

- Aggregator-Call (uncached) bis 10.000 Records: < 500 ms
- Cached-Call: < 20 ms
- Chart-Library im Initial-Bundle: < 60 KB gzipped (sonst Lazy-Loading via dynamischem Import)

Benchmark wird Teil des PR-Review (Pest + `microtime(true)`-Fence-Test gegen Seed mit 10.000 Records).

---

## Akzeptanzkriterien

- [ ] Analytics-Seite zeigt für jeden Range alle Kennzahlen-Karten korrekt
- [ ] Range-Toggle ändert URL und Daten ohne Page-Reload (Inertia Partial Reload)
- [ ] Send-Mode-Stats erscheinen nur, wenn Restaurant aktuell `shadow` oder `auto` nutzt
- [ ] 30-Tage-Chart hat lückenlose Tagesachse (auch 0-Tage)
- [ ] Cache-TTL von 5 min greift; identische Calls innerhalb dieser Zeit erzeugen keinen zweiten DB-Hit (Test mit `Cache::spy()`)
- [ ] Mandantentrennung: User aus Restaurant B sieht keine Daten von Restaurant A
- [ ] Direktzugriff auf `/analytics` ohne Auth → Redirect auf Login
- [ ] Aggregator-Performance: bei 10.000 Reservierungen < 500 ms (uncached)
- [ ] Edit-Rate-Berechnung erfordert die Snapshot-Erweiterung in PRD-005 (`original_body` im `ai_prompt_snapshot`); Migrations-Schritt dokumentiert
- [ ] Alle Tests grün, Pint/ESLint/Prettier ohne Findings

---

## Tests

**Unit (`tests/Unit/Analytics/AnalyticsAggregatorTest.php`)**

Mit Seed-Daten in `RefreshDatabase`:

- `it counts total requests within range`
- `it counts requests by source`
- `it computes confirmation rate`
- `it computes median and p90 response time` (mit handgesetzten `created_at`/`sent_at`)
- `it computes edit rate when body differs from original`
- `it returns null sendModeStats for manual mode restaurants`
- `it computes shadow takeover rate`
- `it lists top 3 hard gate reasons in auto mode`
- `it fills trend buckets even for days with zero requests`
- `it scopes all queries to restaurant id` (kein Multi-Tenant-Leak)

**Unit (`tests/Unit/Analytics/AnalyticsRangeTest.php`)**

- Konvertierung Range → Carbon-Bereich
- Bucket-Größe Today (Stunden) vs. 7d/30d (Tage)

**Feature (`tests/Feature/Analytics/AnalyticsPageTest.php`)**

- `it renders the analytics page for owner`
- `it switches range via inertia partial reload`
- `it caches aggregator results for 5 minutes`
- `it does not show send mode stats for manual mode`
- `it shows send mode stats for shadow mode`
- `it forbids access for users without restaurant`
- `it scopes data to current restaurant`

**Frontend (Vitest, `resources/js/pages/Analytics.spec.ts`)**

- `range-toggle switches active tab and emits update`
- `chart renders with provided trend data`
- `empty state shows when totals are zero`

---

## Risiken & offene Fragen

- **PRD-005-Snapshot-Erweiterung notwendig** – die Edit-Rate vergleicht `body` mit `ai_prompt_snapshot->>"$.original_body"`. Aktuell speichert PRD-005 nur das Context-JSON, nicht den ursprünglichen Generated-Body. Diese Erweiterung wandert als Migrations-/Code-Schritt **in PRD-008**, weil sie hier den Wert hat. Risiko: Replies vor V2.0-Release haben kein `original_body` und werden in der Edit-Rate als „nicht-modifiziert" gezählt. Akzeptabel, weil V2.0 eine neue Baseline darstellt.
- **PHP-seitige Percentile-Berechnung** – funktioniert für die Pilot-Größenordnung, wird aber bei zehntausenden Records pro Range zur Latenzbremse. Schwellwert (< 500 ms uncached bei 10.000 Records) wird benchmarkt; bei Überschreitung kommt SQL-seitige Aggregation als Folge-Issue.
- **Time-to-Reply-Definition** – aktuell `created_at` der Request bis `sent_at` der ersten Reply. Bei `auto`-Modus liegt das nahe Null, was die Metrik verzerrt. Lösung: zusätzlich „Time to Owner Action" (= Wechsel aus `new`) als zweite Metrik in V2.1, falls Bedarf entsteht.
- **Chart-Library als Bundle-Last** – `vue-chartjs` + `chart.js` sind ~60 KB gzipped. Falls das Initial-Bundle wegen anderer Features bereits eng ist, alternativ via dynamischem Import lazy laden, sodass Analytics-Seite nur bei Aufruf den Code zieht.
- **Owner-Rolle vs. Staff-Rolle** – PRD-008 gibt aktuell beiden Rollen Zugriff auf Analytics. Falls in der Praxis Staff keine Reports sehen soll: Policy `viewAnalytics` einführen. Vorerst: alle authentifizierten User des Restaurants dürfen die Seite sehen.
