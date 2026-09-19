# Phase 3.8 — Agent Evaluation Platform (Offline)

Evaluates **observable behavior** against deterministic expectations.

```text
Phase 3.6 evidence (n_events / evidence record)
        ↓
Phase 3.7 normalization
        ↓
Phase 3.8 evaluation (assertions)
        ↓
Structured scenario results + optional baseline comparison
```

## What Phase 3.8 is

- Offline fixture and audit-shaped evaluation
- Assertion-level PASS / FAIL / BLOCKED / NOT_VERIFIED / NOT_APPLICABLE
- Critical failure tagging (aligned with existing Agent Mind validation concepts)
- Baseline comparison with `REGRESSION_DETECTED` (not release blocking)

## What Phase 3.8 is NOT

- Production monitoring (3.7)
- Release gate (3.9)
- Rollout automation (3.5 / 3.10)
- Quality score or model ranking
- Dashboard or learning system
- VPS deployment or production DB

## Run offline validation

```bash
php tests/phase38-evaluation-test.php
php tests/phase37-observability-test.php
php tests/phase36-evidence-test.php
# … Agent Core/Mind 795 suite
```

## Dataset

- Scenarios: `tests/phase38-evaluation-scenarios.json` (references `p36_ref` where applicable)
- Fixtures: `tests/phase38-evaluation-fixtures.json`
- Engine: `tests/lib/phase38-evaluation.php`

## Human review

Every failed assertion includes `expected`, `observed`, `reason`, and `evidence.turn_id`.

No single aggregate quality score is produced (`score` is always `null`).
