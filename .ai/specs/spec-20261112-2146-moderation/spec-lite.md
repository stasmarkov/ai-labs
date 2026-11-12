---
id: spec-20261112-2146-moderation
title: Listing moderation classifier
type: feature
size: M
priority: P2
status: approved
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-12; standing approval)
approved_at: 2026-11-12T19:48:23Z
created: 2026-11-12
stacks: [laravel]
slice: SL-26
brs: [BR-26, BR-27, BR-90, BR-91, BR-93]
---

# Listing moderation classifier

## Summary

A `ModerationAgent` reads a listing (title, price, year, mileage, city, description) together with **facts the app computes**, and answers with a fixed shape: `{risk 0..1, reasons[], explanation}`. Reasons come from a fixed list. The answer is validated in PHP; an answer that does not match the shape is rejected, retried once and logged (BR-93).

- **Feature key:** `moderation` in `AiProviders`, shown on `/ai/settings`.
- **Eval set:** a 100-listing labelled set covers clean listings, hard negatives and four scam patterns.
- **`moderation:eval`:** calibrates the risk threshold and reports, for qwen3.5:9b and gemma4:12b, accuracy, precision and recall for "risky", reason recall, JSON-valid rate and latency.
- **Lab page:** `/ai/moderation` lets a learner paste or pick a listing.
- **Guide:** a capability guide explains the classifier.

## Context

- **Signals the text cannot carry.** Two of the four scam signals need data the text does not hold. "Price far below market" needs the market price, and "copied VIN" needs a register of reported VINs. Listings have no VIN column; the seeded descriptions mention none.
- **Facts the app computes**, given to the model:
  - `market_median_eur`: the median price of listings with the same make and model and year ±1 (null with fewer than 5 such listings);
  - `price_ratio`: price ÷ median;
  - `vins_in_text`: VINs found in the text by a 17-character regex that excludes I, O and Q;
  - `vin_reported`: true when any of them is in the fixture register `resources/moderation/reported-vins.php`.

  The model judges with these facts. The guide teaches why grounding a model in computed facts beats asking it to know prices.
- **User impact:** a learner sees classification with a closed label set, threshold calibration, the cost of false positives, and model output checked in PHP.

## Scope

### In scope

- **Agent** `App\Ai\ModerationAgent`: `HasStructuredOutput`, versioned prompt `resources/prompts/moderation.v1.md`, feature `moderation` (`AI_MODERATION_PROVIDER` / `_MODEL`; default: the text default).
  - **Schema:**
    - `risk`: a number from 0 to 1;
    - `reasons`: 0–4 values from `advance_payment`, `off_platform_contact`, `price_far_below_market`, `reported_vin`, `pressure_tactics`, `inconsistent_details`, `other`;
    - `explanation`: 1–300 characters.
  - **Prompt:** lists the facts and defines each reason. A listing with no reason should get a low risk.
- **Validation and retry.**
  - `ModerationRules` checks the PHP side as well: risk between 0 and 1, reasons distinct and in the list, the explanation present.
  - Invalid or unparsable output is rejected and retried **once** (2 attempts in total). If both fail, the result is `invalid` and nothing is classified.
  - Every attempt is an `ai_runs` row, and invalid attempts are flagged `validation_failed` (BR-90, BR-93).
  - A transport error is one attempt with no retry.
- **Service** `ModerationService::moderate(ModerationInput $input)`.
  - It computes the facts with `ModerationFacts` (one median query per make, model and year, cached 1 hour), runs the agent, and returns `{risk, risky (risk ≥ threshold), reasons, explanation, facts, attempts, invocation_ids, status}`.
  - The threshold is `ai.moderation.threshold`, calibrated by the eval (default 0.5 until then).
  - The input guard (SL-45) runs on the description first. A hit is **not** a refusal here: injection text in a listing is itself a red flag. The input is then classified with the fact `injection_pattern` set, and the prompt treats it as a reason `other`.
- **Eval set** `bench/moderation/listings.v1.jsonl`: 100 items, each with `{id, title, make, model, year, price_eur, mileage_km, city, description, label: "safe"|"risky", reasons: [...]}`.
  - **The 50 safe items:**
    - 35 descriptions produced by the existing `ListingDocumentGenerator` with a separate seed. They are written to the file only, never stored or indexed, so they stay outside the indexed corpus.
    - 15 **hard negatives**, written by hand: legitimate mentions of financing, bank transfer at handover, "price negotiable", a VIN that is not reported, a low price explained by damage.
  - **The 50 risky items:**
    - 10 each of `advance_payment`, `off_platform_contact`, `price_far_below_market` (price at most 0.5 of the median of real listings, computed when the set is generated) and `reported_vin` (a VIN from the register);
    - 10 mixed items with two signals.
  - **Construction:** the set is built by `moderation:make-set {--seed=11}`, which writes the file deterministically. Scam phrasing comes from a fixed template list with variations. The labels are correct by construction, and the 15 hard negatives and 10 mixed items are hand-written and committed.
  - A test checks that no item text appears in `listing_documents` or `docs_chunks`.
- **Eval** `moderation:eval {--models=qwen3.5:9b,gemma4:12b}`.
  - **Per model:**
    - **JSON-valid rate:** on the first attempt and after the retry;
    - **threshold calibration:** a curve from 0.1 to 0.9 in steps of 0.05, choosing the threshold with the best F1 for "risky" (on a tie, the higher threshold);
    - at that threshold: accuracy, precision and recall for "risky", and the false-positive rate on hard negatives;
    - **reason recall:** expected reasons found in the answer's reasons, over risky items;
    - **latency:** p50 and p95.
  - **Output:** `bench/results/moderation/<date>/run.json`, a `BENCHMARKS.md` section in the existing format (BR-26), and the chosen threshold as the `config/ai.php` default (set by the orchestrator).
- **Lab page `/ai/moderation`.**
  - A form with title, price, make, model, year, mileage, city and description, plus a "Pick a listing" dropdown (10 seeded listings) and "Load an example" (4 risky examples from the set).
  - A provider selector (SL-51).
  - **Result:** a risk meter against the threshold, the risky badge, reason chips with their definitions, the explanation, a facts panel (median, ratio, VINs found, reported, injection pattern), attempts and the run link.
  - The "How it works" panel, a navigation entry and an overview and home capability.
  - `POST /api/moderation` (validated input, `moderation` throttle at 30 per minute per IP, the cloud limiter).
- **Guide** `resources/guides/moderation.php`: capability, six tabs. It covers fixed reasons over free text, computed facts, threshold calibration and the false-positive trade-off, and why an injection attempt counts as a signal and not a refusal.
- **Docs:** §16 and the README.

### Out of scope

- Moderating stored listings in bulk or on create.
- An admin review queue.
- A real VIN registry.
- Images (the photo slices cover those).
- Cloud moderation APIs.

## Must NOT change

- **MNC-1**: Listing pages, other AI features and their settings rows are unchanged; `moderation` is a new row.
- **MNC-2**: The input guard keeps refusing on chat, NL search, photo search and the assistant. Only moderation uses its match as a fact.
- **MNC-3**: The suite stays offline.
- **MNC-4**: The fingerprint is unchanged and parity is identical.

## Acceptance criteria

- **AC-1** (BR-93): WHEN a listing is moderated THE SYSTEM SHALL return a risk from 0 to 1, reasons only from the fixed list, an explanation and the facts used. IF the model's output fails the shape THEN it SHALL retry once, and IF it fails again SHALL return status `invalid`, with both attempts logged as `validation_failed`.
- **AC-2**: WHEN a listing is moderated THE SYSTEM SHALL compute the market median for its make, model and year ±1, the price ratio, the VINs in its text and whether any is reported, and pass them to the model.
- **AC-3**: WHEN the description matches an injection pattern THE SYSTEM SHALL still classify it, with the fact `injection_pattern` set, and SHALL NOT refuse.
- **AC-4** (BR-91): WHEN `moderation` is switched on `/ai/settings` or by env THE SYSTEM SHALL classify with that provider and model.
- **AC-5**: WHEN `moderation:make-set` runs with the same seed THE SYSTEM SHALL write the same 100 items with labels, and no item text SHALL exist in the indexed corpus.
- **AC-6** (BR-26): WHEN `moderation:eval` runs THE SYSTEM SHALL write the per-model metrics listed in scope and the threshold curve, and `BENCHMARKS.md` SHALL present them.
- **AC-7**: WHEN a learner submits on `/ai/moderation` THE SYSTEM SHALL show the risk against the threshold, the reasons, the explanation, the facts, the attempts and the run link.
- **AC-8** (BR-27): WHEN `/ai/guides/moderation` renders THE SYSTEM SHALL show six non-empty tabs, and the no-references test SHALL pass on it and on `/ai/moderation`.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Safety | Listing text is passed as data inside a delimited block; the model's output is never used as HTML; the endpoint validates every field | `ModerationEndpointTest` |
| **NFR-2** | Cost | One median query per make, model and year, cached; at most 2 model calls per listing | `ModerationServiceTest` |

## Rollout

- **Flag**: none.
- **Deploy order**: code; then `moderation:make-set`, the eval, and setting the threshold.
- **Rollback**: revert. There is no migration.

## Boundaries

- **Always**:
  - Validate in PHP.
  - A closed reason list.
  - Compute facts in the app.
  - Fake the agent in tests.
  - Update the guide, §16, the README and `BENCHMARKS.md`.
- **Ask first**:
  - A schema change.
  - Bulk moderation of stored listings.
  - A new package.
- **Never**:
  - Let the model invent a reason outside the list.
  - Put eval items into the indexed corpus.
  - Refuse a listing for an injection pattern.

## Technical context

- **Related code**:
  - `app/Ai/ListingFilterAgent.php` and `app/Rules/ListingFilterRules.php` (structured output plus PHP rules);
  - `app/Services/PhotoExtractionService.php` (the retry pattern);
  - `app/Services/PromptInjectionGuard.php`;
  - `app/Services/ListingDocumentGenerator.php` (descriptions);
  - `app/Ai/AiProviders.php`;
  - `app/Services/AiSettingsService.php`;
  - the SL-51 lab provider selector;
  - `resources/js/ai/components/LabHowItWorks.vue`.
- **Already implemented**: the resolver and settings, the input guard, the guide engine, `ai_runs` with `validation_failed`.

## Clarifications (binding)

Self-decided under the owner's standing approval.

- **Labels by construction plus hand-written hard cases.** The 75 generated items have labels that are correct by construction. The 25 hand-written items (hard negatives and mixed) are where "hand-labelled" applies. This makes the set reproducible and honest about what was hand-labelled.
- **Facts beat knowledge.** A local model cannot know market prices or stolen VINs, so the app computes them. The eval measures whether the model uses them.
- **Injection is a signal here**, unlike chat, because a listing that tries to steer a moderator is suspicious in itself.

## Clarity gate answers (binding, self-decided 2026-11-12)

1. **Thresholds.**
   - The threshold is stored per model: `ai.moderation.thresholds` maps a model id to its calibrated threshold, with `ai.moderation.threshold` (0.5) as the fallback.
   - The eval reports each model's threshold, and the orchestrator writes both into `config/ai.php`.
   - The service reads the threshold of the model that answered.
2. **Invalid items.** An item that ends `invalid` counts as **wrong** for accuracy: a risky item counts as missed (a false negative), a safe item as a false alarm (a false positive). It is excluded from reason recall. The JSON-valid rate reports how many there were.
3. **Determinism.**
   - `moderation:make-set` reads a fixed **price reference** `bench/moderation/price-reference.json` (make, model and year → median), written once by `moderation:make-set --refresh-prices` from the dev database and committed. Generation then depends only on the seed and that file.
   - Items are drawn from make, model and year groups with a reference median from at least 5 listings.
   - The eval and the lab compute live facts from the database. If a median is null (fewer than 5 listings), `price_ratio` is null and the prompt says the price cannot be judged.
4. **Status values.**
   - `classified`: a valid answer.
   - `invalid`: both attempts failed the shape.
   - `failed`: a transport error. It is one attempt with no retry and an `ai_runs` row with status failed. If the transport error comes on the second attempt, the result is `failed` with 2 attempts.
5. **Rules.**
   - Any risk may come with any reasons; PHP does not enforce consistency between them. The phrase "reasons with risk below 0.2" is deleted.
   - An explanation over 300 characters is cut to 300, not rejected.
   - The risk must be a JSON number; a numeric string is rejected.
6. **Injection.**
   - `injection_pattern` (the pattern name or null) joins the facts list.
   - The prompt tells the model to add the reason `other` and a risk of at least 0.7 when it is set.
   - PHP does not add the reason. The eval has 5 risky items with injected text, and AC-3 is measured as their recall like the other reasons.
   - The manual test expects the reason `other`.
7. **Metrics.**
   - **Reason recall:** micro, computed over all valid risky items, no matter where the threshold sits.
   - **Mixed items:** each lists its two reasons, chosen from the four kinds by the seed.
   - **False-positive rate on hard negatives:** false positives ÷ 15, with invalid items counted as false positives.
   - **JSON-valid after the retry:** items with a valid answer within 2 attempts ÷ 100.
8. **Lab and fact details.**
   - **Pick a listing:** the 10 lowest listing ids.
   - **Load an example:** the first item of each of the four kinds in the set.
   - **Provider:** the lab provider selector wins for its request (SL-51 rule), then `/ai/settings`, then config.
   - **Cloud limiter:** the SL-51 `cloud-ai` limiter.
   - **Run link:** `/ai/runs/{invocation id}`.
   - **Capability:** the `LabOverviewService` entry shown on `/ai` and `/`.
   - **§16:** section 16 of `docs/learning-guide.md`.
   - **Median:** it excludes the listing being moderated when it is a stored listing. All listings count (there is no inactive state).
   - **VIN regex:** `\b[A-HJ-NPR-Z0-9]{17}\b`, case-insensitive, uppercased before the register lookup.
   - **Corpus test:** it seeds a listing document and a docs chunk, then checks that no item description is a substring (case-insensitive) of any stored text, and that the generator was called with a seed different from the database seeder.

## Open questions

- [x] Clarity gate (8 items) answered above.
- [x] Accepted assumption: BR-26, BR-27, BR-90, BR-91 and BR-93 come from a draft brief applied in every slice (standing approval).
