#!/usr/bin/env bash
# Qualification helpers (no database required).
#
#   bash tests/run-qualification.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tests/_php.sh
source "${SCRIPT_DIR}/_php.sh"

ROOT="$(iqp_repo_root)"
cd "$ROOT"
PHP="$(iqp_find_php)"

echo "Using PHP: $PHP"
"$PHP" "${ROOT}/tests/qualification-flow-test.php"
