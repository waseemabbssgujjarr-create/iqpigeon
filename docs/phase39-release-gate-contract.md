# Phase 3.9 Release Gate Contract

## Input

```json
{
  "release_candidate": "5f63202",
  "regression": {
    "agent_core_mind": {"passed": 795, "failed": 0},
    "phase36": {"passed": 45, "failed": 0},
    "phase37": {"passed": 43, "failed": 0},
    "phase38_harness": {"passed": 20, "failed": 0}
  },
  "evaluation_report": { "... Phase 3.8 report ..." },
  "baseline_comparison": { "... optional phase38_compare_runs ..." }
}
```

Malformed input → **BLOCKED** + `INVALID_GATE_INPUT`.

## Output

```json
{
  "gate": "phase39",
  "status": "PASS",
  "release_candidate": "5f63202",
  "criteria": [],
  "failures": [],
  "blocked_reasons": [],
  "warnings": [],
  "evaluation_summary": {},
  "baseline_comparison": null,
  "summary": {}
}
```

No statuses: `RELEASED`, `DEPLOYED`, `ROLLED_OUT`.

## Mandatory criteria

| ID | Rule |
|----|------|
| `agent_core_mind` | 795/795 (0 failed) |
| `phase36` | 45/45 |
| `phase37` | 43/43 |
| `phase38_harness` | 20/20 |
| `critical_failures` | `critical_failure_count === 0` in evaluation report |
| `REGRESSION` | Any `REGRESSION_DETECTED` in baseline comparison (unless listed in `regression_exceptions`) |

## Required scenarios

From policy `scenario_requirements.required_for_release`:

- **FAIL** → gate **FAIL**
- **BLOCKED** → gate **BLOCKED**
- **NOT_VERIFIED** → gate **BLOCKED**

Optional scenario failure → **warning** only.

## Required assertions

On required scenarios, assertion status:

- **FAIL** → `REQUIRED_ASSERTION_FAILURE` (gate **FAIL**)
- **NOT_VERIFIED** / **BLOCKED** → gate **BLOCKED**

Optional assertion failure → warning.

## Reason codes

`REGRESSION`, `CRITICAL_FAILURE`, `REQUIRED_SCENARIO_FAILURE`, `REQUIRED_ASSERTION_FAILURE`, `REQUIRED_EVIDENCE_NOT_VERIFIED`, `REQUIRED_SCENARIO_BLOCKED`, `REGRESSION_SUITE_FAILED`, `REGRESSION_BASELINE_UNAVAILABLE`, `INVALID_GATE_INPUT`, `OPTIONAL_*` (warnings only).

## Baseline policy

| Baseline → Current | Gate |
|--------------------|------|
| PASS → PASS | OK |
| PASS → FAIL | FAIL |
| PASS → BLOCKED | BLOCKED |
| PASS → NOT_VERIFIED | BLOCKED |
| New critical vs baseline | FAIL |

Improvements (FAIL→PASS) do **not** cancel other failures.

## Limitations

- Gate does not run PHPUnit; regression counts must be supplied by CI wrapper.
- Does not read production data.
- Does not invoke LLMs.

Version: `PHASE39_GATE_VERSION` in `tests/lib/phase39-release-gate.php`.
