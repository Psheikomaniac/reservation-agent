# PRD-010: Push & Sound-Alerts

**Produkt:** reservation-agent
**Version:** V2.0
**Priorität:** P2 – operativer Komfort, baut auf PRD-004 (Polling) auf
**Entwicklungsphase:** V2 (parallel zu Phase 2/3)

---

## Problem

PRD-004-Polling lädt das Dashboard alle 30 Sekunden neu, aber **nur wenn der Tab im Vordergrund ist**. In der Realität:

- Owner haben das Dashboard nebenbei in einem Tab offen, arbeiten aber an anderen Dingen.
- Bei kurzfristigen Anfragen („Tisch für heute Abend, 4 Personen, 19:30") sind 30 Sekunden Verzögerung **plus** „Owner muss den Tab anschauen" zu lang – die Anfrage ist verloren, bevor sie gesehen wurde.
- Außerhalb der Öffnungszeiten (z. B. 14–17 Uhr Pause) liegt das Dashboard niemandes Hand. Es entsteht ein toter Zeitraum, in dem Anfragen liegen bleiben.

V1.0 lebt mit dieser Lücke, weil sie kein Killer ist – aber sie kostet Reaktionsgeschwindigkeit, was ein zentrales Verkaufsargument ist.

## Ziel

Sofortige akustische und visuelle Benachrichtigung über neue Anfragen via **Browser Notifications API** plus **optionalem Sound-Alert**. Beides per User-Setting de-/aktivierbar. Zusätzlich ein **täglicher E-Mail-Digest** als Fallback für User, die Browser-Notifications blockieren.

Kein Service Worker, kein Push API, keine externe Infrastruktur. Notifications laufen **in dem Tab, der das Dashboard offen hat** – das deckt 90 % der Pilot-Use-Cases zu Null Infrastruktur-Kosten.

---

## Scope V2.0

### In Scope

- User-Settings `notification_settings` (JSON) am bestehenden `users`-Model
- Settings-UI als Page `resources/js/pages/Settings/Notifications.vue`:
  - Browser-Notifications: an / aus
  - Sound-Alerts: an / aus + Lautstärke (0–100)
  - Sound-Auswahl: 1 Default-Sound + 2 Alternativen
  - Daily Digest: an / aus + Zeit (Default 18:00 Restaurant-Zeit)
- Composable `useNotifications` für Permission-Handling, Trigger
- Trigger-Punkt: Dashboard-Polling (PRD-004) erkennt neue Requests im Diff zwischen Reloads → Notification + Sound
- Permission-Request: nur bei aktiver Owner-Aktion („Notifications aktivieren"-Button), nie automatisch beim Dashboard-Load
- Email-Digest:
  - Job `SendDailyDigestJob`, scheduled täglich, dispatcht pro Restaurant je nach User-Setting
  - Mail enthält: Anzahl Anfragen heute, Anzahl bestätigt, Anzahl offen, Anzahl mit `needs_manual_review`
  - Direktlink ins Dashboard
- Audio-Files: 3 kurze Töne (~0.5 s) im `public/sounds/` als MP3, gemeinfrei
- Frontend-Lautstärke via `HTMLAudioElement.volume`

### Out of Scope

- Browser-Push außerhalb des offenen Tabs (Service Worker + Push API) – V3.0
- Mobile Push – V3.0 (gehört zu Mobile App)
- SMS-Alerts – V3.0 oder darüber hinaus
- Slack-/Teams-Integration – V3.0
- Custom-Alarme („nur bei > 6 Personen") – V2.1, falls Pilot-Bedarf

---

## Technische Anforderungen

### Datenmodell

`users.notification_settings` (JSON, Default `{}`):

```json
{
  "browser_notifications": false,
  "sound_alerts": false,
  "sound": "default",
  "volume": 70,
  "daily_digest": true,
  "daily_digest_at": "18:00"
}
```

Migration ergänzt die Spalte; `notification_settings` wird als JSON-Cast `'array'` ans User-Model gehängt. Default-Werte werden beim ersten Setting-Load aus einem Service `NotificationSettings::default()` gemerged, damit neue Felder rückwärtskompatibel sind, ohne Migration jedes bestehenden Users.

### Composable `useNotifications`

`resources/js/composables/useNotifications.ts`:

```ts
export function useNotifications(settings: Ref<NotificationSettings>) {
  const permission = ref<NotificationPermission>(typeof Notification !== 'undefined' ? Notification.permission : 'denied');

  async function requestPermission(): Promise<NotificationPermission> {
    if (typeof Notification === 'undefined') return 'denied';
    permission.value = await Notification.requestPermission();
    return permission.value;
  }

  function notify(title: string, options: NotificationOptions = {}): Notification | null {
    if (!settings.value.browser_notifications) return null;
    if (permission.value !== 'granted') return null;
    return new Notification(title, options);
  }

  function playSound(soundKey: string = settings.value.sound): void {
    if (!settings.value.sound_alerts) return;
    const audio = new Audio(`/sounds/${soundKey}.mp3`);
    audio.volume = clamp(settings.value.volume / 100, 0, 1);
    audio.play().catch(() => { /* user gesture missing – Browser blockt, ist ok */ });
  }

  return { permission, requestPermission, notify, playSound };
}
```

Browser-Quirks bewusst eingerechnet:

- `Notification` ist in einigen Test-Umgebungen nicht definiert → defensive Checks.
- `audio.play()` benötigt User-Gesture beim ersten Ton; bei abgelehntem Promise schweigend weitermachen, **nicht** im UI als Fehler anzeigen.

### Trigger im Dashboard

`Dashboard.vue` erweitert die Polling-Logik aus PRD-004:

```ts
const previousIds = ref<Set<number>>(new Set());

onUpdated(() => {
  const currentIds = new Set(props.requests.data.map((r: Request) => r.id));
  const newIds = [...currentIds].filter((id) => !previousIds.value.has(id));

  if (previousIds.value.size > 0 && newIds.length > 0) {
    notifications.notify('Neue Reservierungsanfrage', {
      body: `${newIds.length} neue Anfrage${newIds.length > 1 ? 'n' : ''} – jetzt anschauen`,
      tag: 'reservation-agent-new-request',
    });
    notifications.playSound();
  }

  previousIds.value = currentIds;
});
```

Diff-basiert – damit der erste Page-Load **keine** Notification triggert. Tag-basierter De-Duplication-Hint im Notification-Tag, damit mehrere Notifications nicht stapeln.

### Email-Digest

`app/Jobs/SendDailyDigestJob.php`:

```php
public function handle(): void
{
    $users = User::whereJsonContains('notification_settings->daily_digest', true)
        ->whereNotNull('restaurant_id')
        ->with('restaurant')
        ->get();

    foreach ($users as $user) {
        if (!$this->shouldSendNow($user)) {
            continue;
        }
        Mail::to($user)->send(new DailyDigestMail($this->summaryFor($user)));
    }
}

private function shouldSendNow(User $user): bool
{
    $sendAt = $user->notification_settings['daily_digest_at'] ?? '18:00';
    $tz     = $user->restaurant->timezone;
    $now    = now()->setTimezone($tz);

    return $now->format('H:i') === $sendAt;
}

private function summaryFor(User $user): DigestSummary
{
    $restaurant = $user->restaurant;
    $today = now()->setTimezone($restaurant->timezone)->startOfDay();

    return new DigestSummary(
        restaurantName: $restaurant->name,
        totalToday:     ReservationRequest::where('restaurant_id', $restaurant->id)->whereDate('created_at', $today)->count(),
        confirmed:      ReservationRequest::where('restaurant_id', $restaurant->id)->whereDate('created_at', $today)->where('status', ReservationStatus::Confirmed)->count(),
        pending:        ReservationRequest::where('restaurant_id', $restaurant->id)->whereDate('created_at', $today)->whereIn('status', [ReservationStatus::New, ReservationStatus::InReview])->count(),
        needsReview:    ReservationRequest::where('restaurant_id', $restaurant->id)->whereDate('created_at', $today)->where('needs_manual_review', true)->count(),
        dashboardUrl:   route('dashboard'),
    );
}
```

`Schedule` in `routes/console.php`:

```php
Schedule::job(new SendDailyDigestJob())->hourly();
```

Stündliches Trigger-Intervall, weil verschiedene Restaurant-Zeitzonen den exakten Sende-Slot verschieben. `shouldSendNow` filtert intern auf den User-Zeit-Match.

### Audio-Assets

`public/sounds/`:

- `default.mp3` – kurzer, neutraler Hinweiston (~500 ms)
- `chime.mp3` – freundliches Glöckchen
- `tap.mp3` – kurzes Klopfen

Lizenz: gemeinfrei oder CC0. Lizenz-Eintrag in `public/sounds/LICENSE.txt`. Files < 30 KB jeweils.

### Permission-Flow im UI

`Settings/Notifications.vue`:

1. Beim ersten Laden: aktuellen `Notification.permission`-Status anzeigen.
2. Wenn `default`: Toggle „Browser-Notifications aktivieren" → on-click `requestPermission()` aufrufen.
3. Wenn `granted`: Toggle steuert nur das User-Setting; Browser-Permission bleibt unangetastet.
4. Wenn `denied`: Toggle disabled, Hinweistext „Du hast Notifications für diese Seite blockiert. Aktiviere sie in den Browser-Einstellungen."
5. „Ton testen"-Button für jeden der drei Sounds (mit aktueller Volume-Einstellung).

Daily-Digest-Toggle und -Zeit sind unabhängig vom Browser-Permission-Status.

---

## Akzeptanzkriterien

- [ ] User kann Notifications/Sound/Digest unabhängig de-/aktivieren
- [ ] Permission-Request erscheint NUR nach explizitem Owner-Klick auf „Aktivieren"
- [ ] Bei neuer Anfrage im Dashboard: Browser-Notification wird gezeigt (wenn aktiviert + permission granted)
- [ ] Bei neuer Anfrage: Sound spielt mit konfigurierter Lautstärke (wenn aktiviert)
- [ ] Erster Page-Load triggert KEINE Notification (Diff-Logik)
- [ ] Notification-Tag verhindert Stapeln mehrerer Reservation-Notifications
- [ ] „Ton testen"-Button im Settings-UI spielt den ausgewählten Sound mit der eingestellten Lautstärke
- [ ] Daily Digest läuft stündlich, sendet pro User exakt einmal in 24 h zur konfigurierten Zeit
- [ ] Daily Digest enthält Zahlen für: gesamt, bestätigt, offen, manuelle Prüfung
- [ ] Direktlink im Digest geht ins Dashboard
- [ ] Mandantentrennung: Digest enthält nur Daten des eigenen Restaurants
- [ ] User ohne `restaurant_id` (Admin) erhält keinen Digest
- [ ] Settings persistieren über Sessions hinweg
- [ ] Browser ohne Notifications-API fällt auf „nur Sound + Digest"-Modus zurück (kein JS-Fehler)
- [ ] Alle Tests grün, Pint/ESLint/Prettier ohne Findings

---

## Tests

**Frontend (Vitest, `resources/js/composables/useNotifications.test.ts`)**

- `it does not call Notification when browser_notifications is off`
- `it does not call Notification when permission is not granted`
- `it triggers Notification with correct title when allowed`
- `it returns denied permission when Notification API is undefined`
- `it does not throw when audio.play rejects`
- `it clamps volume to [0, 1]`

**Frontend (`Dashboard.notifications.test.ts`)**

- `it does not notify on initial load`
- `it notifies when polling adds new request ids`
- `it suppresses notification when user disabled browser_notifications`

**Feature (`tests/Feature/Notifications/DailyDigestTest.php`)**

- `it sends digest at user configured time in restaurant timezone` (mit `Carbon::setTestNow`)
- `it does not send digest when daily_digest is off`
- `it sends digest only once per user per day`
- `it includes correct counts for today` (mit Seed-Daten)
- `it skips users without restaurant_id`
- `it includes dashboard URL`

**Feature (`tests/Feature/Notifications/SettingsTest.php`)**

- `it persists notification settings`
- `it merges defaults for missing keys`
- `it forbids editing other users settings`

---

## Risiken & offene Fragen

- **Browser-Block** – wenn der Owner Notifications für die Domain global blockiert (passiert oft), bleibt nur Sound + Digest. Die UI muss das transparent zeigen (denied-State explizit ausweisen, nicht stillschweigend). Das ist die wichtigste UX-Falle.
- **Sound nervt schnell** – Default OFF ist Pflicht. Nach 5+ Triggern in 10 Minuten könnte ein Hint im UI erscheinen („Soll der Ton zwischen 22 und 8 Uhr stumm bleiben?"). Das geht aber ins V2.1-Polishing.
- **Polling-Diff im Dashboard** – die Diff-Logik basiert auf den IDs der aktuellen Page. Wenn der Owner gerade Filter umgeschaltet hat, kommen „neue" IDs durch das Filterwechseln, nicht durch echte Neuanfragen. Lösung: Diff nur dann auswerten, wenn `filters` zwischen den Polls **identisch** sind (Vergleich des Filter-Snapshots). Tests decken den Filter-Wechsel ab.
- **Daily-Digest-Zeitzonen** – Restaurants in verschiedenen Zeitzonen haben unterschiedliche „18:00". Stündlicher Job + interner Zeit-Match ist der einfachste robuste Ansatz; bei Tausenden Restaurants müsste man auf Cron-pro-Zeitzone wechseln, aber das ist V3.0+.
- **Audio-Lizenzen** – nur gemeinfreie oder CC0-Sounds verwenden, Lizenzdatei mitliefern. Klingt trivial, ist aber ein klassisches Open-Source-PR-Reject-Reason.
- **Mehrere Tabs** – wenn der Owner das Dashboard in zwei Tabs offen hat, klingt der Sound zweimal. Akzeptabler V2.0-Tradeoff (BroadcastChannel-API für Cross-Tab-Sync ist möglich, aber Overengineering für den Pilot).
