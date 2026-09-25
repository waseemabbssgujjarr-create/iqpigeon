<?php
/**
 * LIVE_WORLD targeted smoke tests (LW-001..006)
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/conversation-mind.php';
require_once $root . '/includes/conversation-response-validator.php';
require_once $root . '/includes/agent-core/bootstrap.php';
require_once $root . '/includes/agent-core/validate.php';
require_once $root . '/includes/agent-core/compose.php';
require_once $root . '/includes/agent-core/pipeline.php';

$passed = 0;
$failed = 0;

function lw_assert(bool $ok, string $name): void
{
    global $passed, $failed;
    if ($ok) {
        echo "PASS: {$name}\n";
        $passed++;
    } else {
        echo "FAIL: {$name}\n";
        $failed++;
    }
}

echo "LIVE_WORLD SMOKE TESTS\n\n";

$bot = ['id' => 53, 'name' => 'Test Co', 'company_name' => 'Test Co'];
$evidence = 'Lahore is about 33 C and partly cloudy on Saturday per public weather sources.';
$search = ['needed' => true, 'ok' => true, 'evidence' => $evidence];

// LW-001
$GLOBALS['conversation_mind_test_live_openai'] = ['success' => true, 'content' => 'It is about 33 C and partly cloudy in Lahore today.'];
$ans = conversation_mind_live_answer($bot, 'What is the weather in Lahore?', [], $search);
$val = conversation_validate_customer_reply(0, $ans, 'weather');
lw_assert($ans !== '' && !empty($val['ok']), 'LW-001 usable evidence + openai → accepted reply');
unset($GLOBALS['conversation_mind_test_live_openai']);

// LW-002
$GLOBALS['conversation_mind_test_live_openai'] = [
    '_attempts' => [
        ['success' => false, 'content' => ''],
        ['success' => true, 'content' => 'Lahore is warm today at roughly 33 C.'],
    ],
];
$ans2 = conversation_mind_live_answer($bot, 'weather?', [], $search);
lw_assert(
    $ans2 === 'Lahore is warm today at roughly 33 C.'
    && !empty(conversation_validate_customer_reply(0, $ans2, 'weather')['ok']),
    'LW-002 retry attempt succeeds'
);
unset($GLOBALS['conversation_mind_test_live_openai']);

// LW-003
$GLOBALS['conversation_mind_test_live_openai'] = ['success' => false, 'content' => ''];
$ans3 = conversation_mind_live_answer($bot, 'weather?', [], $search);
$safe = conversation_mind_unverified_live_reply($bot);
lw_assert(
    $ans3 === ''
    && str_contains($safe, "couldn't verify")
    && !preg_match('/33\s*C/u', $safe),
    'LW-003 generation unavailable → safe unverified (no fabricated facts)'
);
unset($GLOBALS['conversation_mind_test_live_openai']);

// LW-004
$trunc = 'Lahore weather today is very hot and the forecast shows rising temperatures across Punjab without any end';
$val4 = conversation_validate_customer_reply(0, $trunc, 'weather');
lw_assert(empty($val4['ok']) && ($val4['reason'] ?? '') === 'truncated', 'LW-004 mid-sentence long reply rejected');

// LW-005
$complete = 'Lahore is about 33 C and partly cloudy today.';
$val5 = conversation_validate_customer_reply(0, $complete, 'weather');
lw_assert(!empty($val5['ok']), 'LW-005 complete short LIVE_WORLD answer accepted');

// LW-006
$intent = ['kind' => 'LIVE_WORLD', 'tools' => ['live_web.search']];
$turn = ['bot' => $bot, 'lead_id' => 0, 'text' => 'weather'];
$plan = ['outcome' => 'LIVE_WORLD'];
$bad = agent_core_validate('Reply with a number from our menu today.', $turn, $intent, $plan);
lw_assert(empty($bad['ok']) && ($bad['reason'] ?? '') === 'pitch_steal', 'LW-006 menu/commerce steal blocked');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
