# Tenant Onboarding (Phase 2) — Focused Restyle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply direction-C visual consistency to the operator dashboard and public pages — a focused, token-unifying restyle (no deep layout overhaul): a shared `StatusBadge`, a dark app-shell topbar, dashboard onboarding-reminder cards, and the shared status badge on public pages.

**Architecture:** Extract a reusable `StatusBadge.vue` that consumes the existing `resources/js/lib/reservationStatus.ts` (token-based classes + German labels). The Dashboard replaces its hardcoded status map with it; public pages adopt it. The authenticated app-shell header (`AppSidebarHeader`) gets the dark `--topbar` tokens. The Dashboard renders the `onboardingReminders` prop (already supplied by the controller in Phase 1) as link cards. No backend changes; no functional regression.

**Tech Stack:** Inertia v2 + Vue 3.5 + TS, Tailwind v3.4 (existing `status.*` + `topbar` token classes), Vitest + `@vue/test-utils`, ESLint, Prettier.

---

## Design decisions resolved

1. **Focused/mechanical restyle** (chosen): unify status rendering via a shared component, dark app-shell topbar, reminder cards, public status badge. No table redesign / form redesign. Density/spacing tuning is a light visual-review pass, not part of the testable tasks.
2. **Dark topbar** on the authenticated app shell (`AppSidebarHeader`). The public centered card (`PublicLayout`) is NOT given a dark topbar (it has no topbar — it's a centered card); it only adopts the shared `StatusBadge`.
3. **No backend changes.** `onboardingReminders` is already passed by `DashboardController` (#405); Phase 2 only renders it.
4. **Reminders are not separately dismissible.** A card disappears once its optional step is done (the prop empties), so no persistent dismiss state is needed; each card links to where the step is completed.

> **Note on lint (learned in Phase 1b):** in a Vue `<script setup>`, do not write `const props = defineProps(...)` when `props` is only read in the template — ESLint `no-unused-vars` fails CI. Use `defineProps(...)` without assignment, or reference `props.x` in script.

---

## File Structure

**Created**
- `resources/js/components/StatusBadge.vue` — shared status pill (status → token class + label).
- `resources/js/components/StatusBadge.test.ts`
- `resources/js/components/OnboardingReminders.vue` — dashboard reminder cards from the `onboardingReminders` prop.
- `resources/js/components/OnboardingReminders.test.ts`

**Modified**
- `resources/js/pages/Dashboard.vue` — use `StatusBadge`; remove hardcoded `STATUS_BADGE_CLASS` (and `STATUS_LABEL` if it becomes unused); declare + render `onboardingReminders` via `OnboardingReminders`.
- `resources/js/components/AppSidebarHeader.vue` — dark topbar (`bg-topbar text-topbar-foreground`).
- `resources/js/pages/Public/GdprSelfService.vue` — render status via `StatusBadge` (replacing the plain-text status), drop its local `STATUS_LABELS` map.
- `resources/js/types/index.ts` — `onboardingReminders` typing if needed.

> **Reference patterns:** `reservationStatus.ts` (`statusBadgeClass`/`statusLabel`); the current Dashboard badge span (`Dashboard.vue` ~lines 742–757); the Vitest style in `resources/js/lib/reservationStatus.test.ts` and `resources/js/composables/useRowSelection.component.test.ts`.

---

# Epic A — Shared StatusBadge

### Task A1: `StatusBadge.vue` + Vitest

**Files:**
- Create: `resources/js/components/StatusBadge.vue`
- Create: `resources/js/components/StatusBadge.test.ts`

- [ ] **Step 1: Write the failing test**

```typescript
// resources/js/components/StatusBadge.test.ts
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import StatusBadge from './StatusBadge.vue';

describe('StatusBadge', () => {
    it('renders the token class and German label for a status', () => {
        const wrapper = mount(StatusBadge, { props: { status: 'confirmed' } });

        expect(wrapper.text()).toBe('Bestätigt');
        expect(wrapper.classes().join(' ')).toContain('text-status-confirmed');
    });

    it('renders cancelled (the 7th status) too', () => {
        const wrapper = mount(StatusBadge, { props: { status: 'cancelled' } });

        expect(wrapper.text()).toBe('Storniert');
        expect(wrapper.classes().join(' ')).toContain('status-cancelled');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test -- StatusBadge`
Expected: FAIL — component missing.

- [ ] **Step 3: Write the component**

```vue
<script setup lang="ts">
import { statusBadgeClass, statusLabel, type ReservationStatus } from '@/lib/reservationStatus';

const props = defineProps<{ status: ReservationStatus }>();
</script>

<template>
    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadgeClass(props.status)">
        {{ statusLabel(props.status) }}
    </span>
</template>
```

> `props` is referenced in the template via `props.status`; it's also read in script if you prefer — either is lint-safe here because `statusBadgeClass(props.status)` is in the template binding. To be safe against the no-unused-vars rule, the component reads `props.status` in the template bindings above (no bare unused `props`).

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test -- StatusBadge`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/StatusBadge.vue resources/js/components/StatusBadge.test.ts
git commit -m "Add shared StatusBadge component (direction-C tokens)"
```

---

# Epic B — Dashboard adopts StatusBadge

### Task B1: Replace the hardcoded status map with `StatusBadge`

**Files:**
- Modify: `resources/js/pages/Dashboard.vue`

- [ ] **Step 1: Confirm current usages**

Run: `grep -n "STATUS_BADGE_CLASS\|STATUS_LABEL\|STATUS_OPTIONS" resources/js/pages/Dashboard.vue`
Note which of `STATUS_LABEL` / `STATUS_OPTIONS` are used beyond the badge (the filter chips likely use `STATUS_OPTIONS`). Keep `STATUS_OPTIONS`; only remove what becomes unused.

- [ ] **Step 2: Import and use the component**

Add the import:

```typescript
import StatusBadge from '@/components/StatusBadge.vue';
```

Replace the badge span (the `<span ... :class="STATUS_BADGE_CLASS[row.status]">{{ STATUS_LABEL[row.status] }}</span>`, ~lines 742–757) with:

```html
<StatusBadge :status="row.status" />
```

- [ ] **Step 3: Remove dead code**

Delete the `STATUS_BADGE_CLASS` map (lines ~97–105). **Keep `STATUS_LABEL`** — it is still used by the drawer (`STATUS_LABEL[props.selectedRequest.status]`, ~line 838), and keep `STATUS_OPTIONS` (filter chips, ~line 605). Only `STATUS_BADGE_CLASS` becomes dead.

- [ ] **Step 4: Verify build + lint + the existing reservationStatus test**

Run: `npm run build && npm run lint:check && npm run format:check && npm run test`
Expected: clean; `reservationStatus.test` + `StatusBadge.test` green. The status pills now render with `status-*` tokens (visual: confirm the badge colours match direction C).

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/Dashboard.vue
git commit -m "Dashboard: render statuses via shared StatusBadge"
```

---

# Epic C — Dashboard onboarding reminder cards

### Task C1: `OnboardingReminders.vue` + Vitest

**Files:**
- Create: `resources/js/components/OnboardingReminders.vue`
- Create: `resources/js/components/OnboardingReminders.test.ts`

- [ ] **Step 1: Write the failing test**

```typescript
// resources/js/components/OnboardingReminders.test.ts
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

// Ziggy route() global — vi.stubGlobal matches the repo convention
// (see Tables.test.ts / Notifications.component.test.ts).
vi.stubGlobal('route', () => '/onboarding');

import OnboardingReminders from './OnboardingReminders.vue';

const stubs = { Link: { template: '<a><slot /></a>' } };

describe('OnboardingReminders', () => {
    it('renders a card per pending optional step', () => {
        const wrapper = mount(OnboardingReminders, { props: { reminders: ['team', 'tonality'] }, global: { stubs } });

        expect(wrapper.findAll('[data-testid="onboarding-reminder"]')).toHaveLength(2);
        expect(wrapper.text()).toContain('Team');
    });

    it('renders nothing when there are no reminders', () => {
        const wrapper = mount(OnboardingReminders, { props: { reminders: [] }, global: { stubs } });

        expect(wrapper.findAll('[data-testid="onboarding-reminder"]')).toHaveLength(0);
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test -- OnboardingReminders`
Expected: FAIL — component missing.

- [ ] **Step 3: Write the component**

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

type OptionalStep = 'tonality' | 'team';

defineProps<{ reminders: OptionalStep[] }>();

const COPY: Record<OptionalStep, { title: string; body: string }> = {
    tonality: { title: 'Tonalität festlegen', body: 'Bestimmen Sie, wie die KI-Antworten klingen.' },
    team: { title: 'Team einladen', body: 'Laden Sie Mitarbeitende zu Ihrem Restaurant ein.' },
};
</script>

<template>
    <div v-if="reminders.length > 0" class="grid gap-3 sm:grid-cols-2">
        <Link
            v-for="step in reminders"
            :key="step"
            data-testid="onboarding-reminder"
            :href="route('onboarding.wizard')"
            class="rounded-lg border border-border bg-card p-4 text-sm transition hover:border-primary"
        >
            <p class="font-medium">{{ COPY[step].title }}</p>
            <p class="text-muted-foreground">{{ COPY[step].body }}</p>
        </Link>
    </div>
</template>
```

> The reminders link to the wizard (its optional steps remain reachable for a live restaurant). If a dedicated settings deep-link is preferred later, swap the `:href`.

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test -- OnboardingReminders`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/OnboardingReminders.vue resources/js/components/OnboardingReminders.test.ts
git commit -m "Add OnboardingReminders dashboard cards component"
```

---

### Task C2: Render reminders on the Dashboard

**Files:**
- Modify: `resources/js/pages/Dashboard.vue`

- [ ] **Step 1: Declare the prop**

Add `onboardingReminders` to the Dashboard `defineProps` (type `('tonality' | 'team')[]`, default `[]` — confirm the existing props block style; if it uses a `Props` interface, add the field there).

- [ ] **Step 2: Render the component**

Import `OnboardingReminders` and place it near the top of the page content (above the table / under the breadcrumbs), e.g.:

```html
<OnboardingReminders :reminders="onboardingReminders" class="mb-4" />
```

- [ ] **Step 3: Verify the dashboard still loads with reminders**

The Phase-1 backend test `DashboardOnboardingRemindersTest` already asserts the prop is present. Confirm `npm run build` + `npm run lint:check` are clean and the page renders (visual: a live restaurant without staff shows the "Team einladen" card).

- [ ] **Step 4: Commit**

```bash
git add resources/js/pages/Dashboard.vue resources/js/types/index.ts
git commit -m "Dashboard: render onboarding reminder cards"
```

---

# Epic D — Dark app-shell topbar

### Task D1: Dark `AppSidebarHeader`

**Files:**
- Modify: `resources/js/components/AppSidebarHeader.vue`
- Create: `resources/js/components/AppSidebarHeader.test.ts`

- [ ] **Step 1: Write the failing test**

```typescript
// resources/js/components/AppSidebarHeader.test.ts
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import AppSidebarHeader from './AppSidebarHeader.vue';

const stubs = {
    SidebarTrigger: true,
    Breadcrumb: true, BreadcrumbItem: true, BreadcrumbLink: true,
    BreadcrumbList: true, BreadcrumbPage: true, BreadcrumbSeparator: true,
};

describe('AppSidebarHeader', () => {
    it('uses the dark topbar tokens', () => {
        const wrapper = mount(AppSidebarHeader, { props: { breadcrumbs: [] }, global: { stubs } });

        const header = wrapper.find('header');
        expect(header.classes()).toContain('bg-topbar');
        expect(header.classes()).toContain('text-topbar-foreground');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test -- AppSidebarHeader`
Expected: FAIL — header has no `bg-topbar`.

- [ ] **Step 3: Apply the dark topbar**

In `AppSidebarHeader.vue`, add `bg-topbar text-topbar-foreground` to the `<header>` class and switch the border to a topbar-appropriate one, e.g.:

```html
<header
    class="flex h-16 shrink-0 items-center gap-2 border-b border-topbar/40 bg-topbar px-6 text-topbar-foreground transition-[width,height] ease-linear group-has-[[data-collapsible=icon]]/sidebar-wrapper:h-12 md:px-4"
>
```

(The `SidebarTrigger` and breadcrumb text inherit `text-topbar-foreground`; confirm contrast visually.)

- [ ] **Step 4: Run test + build**

Run: `npm run test -- AppSidebarHeader && npm run build && npm run lint:check && npm run format:check`
Expected: PASS / clean. Visual: the authenticated header bar is dark.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/AppSidebarHeader.vue resources/js/components/AppSidebarHeader.test.ts
git commit -m "App shell: dark direction-C topbar header"
```

---

# Epic E — Public pages status badge

### Task E1: `StatusBadge` on the public GDPR self-service page

**Files:**
- Modify: `resources/js/pages/Public/GdprSelfService.vue`

- [ ] **Step 1: Replace the plain-text status**

Import `StatusBadge`. Replace the status `<dd>` (currently `<dd class="font-medium">{{ statusLabel }}</dd>`) with:

```html
<dd><StatusBadge :status="(reservation.status as ReservationStatus)" /></dd>
```

Remove the local `STATUS_LABELS` map and the `statusLabel` computed (now provided by the component), importing `type ReservationStatus` from `@/lib/reservationStatus`.

- [ ] **Step 2: Verify**

Run: `npm run build && npm run lint:check && npm run format:check`
Expected: clean. Visual: the GDPR self-service page shows a token-coloured status pill consistent with the dashboard.

> Note: `ConfirmedSync.vue` / `Thanks.vue` show no reservation status, so they need no badge — they already use `PublicLayout` + tokens and stay as-is. Scope-checked: no other public page renders a status.

- [ ] **Step 3: Commit**

```bash
git add resources/js/pages/Public/GdprSelfService.vue
git commit -m "Public GDPR page: shared StatusBadge for status display"
```

---

# Final verification

> **CI gap (important):** the CI frontend job runs only ESLint + Prettier + `vite build` — it does **not** run Vitest and `vite build` does **not** type-check. So green CI does NOT prove the new component tests pass or that types are sound. Run `npm run test` locally as the real gate for the StatusBadge/OnboardingReminders/AppSidebarHeader tests, and watch for type issues by hand (e.g. the `as ReservationStatus` cast in E1).

- [ ] **Step 1: Frontend** — `npm run test && npm run build && npm run lint:check && npm run format:check` all green **locally** (Vitest is local-only; CI won't catch a red Vitest).
- [ ] **Step 2: Backend regression** — `php artisan test` (no backend changed, but Inertia component-name assertions for Dashboard/public pages must still hold) + `./vendor/bin/pint --test`.
- [ ] **Step 3: Visual review (manual, recommended)** — log in (dark topbar + status pills + reminder card on the dashboard), open the public GDPR self-service link (token status pill). Confirm direction-C consistency and no layout regressions.

---

## Acceptance-criteria coverage (PRD-016 §Phase 2)

| PRD criterion | Task(s) |
|---|---|
| Shared `StatusBadge` from `reservationStatus.ts`; Dashboard drops the hardcoded map | A1, B1 |
| Dark topbar in the authenticated app shell | D1 |
| Onboarding reminder cards rendered on the dashboard | C1, C2 |
| Public pages adopt the shared status badge / tokens | E1 |
| No functional regression; build/lint/format/tests green | Final verification |
