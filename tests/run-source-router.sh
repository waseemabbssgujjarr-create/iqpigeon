#!/usr/bin/env bash
# Source-router only: current affairs vs orders vs hours vs catalog vs general knowledge.
#
#   bash tests/run-source-router.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tests/_php.sh
source "${SCRIPT_DIR}/_php.sh"

ROOT="$(iqp_repo_root)"
cd "$ROOT"
PHP="$(iqp_find_php)"

echo "Using PHP: $PHP"
echo "Source router (no OpenAI calls)"
echo ""

"$PHP" -r '
require_once "config.php";
require_once "includes/live-world-info.php";
require_once "includes/conversation-source-router.php";
require_once "includes/conversation-runtime-memory.php";
require_once "includes/whatsapp-auto-reply-core.php";
require_once "includes/helpers.php";

$failed = 0;
$passed = 0;
function check($ok, $name) {
    global $failed, $passed;
    if ($ok) { echo "PASS  $name\n"; $passed++; }
    else { echo "FAIL  $name\n"; $failed++; }
}

check(!live_world_should_search("What did I order yesterday?"), "order yesterday != web");
check(conversation_source_route("What did I order yesterday?")["primary"] === "CUSTOMER_HISTORY", "order -> CUSTOMER_HISTORY");
check(!live_world_should_search("What did we discuss yesterday?"), "discuss yesterday != web");
check(conversation_source_route("What did we discuss yesterday?")["primary"] === "CONVERSATION_MEMORY", "discuss -> CONVERSATION_MEMORY");
check(!live_world_should_search("What did you recommend to me yesterday?"), "recommend yesterday != web");
check(!live_world_should_search("What is your current burger price?"), "current burger price != web");
check(!live_world_should_search("Are you open today?"), "open today != web");
check(!live_world_should_search("What are your services?"), "services != web");
check(!live_world_should_search("What is photosynthesis?"), "photosynthesis != web");
check(!live_world_should_search("I currently live in Lahore"), "currently live != web");
check(live_world_should_search("Who is the current president of the USA?"), "US president -> web");
check(live_world_should_search("Who is the current Prime Minister of Pakistan?"), "Pakistan PM -> web");
check(live_world_should_search("Who is the current Army Chief?"), "army chief -> web");
check(live_world_should_search("What happened in Pakistan yesterday?"), "Pakistan news -> web");
check(live_world_should_search("Are you open tonight and who is the current PM of Pakistan?"), "mixed hours+PM -> web");
check(conversation_source_route("Are you open tonight and who is the current PM of Pakistan?")["needs_hours"] === true, "mixed still loads hours");
check(live_world_should_search("And the army chief?", "user: who is the current PM of Pakistan"), "follow-up army chief uses thread");
check(live_world_should_search("Who runs America right now?"), "who runs America -> web");
check(live_world_should_search("Do you offer AI automation, and what is the latest OpenAI model?"), "mixed business+OpenAI -> web");

$facts = conversation_runtime_extract_facts("My name is Ahmed. I am interested in your premium service. My budget is 500k. I want it next month.");
check(($facts["customer_name"] ?? "") === "Ahmed", "extract name Ahmed");
check(isset($facts["budget"]), "extract budget");
check(conversation_runtime_extract_facts("Who is the current president of the USA?") === [], "do not store web Q as facts");

$menu = wa_webhook_menu_text(["name" => "Test Kitchen"], [
    ["name" => "Garlic Butter Pizza", "price" => 899, "currency" => "PKR", "category" => "Pizzas"],
    ["name" => "Gangsta Burger", "price" => 1250, "currency" => "PKR", "category" => "Burgers"],
]);
check(str_contains($menu, "reply with a number"), "menu keeps number reply");
check(str_contains($menu, "💰"), "menu has price lines");
check(live_world_should_search("Who is the Prime Minister of Pakistan?"), "PM without current -> web");
check(conversation_source_route("Who is the Prime Minister of Pakistan?")["primary"] === "LIVE_WEB", "PM -> LIVE_WEB");
check(str_contains($menu, "\n"), "menu keeps line breaks");
$clean = conversation_sanitize_customer_facing($menu);
check(str_contains($clean, "\n"), "sanitize keeps menu line breaks");
check(!str_contains(conversation_sanitize_customer_facing("I offer [skills/services] for [clients]."), "[clients]"), "placeholders stripped");

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
'
