# Phase 3.9 — Automated Release Gate (Offline)

Deterministic **PASS / FAIL / BLOCKED** decision from Phase 3.8 evaluation + regression suite inputs.

```text
3.6 Evidence → 3.7 Normalization → 3.8 Evaluation → 3.9 Release Gate
```

## Status meanings

| Status | Meaning |
|--------|---------|
| **PASS** | All mandatory criteria satisfied |
| **FAIL** | Known bad evidence or regression |
| **BLOCKED** | Insufficient evidence to decide safely |

Does **not** deploy, merge, or change rollout.

## Run offline tests

```bash
php tests/phase39-release-gate-test.php
```

Demo runner (fixture reports):

```bash
php tests/phase39-release-gate-runner.php clean
php tests/phase39-release-gate-runner.php critical
```

Exit codes: `0` PASS, `1` FAIL, `2` BLOCKED.

## Policy

`tests/phase39-release-gate-policy.json` — suite thresholds, required vs optional scenarios (P38-008 optional for scenario status but **any** critical failure in the evaluation report still fails the gate).

## Clean vs full evaluation

- **Full Phase 3.8 fixture run** includes P38-008 (intentional critical failure) → gate **FAIL**.
- **Clean release candidate** uses gate fixtures or required-only evaluation with `critical_failure_count = 0` → gate **PASS** when suites are green.

## Not in scope

Progressive rollout (3.10), production DB, VPS, subjective scores, automatic deploy.
