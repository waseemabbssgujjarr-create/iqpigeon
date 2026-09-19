# Phase 3.10 Rollout Contract

## Input — expansion request

```json
{
  "current_stage": "0",
  "target_stage": "1",
  "timestamp": "2026-09-19T12:00:00Z",
  "release_gate": { "... Phase 3.9 result ..." },
  "evaluation_report": { "... Phase 3.8 report ..." },
  "observability_metrics": { "... optional Phase 3.7 aggregate ..." }
}
```

## Output — audit record

```json
{
  "decision": "APPROVED",
  "candidate_stage": "1",
  "previous_stage": "0",
  "approved_bot_ids": [53, 54],
  "rejected_bot_ids": [],
  "release_gate_status": "PASS",
  "rollback_stage": "0",
  "proposed_config": {
    "rollout_bot_ids": "53,54",
    "master_enabled_note": "..."
  },
  "manual_apply_required": true
}
```

Decisions: `APPROVED`, `REJECTED`, `BLOCKED`.

## Rollback levels

- `ROLLBACK_REQUIRED` — gate FAIL/BLOCKED, critical failures, production evidence critical
- `ROLLBACK_RECOMMENDED` — documented metric threshold (e.g. fallback rate) when observability batch supplied
- `NO_ROLLBACK` — safe

Rollback returns **previous stage** bot list; does not auto-set master switch off.

## Release gate integration

| Gate | Expansion |
|------|-----------|
| PASS | May proceed if other checks pass |
| FAIL | REJECTED |
| BLOCKED | BLOCKED / REJECTED |

## Eligibility

Uses existing `agent_core_bot_eligible()` + catalog in policy for offline simulation. Production bots must pass same rules at apply time.

## Reason codes (examples)

`INVALID_STAGE_JUMP`, `GATE_NOT_PASS`, `CRITICAL_FAILURE`, `INELIGIBLE_BOTS`, `EMPTY_COHORT`, `EVALUATION_MISSING`, `FALLBACK_SPIKE`, `MALFORMED_STAGE`

## Limitations

- No per-bot production time series without operator-supplied metrics batch
- No AI cost / quality scoring
- Stage bot IDs 54–55 are **policy placeholders** for future approved cohorts, not auto-enabled in production
