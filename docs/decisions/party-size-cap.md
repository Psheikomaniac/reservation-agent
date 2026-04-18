# Entscheidung: Harter Cap von 20 Personen pro Anfrage in V1.0

**Status:** angenommen
**Datum:** 2026-04-18
**Bezug:** PRD-002 (Öffentliches Reservierungsformular), Issue #30

## Kontext

`ReservationRequest` erlaubt ein `party_size` zwischen 1 und 20. Die Zahl 20 taucht an drei Stellen auf, die sauber im Gleichschritt bleiben müssen:

- `StoreReservationRequest::rules()` (`max:...`)
- Die deutschsprachige Fehlermeldung `party_size.max`
- Der `<select>` im öffentlichen `Public/ReservationForm.vue`

Später wird die gleiche Grenze vom KI-Kontext-Builder (PRD-005) gelesen, wenn er dem Gastronom Alternativen vorschlägt.

PRD-002 stellte zwei Fragen:

1. Sollen einzelne Restaurants den Cap überschreiben können?
2. Wo zentralisieren wir die 20, solange es keinen Override gibt?

## Entscheidung

**V1.0 bleibt hart bei 20.** Kein Per-Restaurant-Override, keine neue Spalte, keine Config. Die Grenze ist eine Klassenkonstante auf dem Domänen-Modell: `App\Models\ReservationRequest::MAX_PARTY_SIZE = 20`.

Alle Stellen lesen aus dieser Konstante:

- `StoreReservationRequest` referenziert sie in Rule + Message
- `PublicReservationController::create()` gibt sie als Inertia-Prop `maxPartySize` ans Frontend weiter
- Die Vue-Form bezieht die Dropdown-Range aus `props.maxPartySize` – kein gespiegelter Literalwert mehr

## Begründung

- Im Pilot-Panel gibt es aktuell kein Restaurant mit einem belastbaren Bedarf > 20. Eine Spalte einzuführen, die niemand nutzt, ist vorauseilende Abstraktion und bläht das Datenmodell + Migrations-Historie unnötig auf.
- Anfragen > 20 Personen sind in der Regel Eventanfragen mit Spezialregeln (Menü, Raumbuchung, Anzahlung). Die Web-Form ist dafür nicht der richtige Kanal – solche Gäste sollen weiterhin direkt per E-Mail / Telefon buchen, nicht im Self-Service-Formular.
- Die Konstante auf dem Modell statt in `config/reservations.php` hält das Wissen bei der Domäne. Der KI-Kontext-Builder (PRD-005) liest sie ebenfalls vom Modell – keine Indirektion über Config-Keys.

## Trigger für Umstellung auf Per-Restaurant-Override

Umschalten auf einen optionalen `restaurants.max_party_size` Override, sobald **einer** dieser Punkte eintritt. Dokumentation + Folge-Issue dann Pflicht.

1. **Konkreter Pilot-Bedarf:** Ein Bestandskunde (aktive Nutzung > 4 Wochen) meldet nachvollziehbar, dass regelmäßig Anfragen für 21–50 Personen über das Formular kommen sollen. "Nice to have" reicht nicht – es muss ein Umsatzthema oder wiederholt abgewiesene Gäste geben.
2. **Abweichende Event-Schwelle:** Ein Pilot hat ein Event-Konzept (Weihnachtsfeiern, Catering), für das die Web-Form bewusst als Einstieg dienen soll. Dann wird ein separater Flow nötig, nicht nur ein höherer Cap.

## Implementierung bei Nachrüstung (Skizze)

- Migration: nullable `restaurants.max_party_size` (unsigned tinyInt, default NULL)
- Model: `Restaurant::effectiveMaxPartySize(): int` – `?? ReservationRequest::MAX_PARTY_SIZE`
- `StoreReservationRequest` liest `$this->route('restaurant')->effectiveMaxPartySize()` statt der Konstante
- Controller gibt `maxPartySize` aus `effectiveMaxPartySize()` weiter
- Kontext-Builder PRD-005 analog

Die Konstante auf dem Modell bleibt dabei Default-Wert und Ankerpunkt.

## Nicht in diesem Dokument

- Event-Buchungs-Flow (eigenes PRD, nicht Teil von V1.0)
- Config-basierte Override-Variante (verworfen – Config würde die Domain-Grenze vom Modell trennen)
