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
- Zentrale Design-Tokens als CSS-Variablen im bestehenden `@layer base`-Muster (Tailwind **v3.4**, shadcn/Reka-konform — siehe `resources/css/app.css`; **kein** v4-`@theme`): Palette (dunkle Topbar, Status-Farben), Spacing, Radius, Typo-Scale.
- Anwenden auf: Onboarding-Wizard (Phase 1), Dashboard + öffentliche Reservierungs-/Bestätigungsseiten (Phase 2).

**Phase 1b (eigene Welle): per-Restaurant-Konfiguration**

Alle drei Konfigurationen leben auf **Owner-only Settings-Seiten** (Muster `SendModeSettingsController` + Policy `manageIntegrations`); Secrets werden im UI **maskiert** (letzte 4 Zeichen, zentraler Helper) und nie im Klartext zurückgegeben. Bis ein Restaurant etwas konfiguriert, gilt der globale `.env`-Fallback.

- **OpenAI-Key BYOK** pro Restaurant (`restaurants.openai_api_key`, Encrypted Cast). Ein `OpenAiClientFactory(?Restaurant)` wählt den Key pro Restaurant, Fallback auf den globalen `.env`-Key; `OpenAiReplyGenerator` baut den Client darüber. `OpenAiKeyHealth` wird **per Restaurant** geschlüsselt (ein abgelehnter Restaurant-Key flaggt nur dieses Restaurant; der globale Key behält seine globale Health).
- **SMTP-Versand** pro Restaurant (neue Encrypted-Spalten `smtp_host/port/username/password` + eigene Absenderadresse `smtp_from_address/smtp_from_name`). `SendReservationReplyJob` baut zur Laufzeit einen Restaurant-Mailer (`Mail::mailer('restaurant-'.$id)`) mit Fallback auf den globalen `.env`-Mailer + globale `mail.from`.
- **IMAP-Empfang** pro Restaurant: Die Spalten (`imap_host/username/password`, Passwort `encrypted`) **existieren bereits** — Phase 1b ergänzt die fehlende **Owner-Settings-Seite** zum Konfigurieren (bisher nur per Seeder/Tinker). `FetchReservationEmailsJob`/`WebklexImapMailboxFactory` bleiben unverändert.

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
| `restaurants.openai_api_key` | string, encrypted, nullable | Phase 1b (BYOK); Fallback global |
| `restaurants.imap_*` | **bereits vorhanden** | `imap_host/username/password` (Passwort `encrypted`) existieren schon — Phase 1b ergänzt nur die Settings-UI |
| `restaurants.smtp_host` | string, nullable | Phase 1b |
| `restaurants.smtp_port` | unsignedInteger, nullable | Phase 1b |
| `restaurants.smtp_username` | string, nullable | Phase 1b |
| `restaurants.smtp_password` | string, **encrypted**, nullable | Phase 1b |
| `restaurants.smtp_from_address` | string, nullable | Phase 1b; Fallback `mail.from.address` |
| `restaurants.smtp_from_name` | string, nullable | Phase 1b; Fallback `mail.from.name` |

Jede Migration `up()` **und** `down()`; bestehende Migrationen werden nicht rückwirkend geändert.

### Provisionierung

`RestaurantProvisioner`-Service: `provision(name, slug, email, timezone): Invitation`. Validierung (Slug/Email eindeutig). Wird vom Artisan-Command genutzt; die spätere Super-Admin-UI ruft denselben Service.

**NOT-NULL-Defaults:** Die bestehende `restaurants`-Tabelle hat `capacity`, `opening_hours` (JSON) und `tonality` als NOT NULL **ohne** Default. Da der Provisioner das Restaurant *vor* dem Wizard anlegt, seedet er diese Felder mit Platzhaltern (`capacity = 0`, `opening_hours = {}`/leeres Schema, `tonality = ` Default-Enum); der Wizard-Pflicht-Kern überschreibt sie. Bewusste Entscheidung **gegen** eine nullable-Migration, da die Felder nach Onboarding ohnehin gesetzt sind.

**Owner-Rolle:** Der provisionierte User wird explizit mit `role = owner` angelegt (DB-Default ist `staff`) — sonst bekäme der „Owner" 403 bei Owner-Funktionen, also genau das Ausgangsproblem.

### Invitation-Flow

- Annahme-Route tokenisiert, Ablauf (z. B. 7 Tage), einmalig (`accepted_at`). Token **gehasht** gespeichert (Anti-Enumeration).
- Staff-Einladung (Wizard/Settings) nutzt denselben Flow mit `role=staff`. Cross-Tenant unmöglich (Policy + Scope).

### Wizard

- Server-getriebene Schritte (Inertia), Validierung per FormRequest pro Schritt; Fortschritt aus Datenpräsenz + `onboarding_completed_at`.
- Stammdaten: Slug-Eindeutigkeit, Zeitzonen-Whitelist. Tische: bestehende Table-CRUD-Logik (PRD-011). Öffnungszeiten: bestehendes `opening_hours`-JSON-Schema.
- Gemeinsame FormRequests/Komponenten zwischen Wizard und späteren Settings-Seiten, um Doppelpflege zu vermeiden.

### Onboarding-Gating

Middleware (z. B. `EnsureOnboardingComplete`) auf den authentifizierten App-Routes: Ein Owner mit Restaurant ohne `onboarding_completed_at` wird auf den Wizard umgeleitet. Ausgenommen: die Wizard-Routes selbst, Logout und die vom Wizard wiederverwendeten Settings-Aktionen. Staff eines noch nicht „live"-Restaurants sieht einen Platzhalter-Hinweis (kein Wizard — nur der Owner richtet ein).

### Design-Tokens

- Tokens zentral als CSS-Variablen im bestehenden `@layer base`-Muster (Tailwind v3.4, **kein** v4-`@theme`); Komponenten konsumieren Variablen (kein Hardcoding). Dark Topbar, Status-Farben für **alle 7 Stati** (`new`/`in_review`/`replied`/`confirmed`/`declined`/`cancelled`/`waitlisted`), Dichte/Spacing/Typo. Phase 2 ersetzt Hardcoded-Styles der Top-Surfaces durch Tokens.

### Phasen

- **Phase 1:** Tokens + Onboarding (Command, Invitation, Wizard Pflicht-Kern + Tonalität + Team) im C-Look.
- **Phase 1b:** per-Restaurant BYOK + SMTP-Versand. **Eigenes Epic mit eigenen Akzeptanzkriterien** — pilot-optional, blockiert Phase 1/2 nicht (global bleibt nutzbar).
- **Phase 2:** Restyle Dashboard + öffentliche Reservierungs-/Bestätigungsseiten.

---

## Akzeptanzkriterien

- [ ] `restaurant:provision` legt Restaurant (inkl. NOT-NULL-Defaults für `capacity`/`opening_hours`/`tonality`) + Owner (`role=owner`) + Invitation an und gibt den signierten Link aus; doppelter Slug/Email → klarer Fehler.
- [ ] Einladungs-Link: gültig → Passwort setzen → eingeloggt; abgelaufen/bereits angenommen → klare Meldung, kein Zugriff.
- [ ] Owner ohne vollständigen Pflicht-Kern wird auf den Wizard geleitet (Gating), nicht auf ein leeres Dashboard.
- [ ] Pflicht-Kern vollständig → `onboarding_completed_at` gesetzt → Dashboard „live".
- [ ] Optionale Schritte überspringbar; übersprungene erscheinen als Dashboard-Erinnerung.
- [ ] Team-Einladung erzeugt Staff-Invitation über denselben Flow; Cross-Tenant unmöglich.
- [ ] Migrationen vor/zurück lauffähig; keine bestehende Migration geändert.
- [ ] Design-Tokens zentral (CSS-Variablen im `@layer base`-Muster, kein v4-`@theme`), alle 7 Status-Farben inkl. `cancelled`; Wizard + Dashboard + öffentliche Reservierungs-/Bestätigungsseiten in Richtung C; keine funktionale Regression.
- [ ] Phase 1b: OpenAI-Key pro Restaurant wird vom Generator verwendet (Fallback global); SMTP-Versand-Config encrypted, nie im Klartext geloggt/ausgegeben.
- [ ] `./vendor/bin/pint --test`, `npm run lint`, `npm run format:check`, `npm run build` ohne Findings; Ziggy bei neuen Routes regeneriert.

---

## Tests

- **Command:** provisioniert korrekt (Owner mit `role=owner`, NOT-NULL-Defaults gesetzt); Duplikate (Slug/Email) abgelehnt.
- **Invitation:** Annahme gültig/abgelaufen/doppelt; Token gehasht; Cross-Tenant abgewiesen.
- **Wizard:** Pflicht-Schritt-Validierung + Persistenz; Gating-Redirect; `onboarding_completed_at`-Logik; Skip + Erinnerung.
- **Team-Invite:** Owner darf, Staff nicht; fremdes Restaurant unmöglich.
- **Phase 1b:** Generator nutzt per-Restaurant-Key (`OpenAI::fake`); kein Key-Leak in Logs/Responses; Mail-Secrets maskiert.
- **Frontend (Vitest):** Wizard-Schritt-Komponenten (Validierungsanzeige, Skip).
- **Keine Regression:** bestehende Feature-/Vitest-Suiten grün.

---

## Risiken & offene Fragen

- **BYOK/E-Mail pro Restaurant** verschiebt globale `.env`-Config auf Tenant-Ebene — größter Aufwand; daher eigene Phase 1b, fürs Pilot optional/überspringbar (global bleibt nutzbar).
- **Wizard ↔ Settings-Duplizierung:** beide bearbeiten dieselben Felder — gemeinsame FormRequests/Komponenten nutzen.
- **User ohne Passwort:** `password`-nullable berührt Auth-Annahmen (Login/Reset) — per Tests absichern.
- **Design-Regression:** Token-Umstellung kann bestehende Screens optisch verschieben — Phase 2 schrittweise, mit visueller Kontrolle.
- **Tailwind-Version:** Projekt läuft auf **Tailwind v3.4** (`@tailwind`-Direktiven + HSL-Variablen in `@layer base`), nicht v4. Token-Arbeit folgt diesem Muster — **kein** `@theme`. Die Stack-Tabelle in `CLAUDE.md` nennt fälschlich „Tailwind v4"; separat korrigieren (außerhalb dieses PRDs).
- **PRD-Nummer:** belegt 016 (vorgezogen); V4-Bullets bei Ausplanung neu nummerieren.

---

## Begleitende Quick-Wins (separate PRs, nicht Teil dieses PRDs)

- Veralteten „V1.0/Threading"-Hinweis aus dem Dashboard entfernen (Threading ist seit PRD-006 gebaut).
- 2. Demo-Restaurant + mehr Tische seeden (lokale Testbarkeit der Mandantenfähigkeit).
