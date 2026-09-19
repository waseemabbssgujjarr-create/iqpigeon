# Phase 3.8 Evaluation Contract

## Scenario (input to evaluator)

```json
{
  "scenario_id": "P38-001",
  "name": "...",
  "dimension": "...",
  "p36_ref": "P36-005",
  "fixture_key": "successful_agent_core",
  "input": "trigger text (documentation)",
  "assertions": [
    {"assertion_id": "P38-001-PATH", "type": "path_equals", "expected": "agent_core", "critical": false}
  ]
}
```

Fields in `expected` are **EXPECTED** semantics only until matched against normalized evidence (**OBSERVED**).

## Scenario result

```json
{
  "scenario_id": "P38-001",
  "status": "PASS",
  "score": null,
  "assertions": [],
  "critical_failures": [],
  "evidence": {"turn_id": 90001, "bot_id": 53, "path": "agent_core"},
  "notes": []
}
```

Overall `status` priority: any **FAIL** → FAIL; else **BLOCKED**; else **NOT_VERIFIED**; else **PASS**.

## Assertion statuses

| Status | Meaning |
|--------|---------|
| PASS | Expected matches observed evidence |
| FAIL | Mismatch |
| BLOCKED | Evaluator cannot run assertion (unknown type, missing fixture) |
| NOT_VERIFIED | Required evidence field absent |
| NOT_APPLICABLE | Assertion does not apply to this turn |

## Assertion types (Phase 3.8.0)

`path_equals`, `path_not_fallback`, `intent_equals`, `intent_present`, `action_equals`, `cta_mode_equals`, `customer_need_equals`, `stage_observed`, `validation_executed`, `validation_passed`, `validation_blocked`, `validation_reason_equals`, `action_executed`, `action_verified`, `action_not_verified`, `delivery_succeeded`, `fallback_reason_equals`, `no_unverified_booking_execution`

## Critical failure IDs

Documented in `phase38_critical_failure_ids()`:

- `fabricated_action_completion`
- `validation_false_action_leaked`
- `unverified_booking_claim`
- `support_path_sales_cta`
- `unexpected_agent_core_fallback`

Triggered only when a **critical** assertion **FAIL**s with observable evidence (not subjective scoring).

## Baseline comparison

`phase38_compare_runs($baseline, $current)` returns factual deltas:

- `newly_passing`, `newly_failing`, `unchanged`, `blocked_changes`, `critical_changes`
- `regressions[]` entries use flag **`REGRESSION_DETECTED`**

Does not emit `RELEASE_BLOCKED`.

## Limitations

- Cannot evaluate reply text quality (not in sanitized events).
- Cannot detect all Agent Mind rules without corresponding event fields.
- Production Layer B turns require audit `detail_json` for rich assertions.

## Version

Evaluator: `PHASE38_EVALUATOR_VERSION` in `tests/lib/phase38-evaluation.php`.
