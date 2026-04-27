# Troubleshooting – bekannte Fehler & Fixes

Diese Datei sammelt **alle nicht-trivialen Fehler**, auf die wir im Projekt schon einmal gestoßen sind, samt Ursache und Fix. Ziel: nicht zweimal in dieselbe Falle laufen.

## Wann gehört ein Eintrag hier rein?

- Der Fehler hat **mehr als 10 Minuten Suche** gekostet.
- Die Symptome zeigten **nicht direkt** auf die Ursache (z. B. „Klick passiert nichts" → eigentlich Port-Mismatch).
- Die Lösung ist **nicht offensichtlich** aus dem Code-Diff allein nachvollziehbar.

Nicht hier rein gehören Tippfehler, vergessene `use`-Statements, kaputte Migrations beim Lokal-Setup o. Ä. – das sind keine wiederkehrenden Fallstricke.

## Eintragsvorlage

Beim Anlegen eines neuen Eintrags diese Vorlage kopieren und unter „Einträge" oben einfügen (neueste zuerst). Tags klein, mit Bindestrich, kommasepariert. Die Slug-Anker (`{#kebab-case-slug}`) ermöglichen direkte Verlinkung aus PRs/Issues.

```markdown
### <Kurztitel> {#kebab-case-slug}

- **Datum:** YYYY-MM-DD
- **Tags:** `tag1`, `tag2`
- **Erstmals beobachtet in:** #<PR-Nr> (Issue #<Nr> falls vorhanden)

**Symptom**
Was sieht man? Was deutete oberflächlich auf den Fehler hin?

**Ursache**
Was war wirklich los? Warum war es schwer zu sehen?

**Fix**
Was wurde geändert? Welche Datei(en)? Gibt es eine Regression-Test-Stelle?
```

## Inhalt

- [Inertia-XHR-Antworten brechen durch PHP-8.5-Deprecation-HTML](#inertia-xhr-php85-deprecation)
- [Inertia-Links scheinen ins Leere zu klicken (APP_URL/Serve-Port-Mismatch)](#inertia-link-app-url-port-mismatch)

---

## Einträge

### Inertia-XHR-Antworten brechen durch PHP-8.5-Deprecation-HTML {#inertia-xhr-php85-deprecation}

- **Datum:** 2026-04-25
- **Tags:** `inertia`, `php-8.5`, `deprecation`, `pdo`, `xhr`
- **Erstmals beobachtet in:** #151 (Issue #149)

**Symptom**
Client-seitige Navigation in Inertia bricht stumm ab. In der Browser-Konsole erscheint:

```
Cannot read properties of undefined (reading 'toString')
```

aus `Inertia.setPage()`. Klassische Seiten (HTML) funktionieren, nur Inertia-XHR-Antworten sind betroffen.

**Ursache**
PHP 8.5 hat `PDO::MYSQL_ATTR_SSL_CA` zugunsten der namespaced Variante `\Pdo\Mysql::ATTR_SSL_CA` deprecated. Mit `APP_DEBUG=true` schreibt PHP die Deprecation-Meldung als HTML (`<br /><b>Deprecated…</b>`) auf stdout – **vor** dem eigentlichen Response-Body. Browser ignorieren das in HTML-Antworten, aber bei `Content-Type: application/json` (Inertia-XHR) macht das vorangestellte HTML die JSON-Antwort unparsbar. Inertias `setPage()` bekommt `undefined` und crasht.

**Fix**
- `config/database.php`: bevorzugt `\Pdo\Mysql::ATTR_SSL_CA`, fällt auf die alte Konstante zurück, wenn die Klasse nicht existiert (PHP < 8.5). Damit wird auf keinem Pfad eine Deprecation emittiert.
- Regression-Test in `tests/Unit/Config/DatabaseConfigTest.php` schlägt fehl, sobald das Laden der DB-Config eine `E_DEPRECATED` triggert.

**Merksatz:** Wenn Inertia-XHRs JSON-Parser-Fehler werfen, nicht zuerst im JS-Bundle suchen – `curl -s -H "X-Inertia: true" …` liefert die rohe Antwort und entlarvt jeden HTML-Vorlauf sofort.

---

### Inertia-Links scheinen ins Leere zu klicken (APP_URL/Serve-Port-Mismatch) {#inertia-link-app-url-port-mismatch}

- **Datum:** 2026-04-25
- **Tags:** `inertia`, `ziggy`, `routing`, `env`, `dev-setup`
- **Erstmals beobachtet in:** #150 (Issue #149)

**Symptom**
Klicks auf Login-/Register-Links im lokalen Dev-Setup tun augenscheinlich nichts: keine Navigation, keine Fehlermeldung, keine Network-Aktivität auf dem laufenden `php artisan serve` (Port 8000).

**Ursache**
`APP_URL` war auf `http://localhost` (Port 80) gesetzt, `composer run dev` startet aber auf Port 8000. Ziggy serialisiert `APP_URL` in `window.Ziggy`, also generierten `route('login')` und `route('register')` Links auf `http://localhost/login` (Port 80). Dort lief nichts – die Navigation ging an einen toten Port, der Browser zeigt das stumm.

**Fix**
- `.env.example`: `APP_URL=http://localhost:8000` (passt zu `php artisan serve`).
- `app/Http/Middleware/WarnOnAppUrlMismatch.php`: loggt im `local`-Env eine Warnung, wenn die Browser-Origin von `APP_URL` abweicht. So fällt der nächste Mismatch auf, bevor man eine Stunde sucht.
- `.gitignore`: `/resources/js/ziggy.js` ignoriert (orphaned, wurde nicht importiert; Source of Truth ist die `@routes`-Blade-Direktive).
- Feature-Test in `tests/Feature/AppUrlMismatchWarningTest.php`.

**Merksatz:** Wenn Inertia-Links „nichts tun", zuerst `window.Ziggy.url` in der Browser-Console prüfen. Stimmt der Port nicht mit dem Dev-Server überein, ist es immer das.
