# PRD-001: Projekt-Grundstruktur & Datenmodell

**Produkt:** reservation-agent
**Version:** V1.0
**Priorität:** P0 – Fundament (alles andere baut darauf auf)
**Entwicklungsphase:** Woche 1

---

## Problem

Ohne ein funktionierendes Laravel-Skelett mit sauberem Datenmodell, Authentifizierung und Mandantenfähigkeit (ein Benutzer sieht nur seine Reservierungen) sind alle weiteren PRDs blockiert. Außerdem fehlt eine gemeinsame Struktur für Reservierungen unterschiedlicher Quellen (Web-Formular, E-Mail), damit das Dashboard sie einheitlich darstellen kann.

## Ziel

Ein lauffähiges Laravel 12 + Inertia + Vue 3.5 Projekt mit vollständigem Datenmodell, Migrations, Seeders, Auth und einer leeren Dashboard-Shell. Nach diesem PRD läuft `composer run dev` fehlerfrei, ein Benutzer kann sich einloggen und sieht ein leeres Dashboard im eigenen Restaurant-Kontext.

---

## Scope V1.0

### In Scope

- Laravel 12 Projekt-Setup basierend auf `laravel/vue-starter-kit` (Inertia + Vue 3.5 + TS + Tailwind v4 + Reka UI)
- Authentifizierung (Register, Login, Logout, Passwort-Reset) aus dem Starter-Kit
- Datenmodell mit Migrations:
  - `restaurants` (Name, Slug, Zeitzone, Öffnungszeiten, Kapazität, Tonalität-Präferenz)
  - `users` – erweitert um `restaurant_id` und `role` (`owner`, `staff`)
  - `reservation_requests` – zentrale Tabelle für alle Quellen
  - `reservation_replies` – Antwortvorschläge und freigegebene Antworten
  - `failed_email_imports` – Quarantäne für nicht parsbare Mails (wird in PRD-003 befüllt, Tabelle bereits hier anlegen)
- Enums in `app/Enums/`:
  - `ReservationStatus` (`new`, `in_review`, `replied`, `confirmed`, `declined`, `cancelled`)
  - `ReservationSource` (`web_form`, `email`)
  - `ReservationReplyStatus` (`draft`, `approved`, `sent`, `failed`)
- Models mit Casts, Relationen, Scopes (`RestaurantScope` als globaler Scope für Mandantentrennung)
- Policies (`RestaurantPolicy`, `ReservationRequestPolicy`) + `AuthServiceProvider`-Registrierung
- Factories + Seeders für lokale Entwicklung (1 Demo-Restaurant, 1 Owner, 3 Demo-Reservierungen in unterschiedlichen Status)
- Dashboard-Shell (Inertia-Page `Dashboard.vue`) mit Platzhalter, Navigation, Logout
- Grundlegende `.env.example` mit allen Keys (OpenAI, IMAP, Mail – zunächst leer)

### Out of Scope

- Öffentliches Reservierungs-Formular (PRD-002)
- IMAP-Fetch-Job / Parser (PRD-003)
- Dashboard-Listing mit echten Daten und Filtern (PRD-004)
- KI-Antwortgenerierung (PRD-005)

---

## Technische Anforderungen

### Datenmodell

**`restaurants`**

| Spalte               | Typ           | Notiz                                     |
|----------------------|---------------|-------------------------------------------|
| id                   | bigint PK     |                                           |
| name                 | string        |                                           |
| slug                 | string unique | für öffentliche URL in PRD-002            |
| timezone             | string        | z. B. `Europe/Berlin`                     |
| capacity             | int           | max. gleichzeitige Gäste                  |
| opening_hours        | json          | Schema siehe unten                        |
| tonality             | string        | `formal` \| `casual` \| `family`          |
| imap_host            | string null   | wird in PRD-003 verwendet                 |
| imap_username        | string null   |                                           |
| imap_password        | text null     | via Laravel Encrypted Cast                |
| created_at, updated_at | timestamps  |                                           |

**`opening_hours`-JSON-Schema** (Wochenplan, in PRD-001 festgelegt):

```json
{
  "mon": [{"from": "11:30", "to": "14:30"}, {"from": "18:00", "to": "22:30"}],
  "tue": [],
  "wed": [{"from": "11:30", "to": "14:30"}, {"from": "18:00", "to": "22:30"}],
  "thu": [{"from": "11:30", "to": "14:30"}, {"from": "18:00", "to": "22:30"}],
  "fri": [{"from": "11:30", "to": "14:30"}, {"from": "18:00", "to": "23:00"}],
  "sat": [{"from": "18:00", "to": "23:00"}],
  "sun": [{"from": "11:30", "to": "15:00"}]
}
```

Leeres Array = Ruhetag. Mehrere Blöcke pro Tag für Mittags-/Abendservice erlaubt.

**`users`** – zusätzlich zu Laravel-Default:

| Spalte         | Typ           | Notiz                              |
|----------------|---------------|------------------------------------|
| restaurant_id  | bigint FK     | NULL = Admin-User (reserviert)     |
| role           | string        | `owner` \| `staff`                 |

**`reservation_requests`**

| Spalte              | Typ           | Notiz                                          |
|---------------------|---------------|------------------------------------------------|
| id                  | bigint PK     |                                                |
| restaurant_id       | bigint FK     |                                                |
| source              | string        | `ReservationSource` enum                       |
| status              | string        | `ReservationStatus` enum                       |
| guest_name          | string        |                                                |
| guest_email         | string null   | null bei reiner Telefon-Mail                   |
| guest_phone         | string null   |                                                |
| party_size          | int           | 1–20 (siehe PRD-002)                           |
| desired_at          | datetime null | null, wenn aus Mail nicht parsbar              |
| message             | text null     | Freitext vom Gast                              |
| raw_payload         | json null     | Original: Formular-Payload oder parsed Mail    |
| needs_manual_review | boolean       | default false – von PRD-003 genutzt            |
| created_at, updated_at | timestamps |                                                |

**`reservation_replies`**

| Spalte                 | Typ         | Notiz                                       |
|------------------------|-------------|---------------------------------------------|
| id                     | bigint PK   |                                             |
| reservation_request_id | bigint FK   |                                             |
| status                 | string      | `ReservationReplyStatus` enum               |
| body                   | text        | KI- oder manuell erzeugter Antworttext      |
| ai_prompt_snapshot     | json null   | Context-JSON, mit dem die KI gefüttert wurde|
| approved_by            | bigint FK null | User-ID (null solange draft)             |
| approved_at            | datetime null |                                           |
| sent_at                | datetime null |                                           |
| error_message          | text null   | Fehler beim Versand                         |
| created_at, updated_at | timestamps  |                                             |

**`failed_email_imports`**

| Spalte           | Typ       |
|------------------|-----------|
| id               | bigint PK |
| restaurant_id    | bigint FK |
| message_id       | string    |
| raw_headers      | text      |
| raw_body         | text      |
| error            | text      |
| created_at       | timestamp |

### Mandantentrennung

- `RestaurantScope` als globaler Eloquent Scope auf `ReservationRequest`, `ReservationReply` und allen mandantenbezogenen Models – automatisch `where('restaurant_id', auth()->user()->restaurant_id)`.
- Policies erzwingen zusätzlich die Zugriffsprüfung auf Controller-Ebene (`$this->authorize('view', $request)`).
- `RestaurantPolicy::manage` nur für `role = owner`.

### Dashboard-Shell

- Inertia-Page `resources/js/pages/Dashboard.vue`
- Layout mit Sidebar (Reservierungen, Einstellungen, Logout)
- Zeigt in diesem PRD nur einen Platzhalter „Noch keine Reservierungen" – echtes Listing folgt in PRD-004
- Begrüßung mit Restaurant-Namen aus geteilten Inertia-Props

---

## Akzeptanzkriterien

- [ ] `composer install && npm install && composer run dev` läuft fehlerfrei
- [ ] `php artisan migrate:fresh --seed` erzeugt Demo-Restaurant, Demo-Owner und 3 Demo-Reservierungen
- [ ] Login mit Demo-Owner führt auf `/dashboard` mit Restaurant-Namen im Header
- [ ] Zweiter User aus anderem Restaurant sieht **keine** Reservierungen des ersten Restaurants (Scope greift)
- [ ] Direkter Zugriff auf `reservation_requests/{id}` eines fremden Restaurants → 403 (Policy greift)
- [ ] Alle Enums sind als PHP-Enums umgesetzt und in Models via Cast eingebunden
- [ ] `./vendor/bin/pint --test` ohne Findings
- [ ] `npm run lint` und `npm run format:check` ohne Findings
- [ ] Alle Tests grün (`composer test`)

---

## Tests

**Unit**

- `ReservationStatus::canTransitionTo()` – erlaubte und verbotene Übergänge (`new → in_review` ok, `confirmed → new` verboten)
- `OpeningHours::isOpenAt(Carbon $time)` – Wochentag-Matrix, mehrere Blöcke pro Tag, Ruhetag
- `Restaurant::availableSeatsAt()` – einfache Kapazitäts-Rechnung aus Seed-Daten

**Feature**

- Auth-Flow (Register, Login, Logout) aus Starter-Kit bleibt grün
- Dashboard-Zugriff: nicht eingeloggt → Redirect auf Login
- Mandantentrennung: Owner A sieht nur Reservierungen von Restaurant A
- Policy-Verletzung: direkter `GET /reservations/{id}` eines fremden Restaurants → 403

**Fixtures**

- `RestaurantFactory` mit realistischen Öffnungszeiten
- `UserFactory`-State `forRestaurant(Restaurant $r)`
- `ReservationRequestFactory` mit States `new`, `inReview`, `confirmed`

---

## Risiken & offene Fragen

- **Prod-DB: MySQL 8** (entschieden, siehe Issue #14). Laravel-Hosting-Stacks (Forge, Ploi, cPanel) laufen dort am reibungslosesten, JSON-Spalten reichen für das `opening_hours`-Schema. Lokal bleibt SQLite; Migrations werden in CI gegen MySQL 8 geprüft.
- **Öffnungszeiten-Editor im UI** – ist in PRD-001 bewusst NICHT enthalten. Für V1.0 reicht ein JSON-Feld im Seeder / Admin-Edit im Tinker. Ein UI-Editor kommt ggf. in V1.1.
- **Zeitzone** – Anfrage-Zeit wird in UTC gespeichert (`desired_at`), aber für Verfügbarkeitsprüfung (PRD-005) in Restaurant-Zeitzone umgerechnet. Globale Konvention: alles UTC in der DB.
