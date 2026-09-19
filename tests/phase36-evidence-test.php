<?php
/**
 * PHASE 3.6 OFFLINE EVIDENCE VALIDATION
 * Local only — no production credentials, no WhatsApp, no DB writes.
 *
 * Run: php tests/phase36-evidence-test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/tests/lib/phase36-evidence.php';

$passed = 0;
$failed = 0;

function p36_assert(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) {
        echo "PASS: {$name}\n";
        $passed++;
    } else {
        echo "FAIL: {$name}\n";
        $failed++;
    }
}

echo "PHASE 3.6 OFFLINE EVIDENCE VALIDATION\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n\n";

// --- Watched events align with wa-az-audit.php source ---
$azSrc = (string) file_get_contents($root . '/api/wa-az-audit.php');
p36_assert(
    str_contains($azSrc, 'function az_watched_core_events')
    && str_contains($azSrc, "'CORE_START'")
    && str_contains($azSrc, "'CORE_FALLBACK'")
    && str_contains($azSrc, "'RESPONSE_SENT'"),
    'AUDIT: wa-az-audit defines watched Core events'
);
$watched = phase36_watched_core_event_names();
p36_assert(count($watched) >= 20, 'AUDIT: watched event list non-empty');
foreach (['CORE_START', 'CORE_COMPLETE', 'CORE_FALLBACK', 'RESPONSE_SENT'] as $must) {
    p36_assert(in_array($must, $watched, true), 'AUDIT: watched includes ' . $must);
}

// --- Scenario definitions ---
$scenarioPath = $root . '/tests/phase36-scenarios.json';
$rawScenarios = is_readable($scenarioPath) ? file_get_contents($scenarioPath) : false;
$scenarios = is_string($rawScenarios) ? json_decode($rawScenarios, true) : null;
p36_assert(is_array($scenarios) && $scenarios !== [], 'SCEN: scenarios JSON loads');
$ids = [];
if (is_array($scenarios)) {
    foreach ($scenarios as $row) {
        if (!is_array($row)) {
            p36_assert(false, 'SCEN: row is array');
            continue;
        }
        $errs = phase36_validate_scenario_definition($row);
        p36_assert($errs === [], 'SCEN: valid ' . ($row['scenario_id'] ?? '?') . ($errs ? ' — ' . implode(', ', $errs) : ''));
        $ids[] = (string) ($row['scenario_id'] ?? '');
    }
    p36_assert(count($ids) === count(array_unique($ids)), 'SCEN: unique scenario IDs');
    p36_assert(count($scenarios) === 20, 'SCEN: twenty scenarios defined');
}

// --- Evidence template ---
$templatePath = $root . '/tests/phase36-evidence-run-template.json';
$template = is_readable($templatePath) ? json_decode((string) file_get_contents($templatePath), true) : null;
p36_assert(is_array($template) && isset($template['scenarios']), 'TMPL: run template JSON valid');

// --- Path classification fixtures ---
$coreSuccessEvents = [
    ['event_type' => 'CORE_START', 'created_at' => null, 'detail_json' => ['turn_id' => 100, 'bot_id' => 53, 'channel' => 'whatsapp', 'elapsed_ms' => 10]],
    ['event_type' => 'CORE_COMPLETE', 'created_at' => null, 'detail_json' => ['elapsed_ms' => 900]],
    ['event_type' => 'RESPONSE_SENT', 'created_at' => null, 'detail_json' => []],
];
p36_assert(
    phase36_classify_path_from_events($coreSuccessEvents) === 'agent_core',
    'PATH: CORE_START+COMPLETE → agent_core'
);
p36_assert(
    phase36_extract_fallback_reason($coreSuccessEvents) === null,
    'PATH: no fallback reason on success'
);

$fallbackEvents = array_merge($coreSuccessEvents, [
    ['event_type' => 'CORE_FALLBACK', 'created_at' => null, 'detail_json' => ['fallback_reason' => 'validation_failed']],
]);
p36_assert(
    phase36_classify_path_from_events($fallbackEvents) === 'fallback',
    'PATH: CORE_FALLBACK → fallback'
);
p36_assert(
    phase36_extract_fallback_reason($fallbackEvents) === 'validation_failed',
    'PATH: extract fallback_reason'
);

$legacyEvents = [
    ['event_type' => 'PROCESSING_TO_RESPONSE', 'created_at' => null, 'detail_json' => []],
    ['event_type' => 'RESPONSE_SENT', 'created_at' => null, 'detail_json' => []],
];
p36_assert(
    phase36_classify_path_from_events($legacyEvents) === 'webhook_mind_or_unknown',
    'PATH: delivery without CORE_START → webhook_mind_or_unknown'
);

p36_assert(
    phase36_classify_path_from_events([]) === 'NOT_VERIFIED',
    'PATH: empty events → NOT_VERIFIED'
);

// --- Build from audit-shaped payload ---
$nEventsOk = [
    'ok'              => true,
    'status'          => 'ok',
    'select_only'     => true,
    'sends'           => false,
    'mutates'         => false,
    'turn_id'         => 100,
    'events'          => [
        ['event_type' => 'CORE_START', 'created_at' => '2026-01-01 12:00:00', 'detail_json' => ['bot_id' => 53, 'channel' => 'whatsapp', 'elapsed_ms' => 5]],
        ['event_type' => 'CORE_COMPLETE', 'created_at' => '2026-01-01 12:00:01', 'detail_json' => ['elapsed_ms' => 800]],
    ],
    'watched_missing' => ['LIVE_WORLD_DETECTED'],
];
p36_assert(phase36_validate_audit_n_events_shape($nEventsOk) === [], 'SHAPE: valid n_events fixture');
$built = phase36_build_evidence_from_audit($nEventsOk, ['scenario_id' => 'P36-001', 'result' => 'PASS']);
p36_assert(
    ($built['path'] ?? '') === 'agent_core'
    && ($built['bot_id'] ?? 0) === 53
    && ($built['channel'] ?? '') === 'whatsapp'
    && ($built['turn_id'] ?? 0) === 100,
    'BUILD: evidence from audit payload'
);
p36_assert(phase36_validate_evidence_record($built) === [], 'BUILD: evidence record validates');

$badRecord = ['scenario_id' => 'P36-001', 'result' => 'MAYBE'];
p36_assert(phase36_validate_evidence_record($badRecord) !== [], 'SCHEMA: rejects invalid result');

// --- Documentation files exist ---
foreach ([
    'tests/phase36-evidence-schema.md',
    'tests/phase36-scenario-matrix.md',
    'docs/phase36-production-validation.md',
    'docs/phase36-production-run.md',
] as $doc) {
    p36_assert(is_readable($root . '/' . $doc), 'DOC: ' . $doc);
}

p36_assert(
    phase36_max_elapsed_ms($coreSuccessEvents) === 900,
    'TIME: max elapsed_ms from events'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
