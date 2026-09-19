# Phase 3.6 — Production Run Log

Use one JSON file per validation session, copied from:

`tests/phase36-evidence-run-template.json`

## Run header

| Field | Description |
|-------|-------------|
| `run_id` | Unique id, e.g. `P36-RUN-20260919-001` |
| `date_time` | Session start (ISO-8601) |
| `operator` | Who ran Layer B validation |
| `bot_id` | 53 |
| `channel` | whatsapp |

## Per-scenario row

| Field | Source |
|-------|--------|
| `scenario_id` | P36-NNN |
| `trigger_message` | Sent text (no secrets) |
| `turn_id` | **OBSERVED** |
| `conversation_id` | Lead/thread label if available |
| `observed_path` | From evidence classifier |
| `events` | Event type list from audit |
| `fallback` | boolean |
| `fallback_reason` | From CORE_FALLBACK when present |
| `timing_ms` | Max elapsed_ms from event details if available |
| `result` | PASS / FAIL / BLOCKED / NOT_RUN |
| `notes` | Operator notes |

## Summary counts

Update `summary.pass`, `summary.fail`, `summary.blocked`, `summary.not_run` after the session.

## Storage

Keep run JSON **outside** git if it contains operational metadata.
The template in `tests/` is an empty structure only.
