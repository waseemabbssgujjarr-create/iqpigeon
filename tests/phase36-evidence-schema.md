# Phase 3.6 — Production Evidence Schema

This schema records **observed** production validation results for Bot 53 WhatsApp turns.
It does not replace automated regression tests (795/795 baseline).

## Field semantics

| Marker | Meaning |
|--------|---------|
| **OBSERVED** | Value taken from production audit/API or operator notes |
| **NOT_AVAILABLE** | Field exists in schema but was not exposed by audit for this turn |
| **EXPECTED** | Pre-run expectation from scenario matrix (not proof) |
| **NOT_VERIFIED** | Insufficient evidence to classify |

Do not fabricate turn IDs, events, or paths.

## Scenario result object

```json
{
  "scenario_id": "P36-001",
  "timestamp": "2026-09-19T12:00:00+05:00",
  "channel": "whatsapp",
  "bot_id": 53,
  "turn_id": 12345,
  "conversation_id": "lead:9876",
  "path": "agent_core",
  "fallback": false,
  "fallback_reason": null,
  "events": ["CORE_START", "CORE_PLAN", "CORE_COMPLETE", "RESPONSE_SENT"],
  "stages": ["CORE_START", "CORE_CONTEXT", "CORE_INTENT", "CORE_PLAN", "CORE_GENERATE", "CORE_VALIDATE", "CORE_COMPLETE"],
  "duration_ms": 4200,
  "audit_status": "ok",
  "audit_ok": true,
  "watched_missing": ["LIVE_WORLD_DETECTED"],
  "result": "PASS",
  "notes": ""
}
```

### Required fields (committed harness validation)

- `scenario_id` — `P36-NNN`
- `timestamp` — ISO-8601 when evidence was collected
- `channel` — e.g. `whatsapp`
- `bot_id` — production bot (53 for current rollout)
- `turn_id` — **OBSERVED** from turn engine / audit
- `path` — classified from events (see below)
- `fallback` — boolean
- `result` — `PASS` | `FAIL` | `BLOCKED` | `NOT_RUN`

### Optional but recommended

- `fallback_reason` — from `CORE_FALLBACK` event detail when **OBSERVED**
- `events` — ordered event_type names from `n_events.events`
- `stages` — subset of Core/LIVE stage events
- `duration_ms` — max `elapsed_ms` in event details when **OBSERVED**
- `conversation_id` — operator label (e.g. lead id)
- `audit_status` — `n_events.status` from wa-az-audit
- `watched_missing` — names absent from watched map (not always failure)

## Path classification (from stored events only)

| `path` value | Rule |
|--------------|------|
| `agent_core` | `CORE_START` + `CORE_COMPLETE`, no `CORE_FALLBACK` |
| `fallback` | `CORE_FALLBACK` present |
| `agent_core_incomplete` | `CORE_START` without `CORE_COMPLETE` or `CORE_FALLBACK` |
| `webhook_mind_or_unknown` | Delivery/processing events without Core start |
| `NOT_VERIFIED` | No events or ambiguous |
| `EXPECTED` | Placeholder before production run |

Implemented in `tests/lib/phase36-evidence.php` → `phase36_classify_path_from_events()`.

## Production audit source

Read-only:

```text
GET /api/wa-az-audit.php?key=CRON_SECRET&part=events&turn_id={id}
```

Response key: `n_events` (SELECT-only, no sends).

Watched event names match `az_watched_core_events()` in `api/wa-az-audit.php`.
Additional event types (e.g. `CORE_INTELLIGENCE`) may appear in `events[]` and `event_types_present` even if not in the watched map.

## Sanitization

Event `detail_json` in audit output is sanitized (`agent_core_observe_sanitize`, `az_sanitize_event_detail`).
Customer message text, tokens, and prompts must **not** appear in evidence copies.
