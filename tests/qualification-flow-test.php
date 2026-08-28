<?php
/**
 * Per-bot qualification flow helpers — no database required.
 * Run: php tests/qualification-flow-test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/qualification-flow.php';

$passed = 0;
$failed = 0;

function qf_assert(bool $cond, string $name): void
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

echo "Qualification Flow Tests\n";
echo 'Time: ' . date('Y-m-d H:i:s') . "\n\n";

$fromStrings = qualification_normalize_questions([
    'What are you looking to buy?',
    'Which city for delivery?',
    'Cash on delivery or prepaid?',
    '',
]);
qf_assert(count($fromStrings) === 3, 'blank questions dropped');
qf_assert($fromStrings[0]['type'] === 'Product', 'buy question typed as Product');
qf_assert($fromStrings[1]['type'] === 'Location', 'city question typed as Location');
qf_assert($fromStrings[2]['required'] === true, 'string questions default required');

$fromObjects = qualification_normalize_questions([
    ['text' => 'What is your budget?', 'type' => 'Budget', 'required' => '0'],
    ['text' => 'When can you visit?', 'type' => 'Bogus'],
    ['question' => 'Buy or rent?'],
], false);
qf_assert($fromObjects[0]['required'] === false, 'required=0 is optional');
qf_assert($fromObjects[1]['type'] === 'Availability', 'unknown type inferred from visit');
qf_assert($fromObjects[1]['required'] === false, 'POST rows without checkbox are optional');
qf_assert($fromObjects[2]['text'] === 'Buy or rent?', 'question key accepted');
qf_assert($fromObjects[2]['type'] === 'Intent', 'buy or rent inferred as Intent');

$decoded = qualification_decode_questions(json_encode([
    ['text' => 'Which program?', 'type' => 'Product'],
]));
qf_assert($decoded[0]['text'] === 'Which program?', 'JSON decode keeps text');

$restaurant = qualification_questions_from_industry(industry_template('restaurant'));
qf_assert($restaurant !== [], 'restaurant template has starter questions');
$texts = array_column($restaurant, 'text');
qf_assert(in_array('Delivery or pickup?', $texts, true), 'restaurant includes delivery or pickup');

$clinic = qualification_defaults_for_bot(['industry_key' => 'health']);
qf_assert($clinic['conversion_goal'] === 'call_booked', 'clinic goal is call booked');
qf_assert($clinic['business_mode'] === 'services', 'clinic mode is services');
qf_assert($clinic['qualify_trigger'] !== '', 'clinic gets a default trigger');

$shop = qualification_defaults_for_bot(['industry_key' => 'ecommerce']);
qf_assert($shop['conversion_goal'] === 'order_placed', 'ecommerce goal is order placed');
qf_assert(str_contains(mb_strtolower($shop['qualify_trigger']), 'payment'), 'shop trigger mentions payment');

$emptyBot = [
    'industry_key'          => 'restaurant',
    'qualifying_questions'  => '[]',
    'qualify_trigger'       => '',
    'qualification_custom'  => 0,
];
$effective = qualification_effective_questions($emptyBot);
qf_assert($effective !== [], 'empty bot still gets industry questions at runtime');

$customBot = [
    'industry_key'         => 'restaurant',
    'qualifying_questions' => json_encode([['text' => 'Do you want BBQ or broast?', 'type' => 'Product', 'required' => true]]),
    'qualification_custom' => 1,
];
$customQs = qualification_effective_questions($customBot);
qf_assert(($customQs[0]['text'] ?? '') === 'Do you want BBQ or broast?', 'custom questions win over industry');
qf_assert(qualification_is_custom($customBot) === true, 'custom flag detected');

$load = qualification_load_for_bot($customBot + ['business_mode' => 'ecommerce', 'conversion_goal' => 'order_placed']);
qf_assert($load['custom'] === true, 'load reports custom');
qf_assert($load['questions'][0]['text'] === 'Do you want BBQ or broast?', 'load keeps stored custom question');

$tooMany = [];
for ($i = 0; $i < 20; $i++) {
    $tooMany[] = 'Question ' . $i . '?';
}
qf_assert(count(qualification_normalize_questions($tooMany)) === 12, 'caps at 12 questions');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
