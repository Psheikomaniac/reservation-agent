# Entscheidung: Kein CAPTCHA in V1.0

**Status:** angenommen
**Datum:** 2026-04-18
**Bezug:** PRD-002 (Öffentliches Reservierungsformular), Issue #29

## Kontext

Das öffentliche Formular `POST /r/{slug}/reservations` ist unauthentifiziert und potenziell Spam-Ziel. Die Projekt-Leitplanke bisher:

- `throttle:10,1` – 10 Requests pro IP pro Minute (Route-Middleware)
- Honeypot-Feld `website` – visuell versteckt, bei Befüllung stilles Redirect auf `thanks`, kein DB-Eintrag, kein Event
- Inertia-freundliche Fehlerantwort bei Limit-Treffer (siehe #26)

PRD-002 stellt die Frage: reicht das für V1.0, oder muss ein CAPTCHA dazu?

## Entscheidung

V1.0 geht **ohne CAPTCHA live.** Honeypot + Rate-Limit sind der komplette Bot-Schutz. Die Entscheidung kippt automatisch, sobald einer der unten definierten Trigger zieht.

## Begründung

- Die Zielgruppe sind inhabergeführte Restaurants mit überschaubarem Traffic. Echte Spam-Fluten sind vor dem Pilot unwahrscheinlich genug, dass wir keinen UX-Reibungsverlust durch CAPTCHA erkaufen wollen.
- CAPTCHA-Widgets kosten Time-to-Reservation und erhöhen die Abbruchrate gerade auf Mobile. Das ist in der Pilot-Phase das teurere Risiko.
- Honeypot + `throttle:10,1` fangen Standard-Formbot-Traffic erfahrungsgemäß ab. Komplexe, gezielte Angriffe würde auch ein CAPTCHA nur bremsen, nicht verhindern.

## Trigger für Nachrüsten

Umschalten auf CAPTCHA, sobald **einer** dieser Punkte erreicht ist. Messgrundlage: `reservation_requests` Tabelle + Web-Server-Logs der letzten 7 Tage.

1. **Spam-Ratio > 5 %**: Anteil als Spam klassifizierter Anfragen pro Restaurant überschreitet 5 % der eingegangenen Anfragen in einer rollenden 7-Tage-Betrachtung. Klassifizierung zunächst manuell durch den Gastronom im Dashboard.
2. **Absolute Spam-Welle**: Mehr als 50 Spam-Anfragen in 24 h über alle Restaurants hinweg. Signal: Handarbeit skaliert nicht mehr.
3. **Rate-Limit-Dauertreffer**: Mehr als 100 `429`-Responses pro Tag auf der Store-Route, aufsummiert über alle IPs. Zeigt koordinierten Angriff unterhalb der Per-IP-Schwelle.

Wird ein Trigger ausgelöst, ist die Entscheidung "CAPTCHA aktivieren" binnen einer Woche zu treffen und zu dokumentieren (Update dieser Datei + Folge-Issue).

## Bevorzugter Anbieter bei Nachrüstung

**Cloudflare Turnstile** als erste Wahl:

- Kostenlos, keine harten Limits für unser Traffic-Volumen
- Kein Puzzle für den Endnutzer im Happy-Path – unsichtbare Challenge, nur bei Verdacht interaktive Eskalation
- DSGVO-freundlicher als hCaptcha oder reCAPTCHA v3 (keine dauerhafte Identifier-Speicherung, Cloudflare ist EU-präsent)
- Integration über `<script>` + ein Pflichtfeld auf dem Formular und Server-Side Verify über HTTP-Call in einem neuen `TurnstileRule`

**Fallback:** hCaptcha, falls Turnstile für ein Pilot-Restaurant konkrete Hindernisse zeigt (z. B. Cloudflare-Blocker-Erkennung im Gast-WLAN).

Nicht in Frage kommt reCAPTCHA v2/v3 wegen Google-Abhängigkeit und DSGVO-Reibung.

## Nicht in diesem Dokument

- Detail-Integration (eigenes Issue sobald Trigger greift)
- AB-Test-Plan (nicht geplant – Entscheidung ist binär trigger-gesteuert)
