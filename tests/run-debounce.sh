#!/usr/bin/env bash
# Debounce / quiet-window source checks (no WhatsApp send).
#
#   bash tests/run-debounce.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tests/_php.sh
source "${SCRIPT_DIR}/_php.sh"

ROOT="$(iqp_repo_root)"
cd "$ROOT"
PHP="$(iqp_find_php)"

echo "Using PHP: $PHP"
echo "7-second debounce source checks"
echo ""

"$PHP" -r '
require_once "config.php";
require_once "includes/conversation-turn-engine.php";

$failed = 0;
$passed = 0;
function check($ok, $name) {
    global $failed, $passed;
    if ($ok) { echo "PASS  $name\n"; $passed++; }
    else { echo "FAIL  $name\n"; $failed++; }
}

$c = turn_engine_constants();
check(function_exists("turn_engine_quiet_seconds"), "quiet_seconds exists");
check(turn_engine_quiet_seconds() >= 7, "quiet window is at least 7 seconds");
check((int) $c["text_debounce_ms"] >= 7000, "text debounce >= 7000ms");
check((int) $c["media_debounce_ms"] >= 7000, "media debounce >= 7000ms");

$src = file_get_contents("includes/conversation-turn-engine.php");
check(is_string($src) && str_contains($src, "Silent wait until 7s after last inbound"), "wait comment is 7s after last inbound");
check(is_string($src) && str_contains($src, "still_waiting"), "still_waiting path exists");
$waitPos = is_string($src) ? strpos($src, "turn_engine_webhook_wait_quiet([\$leadId]") : false;
$typePos = is_string($src) ? strpos($src, "whatsapp_send_typing_indicator(\$phoneId, \$token, \$waId)") : false;
check($waitPos !== false && $typePos !== false && $waitPos < $typePos, "typing starts after quiet wait");
check(is_string($src) && str_contains($src, "WAITING_FOR_DEBOUNCE"), "inbound message resets debounce");

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
'
