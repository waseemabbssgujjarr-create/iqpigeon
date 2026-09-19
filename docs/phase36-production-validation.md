# Phase 3.6 — Bot 53 Production Validation Procedure

**Layer B only.** This document describes manual evidence collection.
The repository harness (Layer A) does not send WhatsApp messages or modify production.

## Prerequisites

- Bot 53 is the only rollout target (`AGENT_CORE_ROLLOUT_BOT_IDS=53`).
- `AGENT_CORE_ENABLED=true` on production (operator-controlled; do not change during harness commits).
- `CRON_SECRET` from production `config.local.php` (never commit).
- Scenario list: `tests/phase36-scenario-matrix.md` / `tests/phase36-scenarios.json`.

## Evidence API (read-only)

```text
GET https://{APP_HOST}/api/wa-az-audit.php?key={CRON_SECRET}&part=events&turn_id={TURN_ID}
```

Optional context:

```text
&part=messages&turn_id={TURN_ID}
```

Default audit (no heavy parts):

```text
GET /api/wa-az-audit.php?key={CRON_SECRET}
```

Inspect `d_turns.open` for recent turn ids when `turn_id` is unknown.

### `n_events` fields used for evidence

| Field | Use |
|-------|-----|
| `ok`, `status` | Audit fetch outcome (`turn_id_required`, `turn_not_found`, `no_events`, `ok`, `error`) |
| `turn_id` | **OBSERVED** turn |
| `events[]` | `event_type`, `created_at`, sanitized `detail_json` |
| `event_types_present` | All types stored for turn |
| `watched` / `watched_missing` | Presence map for standard Core/LIVE/delivery events |
| `select_only`, `sends`, `mutates` | Must remain read-only (`true`, `false`, `false`) |

Event names are defined in code: `az_watched_core_events()` in `api/wa-az-audit.php`.
Fallback reason when present: `CORE_FALLBACK` → `detail_json.fallback_reason`.

## Step-by-step (per scenario)

1. Send the controlled WhatsApp message to Bot 53 (trigger from scenario matrix).
2. Record timestamp (ISO-8601).
3. Note lead/conversation identifier if visible in admin tools.
4. Wait for turn processing (debounce/worker; typically ≥7s after last inbound).
5. Locate `turn_id` (open turns in audit core part, admin UI, or DB read-only query).
6. Call `part=events&turn_id=…` with CRON key.
7. Classify path using `tests/lib/phase36-evidence.php` or manual rules in `tests/phase36-evidence-schema.md`.
8. Confirm delivery evidence: `RESPONSE_SENT` and/or `PROCESSING_TO_RESPONSE` when applicable.
9. Record fallback: `CORE_FALLBACK` present → `fallback=true` and reason from detail.
10. Copy sanitized event list into run artifact (`tests/phase36-evidence-run-template.json`).
11. Mark `PASS`, `FAIL`, or `BLOCKED` per scenario row criteria.

## PASS / FAIL / BLOCKED

- **PASS** — Expected Core path with traceable stages/events; no contradicting fallback unless documented.
- **FAIL** — Turn exists; evidence shows unexpected fallback or non-Core path when Core expected.
- **BLOCKED** — Cannot execute (prerequisite turn, media not supported, operator unavailable).

## What not to do

- Do not enable Core for bots other than rollout list.
- Do not modify production PHP, config, or database for Phase 3.6 evidence collection.
- Do not paste customer message bodies or secrets into committed evidence files.

## Helper (local)

After fetching JSON, normalize offline:

```php
require 'tests/lib/phase36-evidence.php';
$evidence = phase36_build_evidence_from_audit($report['n_events'], [
    'scenario_id' => 'P36-001',
    'result' => 'PASS',
]);
```
