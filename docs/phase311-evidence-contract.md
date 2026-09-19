# Phase 3.11 Evidence & Review Contract

## Evidence package (output of `phase311_build_evidence_package`)

| Field | Description |
|-------|-------------|
| `release_commit` | Git SHA under rollout |
| `rollout_stage` / `previous_stage` | Policy stage ids |
| `candidate_bots` / `active_bots` | Intended vs policy-active ids |
| `rollout_timestamp` | ISO-8601 |
| `operator_decision` | Free-text or ticket reference |
| `release_gate` / `release_gate_status` | Phase 3.9 result |
| `evaluation_summary` | Phase 3.8 counts |
| `critical_failure_count` | Must be 0 to expand |
| `production_evidence` | Per-bot audit summaries (empty if not collected) |
| `observability_summary` | Phase 3.7 aggregate when turns present |
| `fallback_summary` / `delivery_summary` | Subsets of observability |
| `evidence_state` | See below |
| `rollback_status` | Phase 3.10 rollback evaluator |
| `next_decision` | Cohort review recommendation |
| `manual_apply_required` | Always `true` |

Schema: `tests/phase311-rollout-evidence-schema.json`

## Evidence states

| State | Meaning |
|-------|---------|
| `NOT_STARTED` | No audit batches supplied |
| `COLLECTING` | Audit present but below minimum verified Core turns |
| `SUFFICIENT` | Meets offline minimum for review |
| `INSUFFICIENT` | Audit present but no Core path observed |
| `CRITICAL_FAILURE` | Eval critical or production evidence critical flag |
| `READY_FOR_REVIEW` | Sufficient + operator flagged ready |

Missing evidence is **never** treated as success.

## Cohort review decisions

| Decision | Typical trigger |
|----------|-----------------|
| `EXPAND` | Gate PASS, no critical failures, evidence SUFFICIENT/READY, Phase 3.10 APPROVED, operator approval recorded |
| `HOLD` | Missing approval, insufficient evidence, rollback recommended metrics |
| `ROLLBACK` | Critical failures or `ROLLBACK_REQUIRED` |
| `BLOCKED` | Gate FAIL/BLOCKED, missing rollback stage, ineligible bots in proposal |

All review outputs include `manual_apply_required: true` and optional `proposed_config` for the target stage.

## Bot verification axes

| Axis | Values |
|------|--------|
| CONFIGURED | `ON_ROLLOUT`, `OFF_ROLLOUT`, `ALL_ELIGIBLE` |
| ELIGIBLE | `ELIGIBLE`, `INELIGIBLE` |
| EFFECTIVE (expected) | `CORE_ON`, `CORE_OFF` |
| EFFECTIVE (observed) | `CORE_ON`, `FALLBACK_OBSERVED`, `NOT_VERIFIED`, `MISMATCH_CORE_ACTIVE` |

Configured rollout ≠ effective runtime until audit confirms Core path.

## Input — production audit batch

```json
{
  "bot_id": 53,
  "n_events": {
    "select_only": true,
    "turn_id": 90001,
    "events": [ "... wa-az-audit.php shape ..." ]
  }
}
```

Pipeline: Phase 3.6 parse → Phase 3.7 normalize/aggregate → Phase 3.11 package.

## Safety blocks (expansion)

Expansion review must not return `EXPAND` when:

- Release gate is `FAIL` or `BLOCKED`
- `critical_failure_count > 0`
- Evidence state not in `SUFFICIENT` / `READY_FOR_REVIEW`
- Phase 3.10 proposal not `APPROVED`
- `operator_approved_expansion` not set
- Rollback target stage missing from policy

## Real production evidence

| Question | Answer |
|----------|--------|
| Real production WhatsApp evidence collected in this commit? | **NO** — tooling and fixtures only |
| Offline tests use | `phase37-observability-fixtures.json` shapes |
| Live collection | Operator GET `/api/wa-az-audit.php` |

Report **`NOT_VERIFIED`** when audit absent; never manufacture PASS.

## Files

| Path | Role |
|------|------|
| `tests/lib/phase311-rollout-evidence.php` | Package builder, review, verify |
| `tests/phase311-rollout-evidence-test.php` | Offline tests |
| `tests/phase311-production-verify.php` | Read-only CLI verification |
| `docs/phase311-production-rollout-runbook.md` | Operator procedure |
