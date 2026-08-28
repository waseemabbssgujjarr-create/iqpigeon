#!/usr/bin/env bash
# Run the Bash/integration tests that actually exist next to this script.
#
#   bash tests/run-bash-suite.sh
#   bash tests/run-bash-suite.sh --skip-live
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ ! -f "${SCRIPT_DIR}/_iqp.sh" ]]; then
  echo "FAIL  tests/_iqp.sh is missing next to this script"
  exit 1
fi
# shellcheck source=tests/_iqp.sh
source "${SCRIPT_DIR}/_iqp.sh"

SKIP_LIVE=0
for arg in "$@"; do
  if [[ "$arg" == "--skip-live" ]]; then
    SKIP_LIVE=1
  fi
done

echo "IQPigeon bash / integration suite"
echo "Scripts: ${SCRIPT_DIR}"
echo ""

groups_fail=0

run_script() {
  local file="$1"
  local label="$2"
  echo "######## ${label} ########"
  if [[ ! -f "$file" ]]; then
    echo "SKIP  ${label} — file not present: ${file}"
    iqp_skip "${label} not present"
    echo ""
    return 0
  fi
  if bash "$file"; then
    echo ""
    return 0
  fi
  groups_fail=$((groups_fail + 1))
  echo ""
  return 0
}

run_script "${SCRIPT_DIR}/run-whatsapp-inbound.sh" "run-whatsapp-inbound.sh"

if [[ "$SKIP_LIVE" -eq 1 ]]; then
  echo "######## run-webhook-verify.sh ########"
  echo "SKIP  run-webhook-verify.sh (--skip-live)"
  iqp_skip "run-webhook-verify.sh (--skip-live)"
  echo ""
else
  run_script "${SCRIPT_DIR}/run-webhook-verify.sh" "run-webhook-verify.sh"
fi

# Optional extra bash tests — SKIP if this copy of tests/ does not include them,
# or if they need PHP CLI and none is available.
if iqp_find_php >/dev/null 2>&1; then
  run_script "${SCRIPT_DIR}/run-debounce.sh" "run-debounce.sh"
  run_script "${SCRIPT_DIR}/run-source-router.sh" "run-source-router.sh"
  run_script "${SCRIPT_DIR}/run-qualification.sh" "run-qualification.sh"
else
  echo "######## optional PHP bash tests ########"
  if [[ -f "${SCRIPT_DIR}/run-debounce.sh" ]]; then
    iqp_skip "run-debounce.sh (PHP CLI not found)"
  else
    iqp_skip "run-debounce.sh not present"
  fi
  if [[ -f "${SCRIPT_DIR}/run-source-router.sh" ]]; then
    iqp_skip "run-source-router.sh (PHP CLI not found)"
  else
    iqp_skip "run-source-router.sh not present"
  fi
  if [[ -f "${SCRIPT_DIR}/run-qualification.sh" ]]; then
    iqp_skip "run-qualification.sh (PHP CLI not found)"
  else
    iqp_skip "run-qualification.sh not present"
  fi
  echo ""
fi

echo "PASS=${iqp_pass}  FAIL=${iqp_fail}  SKIP=${iqp_skip}  (suite-level SKIP for missing optional scripts)"
if [[ "$groups_fail" -gt 0 ]]; then
  echo "RESULT: FAIL (${groups_fail} script group(s) failed)"
  exit 1
fi
echo "RESULT: PASS"
exit 0
