# PRD-016: Tenant-Onboarding & UX-Reife

**Produkt:** reservation-agent
**Version:** V3.2 – Pilot-Enablement
**Priorität:** P0 – ohne Onboarding ist kein Pilot möglich
**Entwicklungsphase:** nach V3-Release (PRD-012–015), vor V4
**Voraussetzungen:** PRD-001 (Foundation/Auth/Mandantentrennung), PRD-011 (Tische/Verfügbarkeit), PRD-005 (KI-Antworten/Tonalität)

> **Nummern-Hinweis:** Die V4-Roadmap führte PRD-016 als Platzhalter für „Stammgast-Profil". Da Onboarding pilot-freischaltend und vorgezogen ist, belegt es PRD-016; die V4-Bullets (Stammgast-Profil etc.) werden bei ihrer Ausplanung neu nummeriert.

---

## Problem

Das Datenmodell ist voll mandantenfähig (alles über `restaurant_id` + globalen `RestaurantScope`), aber es gibt **keinen Weg, ein Restaurant ins System zu bringen**: Die Registrierung aus dem Starter-Kit (`RegisteredUserController`) legt nur einen User **ohne** `restaurant_id` und Rolle an. Restaurants entstehen ausschließlich über Seeder/Tinker. Ein neu registrierter Nutzer landet auf einem leeren Dashboard und erhält bei Owner-Funktionen 403. Für den geplanten Pilot (2–3 Restaurants) fehlt damit die Grundlage.

Zweitens: Die Oberfläche ist die funktionale V1-Foundation-UI ohne Gestaltungs-Investition („stumpf"). Gerade der erste Eindruck eines frisch onboardeten Owners zählt.

(Begleitend: Ein Dashboard-Hinweis „Threading folgt in V2.0" ist seit PRD-006 falsch und wird separat entfernt — siehe Quick-Wins.)

## Ziel

1. **Onboarding:** Ein Restaurant kann zuverlässig provisioniert und vom Owner eingerichtet werden. `restaurant:provision` legt Restaurant + Owner + signierten Einladungs-Link an; der Owner nimmt die Einladung an (Passwort setzen) und durchläuft einen mehrstufigen Setup-Wizard, bis das Restaurant „live" Reservierungen verarbeitet.
2. **UX-Reife:** Ein zentrales Design-Token-System etabliert die Design-Richtung **C (Focused Operator)** — kompakt, datendicht, dunkle Topbar, kräftige Status-Farben. Wizard und die wirkungsstärksten Flächen (Dashboard, öffentliche Reservierungs-/Bestätigungsseiten) erhalten den neuen Look.

---

## Scope

### In Scope (wird gebaut)

**Onboarding**
- `php artisan restaurant:provision --name= --slug= --email= [--timezone=]` → erzeugt Restaurant + Owner (ohne Passwort) + Invitation und gibt den signierten Annahme-Link aus. Logik in einem `RestaurantProvisioner`-Service (wiederverwendbar durch die spätere Super-Admin-UI).
- `Invitation`-Model + Tabelle (gehashtes Token, `email`, `restaurant_id`, `role`, `expires_at`, `accepted_at`). Owner- **und** Staff-Einladungen über denselben Mechanismus.
- Tokenisierte Annahme-Route: Link → Passwort setzen → eingeloggt.
- Mehrstufiger Setup-Wizard (`resources/js/pages/Onboarding/*`), FormRequest pro Schritt, Fortschritt persistiert:
  - **Pflicht-Kern:** Stammdaten (Name, Slug/öffentliche URL, Zeitzone), Öffnungszeiten, ≥1 Tisch.
  - **Optional/überspringbar:** Tonalität, Team einladen (Staff).
- `restaurants.onboarding_completed_at`; „live" = Pflicht-Kern vollständig. Übersprungene optionale Schritte erscheinen als Erinnerungs-Karten im Dashboard.
- **Onboarding-Gating:** Ein Owner ohne vollständigen Pflicht-Kern wird auf den Wizard geleitet statt auf ein leeres/kaputtes Dashboard.

**Design-System (Richtung C)**
- Zentrale Design-Tokens (Tailwind v4 `@theme` / CSS-Variablen): Palette (dunkle Topbar, Status-Farben), Spacing, Radius, Typo-Scale.
- Anwenden auf: Onboarding-Wizard (Phase 1), Dashboard + öffentliche Reservierungs-/Bestätigungsseiten (Phase 2).

**Phase 1c (eigene Welle): per-Restaurant-Konfiguration**
- OpenAI-Key BYOK pro Restaurant (`restaurants.openai_api_key`, Encrypted Cast); Generator wählt den Key pro Restaurant, Fallback auf den globalen `.env`-Key.
- E-Mail-Anbindung pro Restaurant (IMAP/SMTP, Encrypted Casts). Bis dahin bleibt Mail global (`.env`); die Wizard-Schritte zeigen „später/global einrichten".

### Out of Scope (Vision – dokumentiert, nicht gebaut)

- **Öffentlicher Self-Service-Signup** (Registrierung → eigenes Restaurant ohne Admin-Schritt).
- **Multi-Standort / Org-Ebene** + Restaurant-Umschalter (Roadmap V6; Tenancy bleibt **1 User : 1 Restaurant**).
- **Abrechnung / Tarife** (Starter/Professional/Business).
- **Super-Admin-UI** zum Provisionieren (Datenmodell + Service werden dafür vorbereitet, damit die UI ohne Umbau andockt).
- **Per-Tenant-Branding** (Logo/Akzentfarbe) — die Tokens werden so gelegt, dass es später andockt.
- **Restyle-Welle 2** (Settings, Tische, Analytics, Auth-Seiten).

---

## Technische Anforderungen

### Datenmodell

| Tabelle/Spalte | Typ | Notiz |
|---|---|---|
| `invitations` | Tabelle | `id`, `restaurant_id` (fk), `email`, `role` (enum), `token` (gehasht), `expires_at`, `accepted_at` (nullable), `created_at` |
| `restaurants.onboarding_completed_at` | datetime, nullable | „live", wenn gesetzt |
| `users.password` | nullable | bis Einladung angenommen; Annahme setzt das Passwort |
| `restaurants.openai_api_key` | string, encrypted, nullable | Phase 1c (BYOK) |
| `restaurants` Mail-Config (IMAP/SMTP) | encrypted, nullable | Phase 1c — Spalten oder `restaurant_mail_settings` |

Jede Migration `up()` **und** `down()`; bestehende Migrationen werden nicht rückwirkend geändert.

### Provisionierung

`RestaurantProvisioner`-Service: `provision(name, slug, email, timezone): Invitation`. Validierung (Slug/Email eindeutig). Wird vom Artisan-Command genutzt; die spätere Super-Admin-UI ruft denselben Service.

### Invitation-Flow

- Annahme-Route tokenisiert, Ablauf (z. B. 7 Tage), einmalig (`accepted_at`). Token **gehasht** gespeichert (Anti-Enumeration).
- Staff-Einladung (Wizard/Settings) nutzt denselben Flow mit `role=staff`. Cross-Tenant unmöglich (Policy + Scope).

### Wizard

- Server-getriebene Schritte (Inertia), Validierung per FormRequest pro Schritt; Fortschritt aus Datenpräsenz + `onboarding_completed_at`.
- Stammdaten: Slug-Eindeutigkeit, Zeitzonen-Whitelist. Tische: bestehende Table-CRUD-Logik (PRD-011). Öffnungszeiten: bestehendes `opening_hours`-JSON-Schema.
- Gemeinsame FormRequests/Komponenten zwischen Wizard und späteren Settings-Seiten, um Doppelpflege zu vermeiden.

### Design-Tokens

- Tokens zentral; Komponenten konsumieren Variablen (kein Hardcoding). Dark Topbar, Status-Farben (`new`/`in_review`/`replied`/`confirmed`/`declined`/`waitlisted`), Dichte/Spacing/Typo. Phase 2 ersetzt Hardcoded-Styles der Top-Surfaces durch Tokens.

### Phasen

- **Phase 1:** Tokens + Onboarding (Command, Invitation, Wizard Pflicht-Kern + Tonalität + Team) im C-Look.
- **Phase 1c:** per-Restaurant BYOK + E-Mail.
- **Phase 2:** Restyle Dashboard + öffentliche Reservierungs-/Bestätigungsseiten.

---

## Akzeptanzkriterien

- [ ] `restaurant:provision` legt Restaurant + Owner + Invitation an und gibt den signierten Link aus; doppelter Slug/Email → klarer Fehler.
- [ ] Einladungs-Link: gültig → Passwort setzen → eingeloggt; abgelaufen/bereits angenommen → klare Meldung, kein Zugriff.
- [ ] Owner ohne vollständigen Pflicht-Kern wird auf den Wizard geleitet (Gating), nicht auf ein leeres Dashboard.
- [ ] Pflicht-Kern vollständig → `onboarding_completed_at` gesetzt → Dashboard „live".
- [ ] Optionale Schritte überspringbar; übersprungene erscheinen als Dashboard-Erinnerung.
- [ ] Team-Einladung erzeugt Staff-Invitation über denselben Flow; Cross-Tenant unmöglich.
- [ ] Migrationen vor/zurück lauffähig; keine bestehende Migration geändert.
- [ ] Design-Tokens zentral; Wizard + Dashboard + öffentliche Reservierungs-/Bestätigungsseiten in Richtung C; keine funktionale Regression.
- [ ] Phase 1c: OpenAI-Key pro Restaurant wird vom Generator verwendet (Fallback global); E-Mail-Config encrypted, nie im Klartext geloggt/ausgegeben.
- [ ] `./vendor/bin/pint --test`, `npm run lint`, `npm run format:check`, `npm run build` ohne Findings; Ziggy bei neuen Routes regeneriert.

---

## Tests

- **Command:** provisioniert korrekt; Duplikate (Slug/Email) abgelehnt.
- **Invitation:** Annahme gültig/abgelaufen/doppelt; Token gehasht; Cross-Tenant abgewiesen.
- **Wizard:** Pflicht-Schritt-Validierung + Persistenz; Gating-Redirect; `onboarding_completed_at`-Logik; Skip + Erinnerung.
- **Team-Invite:** Owner darf, Staff nicht; fremdes Restaurant unmöglich.
- **Phase 1c:** Generator nutzt per-Restaurant-Key (`OpenAI::fake`); kein Key-Leak in Logs/Responses; Mail-Secrets maskiert.
- **Frontend (Vitest):** Wizard-Schritt-Komponenten (Validierungsanzeige, Skip).
- **Keine Regression:** bestehende Feature-/Vitest-Suiten grün.

---

## Risiken & offene Fragen

- **BYOK/E-Mail pro Restaurant** verschiebt globale `.env`-Config auf Tenant-Ebene — größter Aufwand; daher eigene Phase 1c, fürs Pilot optional/überspringbar (global bleibt nutzbar).
- **Wizard ↔ Settings-Duplizierung:** beide bearbeiten dieselben Felder — gemeinsame FormRequests/Komponenten nutzen.
- **User ohne Passwort:** `password`-nullable berührt Auth-Annahmen (Login/Reset) — per Tests absichern.
- **Design-Regression:** Token-Umstellung kann bestehende Screens optisch verschieben — Phase 2 schrittweise, mit visueller Kontrolle.
- **PRD-Nummer:** belegt 016 (vorgezogen); V4-Bullets bei Ausplanung neu nummerieren.

---

## Begleitende Quick-Wins (separate PRs, nicht Teil dieses PRDs)

- Veralteten „V1.0/Threading"-Hinweis aus dem Dashboard entfernen (Threading ist seit PRD-006 gebaut).
- 2. Demo-Restaurant + mehr Tische seeden (lokale Testbarkeit der Mandantenfähigkeit).
