# Phase 3.11 — Production Rollout Operations Runbook

Human-controlled progressive rollout for Agent Core. Phase 3.10 supplies **decisions**; Phase 3.11 supplies **evidence, verification, and review**. Nothing in this phase writes production configuration automatically.

## Current known-good production (do not change without authorization)

```text
AGENT_CORE_ENABLED=true
AGENT_CORE_ROLLOUT_BOT_IDS='53'
```

## Workflow

```text
Evaluate → Release Gate → Rollout Proposal → Manual Approval → Apply Config
    → Verify Production → Collect Evidence → Cohort Review → Expand OR Roll Back
```

---

## 1. Pre-rollout

1. **Confirm clean git state** — release commit tagged; branch matches deployed code.
2. **Confirm release commit** — record SHA (e.g. `1c71270`).
3. **Run regression suites** — Agent Core/Mind 795/795 + Phase 3.6–3.10 offline harnesses.
4. **Run Phase 3.9 gate** — `php tests/phase39-release-gate-runner.php clean` (fixture demo) or gate input from real regression + eval artifacts. **PASS required** before expansion.
5. **Generate Phase 3.10 rollout proposal** — `phase310_evaluate_rollout_expansion()` with `current_stage` / `target_stage`. Save audit JSON. Decision must be **APPROVED**.
6. **Review candidate cohort** — bot IDs in target stage; confirm eligibility (`agent_core_bot_eligible`).
7. **Confirm rollback target** — note `rollback_stage` and `proposed_config` from policy stage definition.

---

## 2. Manual approval

Record:

- Operator name / ticket ID
- Target stage and bot list
- Release gate status (must be PASS)
- Phase 3.10 audit attachment

Set `operator_approved_expansion=true` only after human sign-off. The harness never applies config for you.

---

## 3. Apply configuration (operator only)

Edit **production** `config.local.php` (not in git):

```php
define('AGENT_CORE_ENABLED', true);
define('AGENT_CORE_ROLLOUT_BOT_IDS', '53,54'); // example stage 1 — apply only when approved
```

Rules:

- Do **not** use empty rollout list unless policy stage explicitly allows “all eligible”.
- Do **not** disable master switch unless rollback procedure requires it.
- Deploy code separately from config; never overwrite `config.local.php` on deploy.

---

## 4. Verify production (read-only)

On server (or locally with fixture):

```bash
php tests/phase311-production-verify.php --json
php tests/phase311-production-verify.php --fixture tests/phase311-production-verify-fixture.json
```

Confirm for each cohort bot:

| Check | Meaning |
|-------|---------|
| `CONFIGURED` | Bot ID in `AGENT_CORE_ROLLOUT_BOT_IDS` (or empty list = all eligible) |
| `ELIGIBLE` | Passes `agent_core_bot_eligible()` |
| `EFFECTIVE` | Observed audit path matches expectation (requires real audit fetch) |

Verify:

- Master flag
- Rollout list
- No **configuration_mismatch** (configured off but Core observed, or configured on but no Core path when audit exists)

---

## 5. Collect evidence

Source: **`GET /api/wa-az-audit.php?part=events`** (Phase 3.6 read model). Operator exports `n_events` batches per turn/bot.

Build package offline:

```bash
php tests/phase311-rollout-evidence-test.php   # harness validation only
```

Use `phase311_build_evidence_package()` with:

- `production_audit_batches` — **real** audit JSON only
- `release_gate`, `evaluation_report`, `rollout_proposal`

Evidence states:

```text
NOT_STARTED → COLLECTING → SUFFICIENT → READY_FOR_REVIEW
INSUFFICIENT / CRITICAL_FAILURE block expansion
```

**Do not fabricate** turn IDs, delivery, or customer content. Missing audit → **NOT_VERIFIED**, not PASS.

---

## 6. Observe before next expansion

From Phase 3.7 aggregates (when audit batches supplied):

- Volume / core path rate / fallback rate
- Validation, action verification, delivery
- Stage presence / lifecycle

Documented limits (Phase 3.7): no AI cost, no quality score, no per-bot time series without turn batches.

Minimum before next stage (schema `ops_policy`): at least one bot with verified Core turns in supplied evidence.

---

## 7. Cohort review

`phase311_review_cohort()` recommendations:

| Decision | Meaning |
|----------|---------|
| EXPAND | Safe to propose next stage (manual apply still required) |
| HOLD | Evidence or approval incomplete |
| ROLLBACK | Critical failure or rollback_required signals |
| BLOCKED | Gate FAIL/BLOCKED or invalid rollback target |

Every output includes **`manual_apply_required: true`**.

---

## 8. Rollback

1. Read `rollback_stage` from current policy stage or Phase 3.10/3.11 audit.
2. Apply **previous** `proposed_config.rollout_bot_ids` manually (e.g. stage 1 → `'53,54'`, stage 0 → `'53'`).
3. Re-run production verify script.
4. Collect post-rollback evidence; gate may need re-run before re-expansion.

Do not globally disable `AGENT_CORE_ENABLED` unless the known-good stage had it off.

---

## 9. Incident handling

- Preserve critical failure details in evidence package (do not aggregate away).
- Gate FAIL/BLOCKED → **BLOCKED** review; do not expand.
- Production evidence critical flag → **ROLLBACK** recommendation.
- Escalate with attached Phase 3.11 evidence JSON + Phase 3.9 gate report.

---

## What remains manual

- Config edits, deploy, WhatsApp evidence export, operator approval, business go/no-go.

## What is intentionally NOT automated

- Writing `config.local.php`
- Changing rollout cohort without human action
- Autonomous expansion or rollback
