# V3–V7-Roadmap – Design-Spec

**Datum:** 2026-04-29
**Stand:** V1 (PRDs 001–005) im Bau / abgeschlossen, V2 (PRDs 006–010) gescoped.
**Output dieses Dokuments:** Versions-Themen und Kandidaten-PRDs auf Bullet-Niveau für V3–V7. **Detail-PRDs** im Stil von 006–010 werden **erst pro Version** geschrieben, sobald die jeweils vorherige Version live oder kurz davor ist – um nicht auf Pilot-Annahmen zu planen, die heute nicht überprüfbar sind.

---

## 1. Schneideprinzip

Versionen werden nach **Nutzer-Schmerz nach Pilotbetrieb** geschnitten, nicht nach Technik-Achsen. Begründung: Ein Pilot-Restaurant nach V2 wird sich nicht über „fehlende WebSockets" beschweren – es wird sich darüber beschweren, dass Telefon-Reservierungen und Walk-ins außerhalb des Systems passieren. Schmerz-Schnitt liefert die ehrlichere Roadmap.

```
V3 = Operative Vollständigkeit       (Alle Reservierungen in einem System)
V4 = Gast-zentriert                  (Den Gast kennen)
V5 = KI-Differenzierung & Reichweite (Eigener Ton, eigene Plattform)
V6 = Plattform & Marktplätze         (Externe Reichweite, Skalierung)
V7 = Geschäftsmodell & Mobile        (Monetarisierung, neue Form-Faktoren)
```

Jede Version baut auf den vorherigen auf:

- V3 schließt operative Lücken aus V1+V2.
- V4 nutzt die in V3 entstandene operative Vollständigkeit, um den Gast als Entität sichtbar zu machen.
- V5 nutzt das V4-Stammgast-Profil, um Antworten pro Restaurant und pro Gast zu differenzieren.
- V6 nutzt das gewachsene System, um es nach außen zu öffnen (Marktplätze, Skalierung).
- V7 monetarisiert (Deposits) und erweitert die Form-Faktoren (Mobile, POS).

---

## 2. V3 – Operative Vollständigkeit

**Theme:** Alle Reservierungen, alle Kanäle, eine Quelle der Wahrheit.
**Aufwand grob:** ~6–8 Wochen.
**Voraussetzung:** V2 live, mindestens ein Pilot-Restaurant 4–6 Wochen produktiv.

### Kandidaten-PRDs

| Nr. | Arbeitstitel                            | Kurz                                                                                                                  | Aufwand |
|-----|-----------------------------------------|-----------------------------------------------------------------------------------------------------------------------|---------|
| 011 | Tisch-Liste mit Belegungsstatus         | Stammdaten-Tabelle (Tisch, Plätze, Raum-Tag), Belegungs-Sicht pro Datum/Slot, manuelle Tisch-Zuweisung an Reservierung. **Kein** grafischer Plan. | ~1.5–2 W |
| 012 | Manuelle Erfassung (Telefon/Walk-in)    | Internes Form für den Gastronom: legt `ReservationRequest` mit `source = phone` oder `walk_in` an. Auto-Tisch-Vorschlag aus PRD-011. | ~1 W |
| 013 | Warteliste passiv                       | Status `waitlisted`, gefilterte Sicht im Dashboard, Banner bei Cancel („1 Wartender könnte rein"). **Kein** automatisches Notify. | ~1 W |
| 014 | Reverb für V3-Surfaces                  | Laravel Reverb-Daemon, WebSocket-Updates für Tisch-Liste, Walk-in, Warteliste. Dashboard-Bestand bleibt vorerst Polling. | ~1.5–2 W |
| 015 | DSGVO-Voll-Workflow Art. 15–22          | Auskunft, Löschen, Berichtigung, Einschränkung, Übertragbarkeit. Workflow-Status, Fristen-Tracking, Audit-Log. | ~3–4 W |

### Bewusste Verschiebungen aus V3

- **Grafischer Tischplan** → spätere Version (Pilot-Feedback abwarten, ob die Liste nicht reicht). Wenn ja: V5 oder V6 als Sub-Projekt.
- **Aktive Warteliste mit Notify** → V4 (braucht CRM-Daten als Empfänger-Quelle).
- **Multi-Standort >5** → V6 (ist ein Skalierungs-/Vertriebs-Feature, kein Operative-Vollständigkeit-Feature).

---

## 3. V4 – Gast-zentriert

**Theme:** Den Gast kennen, ihn beim Namen nennen, wiederkommen lassen.
**Aufwand grob:** ~10–14 Wochen.
**Voraussetzung:** V3 produktiv, mindestens 2–3 Pilot-Restaurants 4–6 Wochen mit V3 live.

### Anker-PRD

| Nr. | Arbeitstitel                              | Kurz                                                                                                                                | Aufwand |
|-----|-------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------|---------|
| 016 | Stammgast-Profil mit Notes + Tags         | `Guest`-Tabelle, Auto-Match auf E-Mail / normalisierte Telefonnummer. Profil mit freier Notiz, Tag-Liste, Allergien-Feld, bevorzugter Sprache. Tags filterbar im Dashboard. | ~3–4 W |

### Begleit-PRDs (alle docken am Anker an)

| Nr. | Arbeitstitel                              | Kurz                                                                                                                                | Aufwand |
|-----|-------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------|---------|
| 017 | Aktive Warteliste mit Notify              | Cancel/Verkleinerung triggert automatische E-Mail an nächsten passenden Wartelisten-Eintrag mit Annahme-Frist. Empfänger-Daten aus PRD-016. | ~2–3 W |
| 018 | No-Show-Tracking                          | Counter pro Gast, sichtbar im Profil. Manueller Status-Übergang `replied → no_show`. **Keine** automatische Sperre, nur Sichtbarkeit. | ~1 W |
| 019 | Mehrsprachige KI-Antworten (erkennen)     | Sprache pro Anfrage erkennen (Profil-Feld + Heuristik aus Anfragetext), KI antwortet in dieser Sprache. Initial DE/EN/FR/IT.                 | ~2–3 W |
| 020 | RAG aus manuellen Edits                   | KI lernt Tonfall/Phrasen des Restaurants aus der Korrektur-Historie aus PRD-005. Pro-Restaurant-Vektor-Store, Few-Shot-Fallback. | ~3–4 W |

### Hinweis auf Idealbild (für V6 dokumentiert)

Das Stammgast-CRM-Idealbild des Owners ist breiter als V4 liefert. Folgende Aufsatz-Features werden **nicht** in V4 gebaut, sondern in V6 als Block „Voll-CRM-Outreach" geplant:

- Geburtstags-Erinnerungen für den Gastronom
- Reaktivierungs-Listen („Gast XY war 3 Monate nicht da")
- Opt-in-Newsletter-Trigger und Marketing-Cadences

Begründung: Diese Features öffnen ein zweites Produkt (Marketing/CRM-Tool) und sprengen V4. Sie passen besser in V6, wenn die operative Basis steht und Pilot-Daten zeigen, welche Outreach-Cadences echten Lift erzeugen.

---

## 4. V5 – KI-Differenzierung & eigene Reichweite

**Theme:** Eigener Ton, eigener Vertriebsweg, eigene Souveränität (auch über die KI).
**Aufwand grob:** ~10–12 Wochen.
**Voraussetzung:** V4 produktiv, RAG-Daten aus mindestens 200–500 Reservierungen pro Pilot-Restaurant.

### Kandidaten-PRDs (Bullet-Niveau)

- **Tonalität pro Restaurant** statt globalem Prompt – pro Restaurant konfigurierbarer Ton, Anrede-Stil, Footer-Phrasen, Begrüßung. Konfig-UI im Restaurant-Setting, A/B-Test-fähig.
- **Mehrsprachige Antworten ausgebaut** auf > 2 Sprachen mit Übersetzungs-Pipeline. V4 liefert Erkennen + 4 Locales – V5 liefert Pflege-UI für Templates und beliebige Locales.
- **Lokale KI als Option** (Ollama / vLLM) – Konfig pro Restaurant: OpenAI vs. self-hosted-Endpoint. Selber Prompt-Vertrag, austauschbare Implementierung. Datenschutz-Argument für skeptische Gastronomen.
- **Embeddable Widget / iframe** für die Restaurant-Webseite – das öffentliche Web-Reservierungs-Formular aus PRD-002 als einbettbares JS-Widget. Selbst-getrieben, kein externer Vertragspartner. Branding-Optionen.
- **Promptbar / Erklärbarkeit im Dashboard** – „warum hat die KI das so geschrieben?" zeigt Top-RAG-Hits + Prompt-Snippet. Baut auf PRD-020 (RAG) auf.

### Verschoben aus älterer Roadmap

- *OpenTable / TheFork / Quandoo* lagen vorher in V5 zusammen mit Widget. Sind in V6 verschoben, weil sie pro Provider 4–6 Wochen externes Onboarding/Vertrag brauchen und V5 dadurch unsteuerbar machen würden.

---

## 5. V6 – Plattform & Marktplätze

**Theme:** Externe Reichweite, Skalierung, breitere Kundenkategorie.
**Aufwand grob:** ~12–16 Wochen, gut parallel teilbar (jede Marktplatz-Anbindung ist eine eigene Welle).
**Voraussetzung:** V5 produktiv, Vertrieb hat erste Marktplatz-Verträge eingetütet.

### Kandidaten-PRDs (Bullet-Niveau)

- **OpenTable-Anbindung** (eigene Welle: Vertrag, Sync inbound/outbound, Konflikt-Handling bei doppelten Anfragen)
- **TheFork-Anbindung** (zweite Welle, gleiche Pattern wie OpenTable, Pattern aus erster Welle wiederverwendbar)
- **Google Reserve / Google Business** (dritte Welle, Buchungen über Google-Suche, Onboarding via Google Business Profile)
- **Quandoo / Resy** (vierte/fünfte Welle, optional je nach Pilot-Bedarf und Markt-Region)
- **Multi-Standort > 5** (aus V3 verschoben) – Performance-Tests bei 10–20 Standorten, RBAC-Rollen, cross-site Reporting, neuer Pricing-Plan über Business
- **Voll-CRM-Outreach** (aus V4-Idealbild verschoben) – Geburtstags-Erinnerungen, Reaktivierungs-Listen, opt-in-Newsletter-Trigger, Marketing-Cadences. Baut auf PRD-016 (Stammgast-Profil) auf.

### Reihenfolge innerhalb V6

Die Marktplatz-Anbindungen sind voneinander unabhängig und sollten **markt-getrieben priorisiert** werden, nicht nach Tech-Reihenfolge: welcher Marktplatz hat im konkreten Pilot-Markt den größten Hebel? In DACH ist das aktuell vermutlich TheFork und Google Reserve; in UK/US OpenTable und Resy.

---

## 6. V7 – Geschäftsmodell & Mobile

**Theme:** Monetarisierung über das Abo hinaus, neue Form-Faktoren, eigene Produktlinien.
**Aufwand grob:** offen, abhängig vom Pilot-Erfolg und Markt-Validierung.
**Voraussetzung:** V6 produktiv, klare Markt-Signale für die einzelnen Bausteine.

### Kandidaten-PRDs (Bullet-Niveau, Reihenfolge nicht gesetzt)

- **Anzahlung / Deposit / No-Show-Gebühr** – Stripe oder SumUp, Hard-Charge bei No-Show. Kommerziell heikel (Image-Risiko), nur opt-in pro Restaurant.
- **POS- / Kassensystem-Integration** – Lightspeed, Vectron, OrderBird, Hypersoft. Tisch-Status-Sync zwischen Reservierung und Kasse, Rechnungs-Cross-Reference. Pro POS-System eigene Welle.
- **Schicht- / Personal-Bezug** – welche Reservierungen liegen in welcher Service-Schicht, Briefings-PDF pro Schicht, Last-Minute-Umverteilung.
- **Mobile App** – eigene Produktlinie (iOS / Android). Primär Push-Empfänger + Quick-Approve, **kein** Voll-Dashboard. Für Gastronomen, die unterwegs Anfragen freigeben wollen.
- **White-Label / Partner-Modell** – für Gastro-Verbände, Lieferdienste, POS-Hersteller. Branding-Layer + Reseller-Vertrags-Schiene.

V7 ist explizit **nicht** als geschlossener Block geplant – jeder Baustein ist eigenständig und kann nach Markt-Feedback nach V6 selektiv gezogen werden.

---

## 7. Versions-übergreifende Verschiebungen (Übersicht)

| Aus  | Nach      | Was                                             | Begründung                                                                 |
|------|-----------|-------------------------------------------------|----------------------------------------------------------------------------|
| V3   | spätere V | Grafischer Tischplan                            | Tisch-Liste löst 80 % des Pain; Pilot-Feedback abwarten, ob teure Variante lohnt |
| V3   | V4        | Aktive Warteliste mit Notify                    | Braucht Stammgast-CRM-Daten als Empfänger-Quelle                            |
| V3   | V6        | Multi-Standort > 5                              | Skalierungs-/Vertriebs-Feature, kein Operative-Vollständigkeit-Feature      |
| V5   | V6        | OpenTable / TheFork / Google Reserve / Quandoo  | Externe Verträge & Onboarding-Pfade machen V5 unsteuerbar                   |
| V4   | V6        | Voll-CRM-Outreach (Geburtstag, Reaktivierung)   | Öffnet zweites Produkt (Marketing-Tool), sprengt V4                          |

---

## 8. Risiken pro Version

- **V3** – DSGVO-Voll-Workflow ist generös. Wenn der Aufwand-Druck steigt: auf Art. 15 + 17 reduzieren, Berichtigung über normale UI lassen, Übertragbarkeit später nachreichen.
- **V4** – RAG kann unter zu wenig Datenmenge leiden (wenige Reservierungen pro Pilot-Restaurant). Fallback: Few-Shot-Prompts mit kuratierten Beispielen pro Restaurant statt Vektor-Store.
- **V5** – Lokale-KI-Option ist datenschutz-getrieben, ohne klaren Markt-Beweis. Verschiebbar nach V6, wenn der Pilot zeigt, dass Gastronomen das nicht aktiv nachfragen.
- **V6** – Marktplatz-Integrationen sind extern abhängig. Vertrag, API-Stabilität, Onboarding-Geschwindigkeit der Provider sind blockierende Risiken. Plan B: jeder Provider ist seine eigene Welle, Verzögerung in einer blockiert die anderen nicht.
- **V7** – Alles in V7 ist optional und braucht Markt-Validierung, nicht Tech-Push. Reihenfolge folgt Pilot-Feedback nach V6.

---

## 9. Was dieses Dokument explizit **nicht** ist

- Es ist **kein** Detail-PRD. PRDs werden pro Version geschrieben, sobald die Version aktiv wird.
- Es ist **keine** verbindliche Aufwand-Schätzung. Die Wochen-Angaben sind Größenordnungen zur Versions-Steuerung, keine Sprint-Plan-Zahlen.
- Es ist **keine** Markt-Validierung der Features. Marktplatz-Integrationen, Mobile App und Deposit-Workflow brauchen vor PRD-Schreibe eine eigene Validierungs-Runde mit Pilot-Restaurants.
- Es ist **keine** Architektur-Entscheidung. Architektur-Entscheidungen (z. B. „Reverb-Daemon im Deployment", „lokale KI mit welcher Library") gehen in `docs/decisions/` und werden pro Version getroffen, nicht hier vorab.

---

## 10. Nächster Schritt

Wenn V2 produktiv ist und Pilot-Feedback vorliegt:

1. V3 Detail-PRDs schreiben (PRD-011 bis PRD-015), in derselben Form wie 006–010.
2. Pro PRD ein Issue auf GitHub anlegen, Branch-Workflow wie in `CLAUDE.md` beschrieben.
3. Diese Roadmap aktualisieren, wenn Pilot-Erkenntnisse Verschiebungen erzwingen.

V4–V7 bleiben bis zum jeweiligen Versions-Start auf Bullet-Niveau – bewusst, weil Detail-Planung auf 12-Monats-Horizont über V3 hinaus heute mehr verspricht, als wir liefern können.
