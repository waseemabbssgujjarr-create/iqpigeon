# Phase 3.7 Metrics Contract

All metrics are **read-only** aggregations over normalized turns.
No quality scores. No release decisions.

## Volume

| Metric | Source | Calculation | Limitations |
|--------|--------|-------------|-------------|
| `total_turns` | Normalized turn list | `count(turns)` | Includes unknown-path turns |
| `agent_core_turns` | `path === agent_core` | count | Requires CORE_START+CORE_COMPLETE, no CORE_FALLBACK |
| `fallback_turns` | `path === fallback` | count | CORE_FALLBACK observed |
| `unknown_turns` | `path === unknown` | count | Incomplete or non-Core delivery-only streams |

## Path rates

| Metric | Denominator | NOT_AVAILABLE when |
|--------|-------------|-------------------|
| `agent_core_rate` | `known_path_turns` (agent_core + fallback + unknown) | denominator = 0 |
| `fallback_rate` | same | denominator = 0 |
| `unknown_rate` | same | denominator = 0 |

**Important:** Unknown-path turns are included in the denominator (known execution path classification), not excluded silently.

## Fallback

| Metric | Source events | Notes |
|--------|---------------|-------|
| `fallback_by_reason` | `CORE_FALLBACK.detail_json.fallback_reason` | Empty if reason not stored |

Common reasons (from runtime, not exhaustive): `validation_failed`, `tool_failure`, `disabled`, `inactive`, `exception`, `empty_generate`.

## Stages

| Metric | Source | Meaning |
|--------|--------|---------|
| `stage_presence_counts` | Mapped event types → canonical stages | Count of turns where stage was **observed** |
| Per-turn `stage_presence` | `phase37_stage_presence_report()` | `NOT_OBSERVED` ≠ proof stage did not run |

Event → stage map lives in `phase37_event_stage_map()` (aligned to `agent_core_observe()` event names).

## Validation

| Metric | Source event | Fields |
|--------|--------------|--------|
| `validation.executed` | Any `CORE_VALIDATE` | — |
| `validation.blocked` | `CORE_VALIDATE` with `ok=false` or `validation=failed` | — |
| `validation.block_by_reason` | `detail_json.reason` | Sanitized reason token |

## Actions

| State | Source |
|-------|--------|
| Planned | `CORE_PLAN.tool_selected` or `selected_action` |
| Executed | `CORE_TOOL_COMPLETE` with `ok=true` |
| Failed | `CORE_TOOL_FAIL` or failed complete |
| Verified | `CORE_COMPLETE.validation_result === verified` (when present) |

**Planned ≠ executed ≠ verified** — never collapsed.

## Delivery

| Metric | Source events |
|--------|---------------|
| `delivery.attempted` | `PROCESSING_TO_RESPONSE` or `RESPONSE_SENT` |
| `delivery.success` | `RESPONSE_SENT` |
| `delivery.failed` | attempted without `RESPONSE_SENT` |

## Timing

| Metric | Source | NOT_AVAILABLE |
|--------|--------|---------------|
| `duration_ms` (per turn) | Max `elapsed_ms` in event details, else timestamp delta | No timestamps and no elapsed_ms |
| `timing_ms.avg/min/max/p50/p95` | Aggregate of numeric durations | No durations in batch |

No new runtime timing instrumentation in Phase 3.7.

## Intelligence / decision fields

Extracted from latest of: `CORE_COMPLETE`, `CORE_DECISION`, `CORE_PLAN`, `CORE_INTELLIGENCE` detail payloads (same fields as `agent_core_observe_decision_bits()`).

**Not available** when audit returns event types only without `detail_json`.

## Unavailable metrics (Phase 3.7)

| Desired signal | Status |
|----------------|--------|
| Per-bot time series without batch input | NOT_AVAILABLE offline |
| Conversation outcome quality | Phase 3.8+ |
| Repeated-question detection | NOT_AVAILABLE without dedicated events |
| AI cost / token counts | Dropped from sanitized observe payloads |
| `CORE_INTELLIGENCE` in watched map | Event may exist in DB but not in `az_watched_core_events()` map |
