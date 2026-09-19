# Phase 3.7 — Production Observability 2.0

Observability-only layer: **existing events → normalized turn → aggregated metrics**.

Not evaluation, not release gates, not dashboards.

## Data flow

```text
conversation_turn_events (production)
        ↓
GET /api/wa-az-audit.php?part=events&turn_id=…
        ↓
n_events.events[]
        ↓
tests/lib/phase37-observability.php
        ↓
normalized turn + phase37_aggregate_metrics()
```

## Normalized turn

See `phase37_normalize_turn_from_n_events()` for the full shape:

- Identity: `turn_id`, `conversation_id`, `bot_id`, `channel`
- Path: `agent_core` | `fallback` | `unknown`
- `lifecycle`, `stages`, `stage_presence` (OBSERVED / NOT_OBSERVED)
- Extracted blocks: `intelligence`, `decision`, `validation`, `action`, `delivery`, `timing`

Missing fields remain `null` or `NOT_AVAILABLE` — never inferred.

## Phase 3.6 compatibility

`phase37_normalize_from_phase36_evidence()` accepts Phase 3.6 evidence records.
Full intelligence/decision extraction requires event `detail_json` from audit (Layer B).

## Offline validation

```bash
php tests/phase37-observability-test.php
php tests/phase36-evidence-test.php
php tests/agent-core-test.php
# … full Agent Core/Mind suite
```

Fixtures: `tests/phase37-observability-fixtures.json` (structures mirror sanitized audit payloads).

## Metrics contract

See `docs/phase37-metrics-contract.md` for metric definitions, denominators, and limitations.
