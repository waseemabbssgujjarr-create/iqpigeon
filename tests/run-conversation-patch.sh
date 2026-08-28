#!/usr/bin/env bash
# Conversation patch checks: 7s debounce, memory isolation, source router, menu layout.
#
#   bash tests/run-conversation-patch.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tests/_php.sh
source "${SCRIPT_DIR}/_php.sh"

ROOT="$(iqp_repo_root)"
cd "$ROOT"

PHP="$(iqp_find_php)"
echo "Using PHP: $PHP"
echo "Conversation patch tests (debounce + memory + router + menu)"
echo ""

failed=0
echo "======== conversation-turn-engine-test.php ========"
if ! "$PHP" "${ROOT}/tests/conversation-turn-engine-test.php"; then
  failed=1
fi

echo ""
echo "======== conversation-intelligence-test.php ========"
if ! "$PHP" "${ROOT}/tests/conversation-intelligence-test.php"; then
  failed=1
fi

echo ""
if [[ "$failed" -ne 0 ]]; then
  echo "RESULT: conversation patch tests failed"
  exit 1
fi
echo "RESULT: conversation patch tests passed"
exit 0
