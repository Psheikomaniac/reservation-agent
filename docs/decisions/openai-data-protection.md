# Data Protection — OpenAI Transfers (V1.0) and V1.1 Provider Options

**Status:** open. Decision points need legal sign-off before PRD-005 goes to a paying customer.

**Tracking issue:** [#81](https://github.com/Psheikomaniac/reservation-agent/issues/81)

---

## Why this is a doc, not code

V1.0 ships with `openai-php/laravel` in BYOK mode (the customer's own OpenAI API key). That model already shifts the bulk of the controller-vs-processor question to the customer, but the transfer itself is still in scope of the customer's privacy policy and AVV/DPA. None of that is solvable in code; the engineering deliverable is to keep the surface clean for the legal answer.

What this file does NOT do:

- Draft the privacy-policy paragraph.
- Provide a DPA / AVV template.
- Evaluate Azure OpenAI or Anthropic Claude as drop-in providers.

What this file DOES do:

- Pin what data the system actually sends to OpenAI today, so the legal text isn't written against a guess.
- List the questions a privacy-policy paragraph has to answer.
- Capture the V1.1 provider-options framework so the V1.0 architecture stays portable.

---

## What we send to OpenAI today

The user message in the OpenAI chat call is exactly the JSON produced by `ReservationContextBuilder::build()` (PR #170). Concretely, every request to OpenAI carries:

| Field | Source | Personal data? |
|------|--------|----------------|
| `restaurant.name` | restaurant config | no |
| `restaurant.tonality` | restaurant config | no |
| `request.guest_name` | guest input on form / parsed mail | **yes — Art. 4(1) GDPR** |
| `request.party_size` | guest input | no |
| `request.desired_at` | guest input (restaurant local time) | no |
| `request.message` | guest input (free-text note) | **yes if guest writes anything personal** |
| `availability.*` | computed by Laravel | no |

The system message is fixed prompt text from `config/reservations.php` and contains no guest data.

This shape is also what `ai_prompt_snapshot` persists, byte-identical (PR #181).

**What we never send:** guest email, guest phone, raw email body, IP address, message-ID, OAuth tokens. None of those are part of the Context-JSON.

---

## Privacy-policy paragraph — questions to answer

The pilot restaurant's privacy policy (or this product's standard clause for self-hosted customers) needs to answer:

1. **Who is the processor?** OpenAI Ireland Ltd. (EU) for OpenAI's standard EU residency, OpenAI L.L.C. (US) otherwise. Document which one.
2. **Which fields are transferred?** See the table above.
3. **Storage retention at the processor?** OpenAI's API standard policy: 30 days, no training. Cite OpenAI's data-usage page on the date of the AVV.
4. **Legal basis for the transfer?** Most likely Art. 6(1)(b) GDPR — necessary for performance of contract with the guest.
5. **Right to objection?** Practically: turn off the AI assistant (no replacement for the guest data once transferred).

Output: a paragraph that the pilot's lawyer reviews. Land it under `docs/legal/privacy-paragraph.md` once approved. Out of scope of this issue to draft.

---

## DPA / AVV — what the customer needs

For German B2B customers an Auftragsverarbeitungsvertrag (AVV) is required if WE are the processor. In BYOK mode the customer signs a DPA directly with OpenAI (their key, their account). We are *not* a processor of guest data in that path — we are a software-as-a-service provider whose code happens to call OpenAI on the customer's behalf.

If that legal interpretation holds (it needs sign-off): no AVV between us and the customer is needed for OpenAI specifically. An AVV is still needed for everything else we host — DB, mail logs, server access — but that's separate from this issue.

---

## V1.1 provider options

The reason `OpenAiReplyGenerator` implements `App\Services\AI\Contracts\ReplyGenerator` (PR #175) is to keep the swap cheap. Options when the OpenAI legal answer doesn't fit a customer:

| Provider | Status | Effort to swap |
|----------|--------|----------------|
| **Azure OpenAI** (EU residency, Microsoft DPA) | natural V1.1 candidate | small — same OpenAI SDK, different `base_uri` and key. New `AzureOpenAiReplyGenerator` implements `ReplyGenerator`, container binds it per-restaurant or per-tenant. |
| **Anthropic Claude** (BYOK) | natural V1.1 candidate | medium — different SDK, same prompt structure. New `AnthropicReplyGenerator` implements `ReplyGenerator`. The system-prompt rules (`config/reservations.ai.system_prompt_rules`) are provider-agnostic and ride along unchanged. |
| **Local LLM (Ollama / vLLM)** | V3.0+ | large — different operational model (self-hosted), different latency/quality profile. Out of scope of this decision. |

V1.0 ships only OpenAI. V1.1 evaluation is gated on the first customer that asks. The architectural prep (interface + container binding) is already done so the V1.1 PR is a single new generator class plus a config flip.

---

## Decision triggers

This file is updated — and a new issue cut — when any of:

1. The pilot's lawyer returns the privacy-policy paragraph.
2. A customer asks for a non-OpenAI provider.
3. OpenAI's data-handling policy changes materially.
4. EU regulators issue new guidance on LLM transfers (e.g. EDPB).
