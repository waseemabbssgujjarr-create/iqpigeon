# Phase 3.10 — Progressive Production Rollout (Offline Governance)

Plans and validates **controlled expansion** of `AGENT_CORE_ROLLOUT_BOT_IDS` using Phase 3.9 **PASS** gate + Phase 3.8 evaluation + existing eligibility.

## Does NOT

- Write `config.local.php`
- Deploy or SSH to VPS
- Auto-enable additional bots
- Change Bot 53 production config
- Disable `AGENT_CORE_ENABLED` on rollback unless operator chooses

## Stages (policy)

| Stage | Cohort (policy fixture) |
|-------|-------------------------|
| 0 | Bot 53 only (known-good baseline) |
| 1 | 53, 54 |
| 2 | 53, 54, 55 |
| 3 | Empty rollout list → all **eligible** bots (existing semantics) |

Allowed transitions: **0→1→2→3** only (no 0→3 unless policy changes).

## Operator flow

1. Run regression + Phase 3.6–3.9 offline suites.
2. Build Phase 3.9 gate input → **PASS** required.
3. Run `phase310_evaluate_rollout_expansion()` with target stage.
4. If **APPROVED**, apply `proposed_config.rollout_bot_ids` to production **manually**.
5. On incidents, use `phase310_evaluate_rollback()` → apply `rollback_stage` config manually.

## Files

- Policy: `tests/phase310-rollout-policy.json`
- Engine: `tests/lib/phase310-rollout.php`
- Tests: `tests/phase310-progressive-rollout-test.php`

## Observability / evaluation

Optional `observability_metrics` (Phase 3.7 aggregate) may reject expansion on documented fallback spike threshold (policy-driven, not invented at runtime).

Evaluation report must have `critical_failure_count = 0` for expansion.

## Manual vs automated

| Automated (offline) | Manual (production) |
|---------------------|---------------------|
| Validate stage transition | Edit `AGENT_CORE_ROLLOUT_BOT_IDS` in `config.local.php` |
| Require Phase 3.9 PASS | Deploy / restart if needed |
| Emit audit JSON + proposed config | Authorize expansion in change control |
| Recommend rollback level | Apply rollback stage config |

## Rollback

`ROLLBACK_REQUIRED` — gate FAIL/BLOCKED, critical evaluation, production evidence critical flag.

`ROLLBACK_RECOMMENDED` — documented fallback rate threshold exceeded (requires operator-supplied Phase 3.7 aggregate metrics).

`NO_ROLLBACK` — signals within policy.

Rollback **never** writes config; it returns `proposed_config` for the previous stage.

## Test coverage

`tests/phase310-progressive-rollout-test.php` — expansions, gate/eval blocks, eligibility, rollback, runtime rollout semantics unchanged, docs present.

## Regression

Run with Phase 3.6–3.9 and full Agent Core/Mind suite (795 baseline) before any production expansion.
