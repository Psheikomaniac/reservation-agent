# Performance — Dashboard at >10k Reservierungen

Validierung der Annahme aus PRD-004, dass das Dashboard mit 10.000+ `reservation_requests`
über mehrere Tenants flüssig bleibt, sobald Pagination und der Composite-Index
`(restaurant_id, status, created_at)` greifen (siehe Migration
`2026_04_27_172941_add_dashboard_composite_index_to_reservation_requests_table.php`,
PR #159).

## Geltungsbereich

> **Disclaimer.** Alle Messungen unten wurden gegen **lokales SQLite** auf einem Mac
> (Apple Silicon, NVMe-SSD) ausgeführt. SQLite ist die V1.0-Default-DB für lokale
> Entwicklung; die Produktions-DB-Wahl (MySQL vs. Postgres) ist laut PRD-001 noch
> offen. Sobald die Prod-DB festliegt, müssen diese Zahlen gegen die Prod-Engine
> (idealerweise mit Prod-ähnlicher Datenmenge und realistischer Latenz) reproduziert
> werden. Bis dahin sind die Werte ein qualitativer Sanity-Check, kein Performance-SLA.

## Setup reproduzieren

```bash
# Optional: vorher lokale DB sichern, der Seeder fügt 10k Rows hinzu
cp database/database.sqlite /tmp/db.sqlite.backup

php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\PerformanceBenchmarkSeeder --force
php artisan dashboard:benchmark --iterations=50 --warmup=5

# Aufräumen
mv /tmp/db.sqlite.backup database/database.sqlite
```

Der Seeder erzeugt:

- **5 Restaurants** (`benchmark-restaurant-1` … `-5`) — die V1.0-Pilot-Größenordnung.
- **10.000 `reservation_requests`** über alle 5 Restaurants (round-robin → ~2.000 pro Tenant).
- Status-Mix gewichtet: ~25 % `new`, ~15 % `in_review`, ~25 % `replied`, ~25 % `confirmed`, ~10 % `declined`.
- `created_at` zufällig in den letzten 90 Tagen, `desired_at` 0–30 Tage nach `created_at`.

Der Seeder ist idempotent für Restaurants (re-run legt keine neuen Tenants an), fügt
beim erneuten Lauf aber jedes Mal weitere 10.000 Rows hinzu — bewusst, damit man die
Last hochfahren kann.

## Methodik

`php artisan dashboard:benchmark` baut exakt die Default-Query von
`DashboardController::index` nach (Status ∈ {`new`, `in_review`}, `desired_at >= heute`,
sortiert nach `created_at DESC`, paginiert auf 25), führt sie nach `--warmup` ungetimten
Aufrufen `--iterations`-mal mit `hrtime(true)` aus und gibt min / avg / p50 / p95 / max
in Millisekunden aus. Anschließend dumpt der Command `EXPLAIN QUERY PLAN` (SQLite) bzw.
`EXPLAIN` (MySQL/Postgres), damit der Index-Plan einsehbar ist.

Die zwei `count()`-Queries für die `stats`-Card laufen separat und werden über
`php artisan tinker` mit dem gleichen `hrtime`-Schema gemessen.

## Messergebnisse

**Setup**: SQLite, 10.003 Rows insgesamt, 2.000 davon im gemessenen Tenant
(`benchmark-restaurant-1`), 50 Iterationen, 5 Warmup-Runs.

### Default Dashboard-Query (paginate(25))

| Metrik | Wert |
|--------|------|
| min    | 1,34 ms |
| avg    | 1,50 ms |
| p50    | 1,37 ms |
| **p95** | **1,76 ms** |
| max    | 6,39 ms |

### Stats-Counts (2× `count()` für `new` + `in_review`)

| Metrik | Wert |
|--------|------|
| p50    | 0,19 ms |
| p95    | 0,29 ms |
| max    | 0,36 ms |

p95-Gesamt-Server-Anteil der Default-Query + Stats: **~2 ms**. Der Inertia-Render-Anteil
(JSON-Serialisierung der 25 Resources, Eager-Load `latestReply`, Middleware) liegt drüber,
ist aber nicht Teil dieser Messung.

## Index-Nutzung (EXPLAIN QUERY PLAN)

Listing-Query:

```
SEARCH reservation_requests USING INDEX reservation_requests_dashboard_index (restaurant_id=? AND status=?)
USE TEMP B-TREE FOR ORDER BY
```

Stats-Count-Query:

```
SEARCH reservation_requests USING INDEX reservation_requests_dashboard_index (restaurant_id=? AND status=?)
```

**Auswertung:**

- ✅ Der Composite-Index `reservation_requests_dashboard_index (restaurant_id, status, created_at)` wird sowohl für die Listing- als auch für beide Stats-Queries genutzt.
- ⚠️ Die Listing-Query baut für die Sortierung eine **temporäre B-Tree-Sortierung** auf, statt direkt aus der Index-Reihenfolge zu lesen. Grund: `whereIn('status', ['new', 'in_review'])` erzeugt zwei Index-Ranges; SQLite kann die Index-`created_at`-Sortierung nicht unverändert über zwei Ranges hinweg verwenden. Bei 2.000 Tenant-Rows und Pagination auf 25 ist die Sort-B-Tree-Phase trivial — das ist erst dann ein Thema, wenn ein einzelner Tenant Größenordnungen mehr aktive Anfragen hat als hier modelliert. Bis dahin: kein Handlungsbedarf.

## Was diese Messung *nicht* abdeckt

- **HTTP-Round-Trip**: Inertia-Render, Vite-Middleware, CSRF, Session-Handling laufen drüber.
- **Concurrency**: alles single-threaded. Bei Polling-Bursts (alle 30 s, mehrere Owner gleichzeitig) müssten WAL-Mode (SQLite) bzw. Connection-Pools (Postgres/MySQL) separat betrachtet werden.
- **Filter-Kombinationen**: gemessen wurde der Default-Filter. Volltextsuche (`q` in `guest_name`/`guest_email`) ist `LIKE %…%` und nutzt den Index *nicht* — bewusste V1.0-Akzeptanz, siehe PRD-004.
- **Latenz-Realität**: lokales SQLite hat keinen Netzwerk-Hop. Prod gegen Managed Postgres/MySQL fügt typischerweise 1–3 ms pro Query hinzu.

## Folge-Issues, falls Prod-Zahlen abweichen

- Re-Benchmark gegen die finale Prod-DB nach Entscheidung in PRD-001
- Falls die Sort-B-Tree-Phase aufgrund vieler aktiver Reservierungen pro Tenant relevant wird: Index-Variante ohne `whereIn`-Spalten oder ein Covering-Index für `created_at` evaluieren
- Volltextsuche (`q`) in Prod gegen einen FULLTEXT-Index oder `pg_trgm` migrieren, falls die Liste lang wird
