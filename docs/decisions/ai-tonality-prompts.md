# AI Tonality Prompts — Pilot Iteration

**Status:** open. Placeholder texts shipped in `config/reservations.php`. Final text requires pilot-restaurant collaboration before PRD-005 GA.

**Tracking issue:** [#80](https://github.com/Psheikomaniac/reservation-agent/issues/80)

---

## Why this is a doc, not code

Prompt quality determines product quality more than any single line of code in this PRD. The wording — formal vs. casual vs. family — has to come from the operators who will run it, not from the engineers who wired it up. This file captures the iteration framework so the conversation with the pilot restaurant has a clear contract.

---

## Definition of Done (PRD-005)

Each of the three tonalities (`formal`, `casual`, `family`) is finalised when:

- [ ] **Five canonical scenarios** all produce a send-ready reply with no operator edits required:
  1. **Frei** — desired time inside opening hours, capacity available.
  2. **Voll** — desired time inside opening hours but `seats_free < party_size`.
  3. **Ruhetag** — `closed_reason = 'ruhetag'`.
  4. **Außerhalb der Öffnungszeit** — `closed_reason = 'ausserhalb_oeffnungszeiten'`.
  5. **Großgruppe** — party size at or near the configured maximum.

- [ ] **Hallucination spot-check, 20 generations across the five scenarios above:**
  - 0 invented numbers (capacity, party size, time).
  - 0 invented names (guest, dish, contact).
  - 0 invented reasons (e.g. claiming an event when none was provided).

- [ ] **Finalised texts replace the placeholders** in `config/reservations.php` (`reservations.ai.tonality_prompts.*`).
- [ ] The seven constant rules in `reservations.ai.system_prompt_rules` remain pinned by `OpenAiReplySystemPromptRulesTest` after the change.

---

## Process

1. **Pilot intake.** A single restaurant agrees to run the assistant in shadow mode (drafts visible, never sent unedited) for ≥ 14 days.
2. **Generation log.** Every draft is captured with its full Context-JSON snapshot (already persisted via `ai_prompt_snapshot`). The pilot's edits are logged via the SQL diff between `body` at draft-time and `body` at approve-time.
3. **Weekly review.** Engineering + pilot-owner walk through last week's edits. Each non-trivial edit becomes either:
   - a tonality-prompt change (preferred), or
   - a new constant rule (if the failure mode is tonality-independent — e.g. "never promise a confirmed reservation").
4. **Cut-over.** When two consecutive weeks pass the DoD, the tonality is promoted: the placeholder string in `config/reservations.php` is replaced. The PR carries a sample of 10 generations from the final week as evidence.

---

## What we deliberately don't do here

- **No A/B testing infrastructure in V1.0.** Per-tonality A/B requires a sampling layer that V1.0 does not have. Pilot iteration is sequential; we accept that this trades scientific rigor for time-to-market.
- **No prompt versioning per restaurant.** All restaurants of the same tonality share the same prompt. Per-restaurant overrides are a V2.0 extension and are explicitly out of scope of this issue.

---

## Current placeholders

The shipped texts in `config/reservations.php` are stand-ins ending with the literal string "Platzhalter — finaler Text wird mit Pilot-Restaurant abgestimmt." This sentinel makes accidental production deployment of placeholder text discoverable via `grep -r 'Platzhalter' config/`.

When the pilot signs off on a tonality, the sentinel is removed in the same PR that lands the final text.
