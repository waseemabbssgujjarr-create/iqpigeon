<?php
/**
 * Agent Mind Phase 2E-LIVE — controlled real LLM validation.
 *
 * Requires P2E_LIVE_OPENAI_KEY environment variable (isolated harness — no DB for settings).
 *
 * Run: php tests/agent-mind-phase2e-live.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/tests/lib/phase2e-live-bootstrap.php';
require_once $root . '/config.php';
require_once $root . '/includes/conversation-intelligence.php';
require_once $root . '/includes/agent-core/agent-core.php';
require_once $root . '/tests/data/phase2e-live-scenarios.php';
require_once $root . '/tests/lib/phase2e-live-runner.php';

$api = phase2e_live_resolve_api();
$outDir = $root . '/tests/output';

echo "Agent Mind Phase 2E-LIVE — Real LLM Validation\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n";

if (!$api['ok']) {
    echo "\nBLOCKED: No OpenAI API key configured.\n";
    echo "Export P2E_LIVE_OPENAI_KEY for isolated live validation.\n";
    echo "\nClassification: NOT READY\n";
    echo "Blocker: Live LLM validation not executed — 0 real API calls made.\n";
    exit(2);
}

echo 'Provider: OpenAI via ai_chat/openai_chat' . "\n";
echo 'Model: ' . $api['model'] . "\n";
echo 'Key source: ' . $api['source'] . " (value not logged)\n\n";

$industries = phase2e_live_industries();
$scenarios = phase2e_live_scenarios();
$traces = [];
$counts = ['single' => 0, 'multi' => 0, 'objection' => 0, 'multi_intent_support' => 0, 'adversarial' => 0];
$bands = ['PASS' => 0, 'REVIEW' => 0, 'FAIL' => 0];
$criticalTotal = 0;
$hallucination = 0;
$falseAction = 0;
$repetition = 0;
$unnecessaryQ = 0;
$stopViolations = 0;
$start = microtime(true);
$apiCalls = 0;

foreach ($scenarios as $scenario) {
    $indKey = (string) ($scenario['industry'] ?? 'retail');
    $industryPack = $industries[$indKey] ?? $industries['retail'];
    $pack = phase2e_live_build_pack($industryPack, $scenario);
    $bot = phase2e_live_build_bot($industryPack);

    $run = phase2e_live_run_conversation($scenario, $industryPack, $pack, $bot);
    $apiCalls += count($scenario['turns'] ?? []);
    $score = phase2e_live_score(
        $run['reply'],
        $run['plan'],
        array_merge($scenario, ['conv' => $run['conv']]),
        $pack,
        $run['text']
    );

    $trace = phase2e_live_trace(
        (string) $scenario['id'],
        $indKey,
        count($scenario['turns'] ?? []),
        $run['plan'],
        $run['reply'],
        $score
    );
    $trace['failure_layer'] = phase2e_live_failure_layer($trace);
    $traces[] = $trace;

    $bucket = (string) ($scenario['bucket'] ?? 'single');
    $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
    if (!empty($scenario['tags']) && in_array('multi_intent_support', $scenario['tags'], true)) {
        $counts['multi_intent_support']++;
    }
    $bands[$score['band']] = ($bands[$score['band']] ?? 0) + 1;

    foreach ($score['critical'] as $c) {
        $criticalTotal++;
        if (str_contains($c, 'fabricated') || str_contains($c, 'hallucin')) {
            $hallucination++;
        }
        if (str_contains($c, 'false_action') || str_contains($c, 'semantic_false')) {
            $falseAction++;
        }
        if (str_contains($c, 'budget_reask') || str_contains($c, 'date_reask')) {
            $unnecessaryQ++;
        }
        if (str_contains($c, 'aggressive_conversion') || str_contains($c, 'sales_cta')) {
            $stopViolations++;
        }
    }
    if (($score['validation']['reason'] ?? '') === 'repetitive') {
        $repetition++;
    }

    $icon = $score['band'] === 'PASS' ? 'PASS' : ($score['band'] === 'REVIEW' ? 'REVIEW' : 'FAIL');
    echo "{$icon}: {$scenario['id']} score={$score['total']}/32";
    if ($score['critical'] !== []) {
        echo ' CRIT=' . implode(',', $score['critical']);
    }
    echo "\n";
}

$elapsed = round(microtime(true) - $start, 2);
$n = count($traces);
$avg = $n > 0 ? round(array_sum(array_column($traces, 'quality_total')) / $n, 2) : 0;

$passCriteria = [
    $n >= 100,
    $criticalTotal === 0,
    $falseAction === 0,
    $hallucination === 0,
    $avg >= 27,
    $bands['FAIL'] <= (int) ($n * 0.08),
];

$classification = 'NOT READY';
$blockers = [];
if ($n < 100) {
    $blockers[] = 'Fewer than 100 evaluations completed';
}
if ($criticalTotal > 0) {
    $blockers[] = "Critical failures detected: {$criticalTotal}";
}
if ($falseAction > 0) {
    $blockers[] = "False action claims: {$falseAction}";
}
if ($hallucination > 0) {
    $blockers[] = "Knowledge hallucination flags: {$hallucination}";
}
if ($avg < 27) {
    $blockers[] = "Average score {$avg}/32 below 27 threshold";
}
if ($bands['FAIL'] > (int) ($n * 0.08)) {
    $blockers[] = 'Too many FAIL band results';
}

if ($blockers === [] && !in_array(false, $passCriteria, true)) {
    $classification = 'READY FOR CONTROLLED STAGING / TEST BOT';
}

$meta = [
    'generated_at'   => date('c'),
    'api_source'     => $api['source'],
    'model'          => $api['model'],
    'evaluations'    => $n,
    'api_calls'      => $apiCalls,
    'elapsed_sec'    => $elapsed,
    'bands'          => $bands,
    'bucket_counts'  => $counts,
    'avg_score'      => $avg,
    'critical_total' => $criticalTotal,
    'classification' => $classification,
    'blockers'       => $blockers,
    'production_agent_core_enabled' => defined('AGENT_CORE_ENABLED') && AGENT_CORE_ENABLED,
];

phase2e_live_write_report($traces, $meta, $outDir);

echo "\n=== Summary ===\n";
echo "Evaluations: {$n} | API calls: {$apiCalls} | Elapsed: {$elapsed}s\n";
echo "PASS: {$bands['PASS']} | REVIEW: {$bands['REVIEW']} | FAIL: {$bands['FAIL']}\n";
echo "Average score: {$avg}/32\n";
echo "Critical failures: {$criticalTotal}\n";
echo "Classification: {$classification}\n";
echo "Report: tests/output/phase2e-live-report.md\n";
echo "Trace JSON: tests/output/phase2e-live-results.json\n";

exit($classification === 'NOT READY' ? 1 : 0);
