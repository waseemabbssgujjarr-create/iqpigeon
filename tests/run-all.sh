#!/usr/bin/env bash
# Run IQPigeon's actual tests: custom tests/*.php (not PHPUnit), then bash suite.
#
#   bash tests/run-all.sh
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ ! -f "${SCRIPT_DIR}/_iqp.sh" ]]; then
  echo "FAIL  tests/_iqp.sh is missing next to this script"
  exit 1
fi
# shellcheck source=tests/_iqp.sh
source "${SCRIPT_DIR}/_iqp.sh"

SEARCH_FROM="$(cd "${SCRIPT_DIR}/.." && pwd)"
APP_ROOT="$(iqp_find_app_root "$SEARCH_FROM" || true)"

echo "IQPigeon complete test suite"
echo "Tests dir: ${SCRIPT_DIR}"
if [[ -n "$APP_ROOT" ]]; then
  echo "App root:  ${APP_ROOT}"
else
  echo "App root:  (not found from ${SEARCH_FROM})"
fi
echo ""

groups_fail=0

# This project has no phpunit.xml and composer.json does not require phpunit/pest.
if [[ -f "${SCRIPT_DIR}/phpunit.xml" || -f "${SEARCH_FROM}/phpunit.xml" || -f "${APP_ROOT:-/dev/null}/phpunit.xml" ]]; then
  echo "######## PHPUnit ########"
  iqp_fail "phpunit.xml exists but this runner does not invoke PHPUnit yet"
  groups_fail=$((groups_fail + 1))
else
  echo "######## PHPUnit ########"
  echo "SKIP  no phpunit.xml — IQPigeon uses custom tests/*.php, not PHPUnit"
  iqp_skip "PHPUnit (not used in this project)"
  echo ""
fi

PHP_BIN=""
PHP_BIN="$(iqp_find_php 2>/dev/null || true)"
if [[ -n "$PHP_BIN" ]]; then
  echo "PHP: $PHP_BIN"
  "$PHP_BIN" -v | head -n 1
  echo ""
else
  echo "PHP CLI: not found"
  echo ""
fi

# Only run PHP files that actually exist. Do not invent names.
PHP_TESTS=(
  qualification-flow-test.php
  conversation-turn-engine-test.php
  conversation-intelligence-test.php
  conversation-scenarios-test.php
  agent-core-test.php
  full-system-test.php
)

if [[ -z "$PHP_BIN" ]]; then
  echo "######## custom PHP tests ########"
  iqp_skip "PHP CLI not on PATH — cannot run tests/*.php"
  echo ""
else
  for name in "${PHP_TESTS[@]}"; do
    file="${SCRIPT_DIR}/${name}"
    echo "######## ${name} ########"
    if [[ ! -f "$file" ]]; then
      echo "SKIP  ${name} not present in tests/"
      iqp_skip "${name} not present"
      echo ""
      continue
    fi
    if [[ -n "$APP_ROOT" ]]; then
      pushd "$APP_ROOT" >/dev/null
    else
      pushd "$SEARCH_FROM" >/dev/null
    fi
    if "$PHP_BIN" "$file"; then
      iqp_pass "${name}"
    else
      iqp_fail "${name}"
      groups_fail=$((groups_fail + 1))
    fi
    popd >/dev/null
    echo ""
  done
fi

echo "######## bash suite ########"
if [[ -f "${SCRIPT_DIR}/run-bash-suite.sh" ]]; then
  if bash "${SCRIPT_DIR}/run-bash-suite.sh"; then
    iqp_pass "run-bash-suite.sh"
  else
    iqp_fail "run-bash-suite.sh"
    groups_fail=$((groups_fail + 1))
  fi
else
  echo "SKIP  run-bash-suite.sh not next to this script (${SCRIPT_DIR})"
  iqp_skip "run-bash-suite.sh not found"
fi

echo ""
echo "PASS=${iqp_pass}  FAIL=${iqp_fail}  SKIP=${iqp_skip}"
if [[ "$groups_fail" -gt 0 || "$iqp_fail" -gt 0 ]]; then
  echo "FINAL RESULT: FAIL"
  exit 1
fi
echo "FINAL RESULT: PASS"
exit 0
