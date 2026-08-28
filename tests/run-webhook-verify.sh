#!/usr/bin/env bash
# Live Meta GET handshake against the real IQPigeon webhook path:
#   {APP_URL}/api/whatsapp-webhook.php
#
#   bash tests/run-webhook-verify.sh
#   BASE_URL=https://iqpigeon.com VERIFY_TOKEN=... bash tests/run-webhook-verify.sh
#
# Never prints the verify token. Never uses YOUR_META_VERIFY_TOKEN.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ ! -f "${SCRIPT_DIR}/_iqp.sh" ]]; then
  echo "FAIL  tests/_iqp.sh is missing next to this script"
  exit 1
fi
# shellcheck source=tests/_iqp.sh
source "${SCRIPT_DIR}/_iqp.sh"

SEARCH_FROM="$(cd "${SCRIPT_DIR}/.." && pwd)"
APP_ROOT=""
APP_ROOT="$(iqp_find_app_root "$SEARCH_FROM" || true)"

echo "IQPigeon WhatsApp webhook GET verify"
if [[ -n "$APP_ROOT" ]]; then
  echo "App root: ${APP_ROOT}"
else
  echo "App root: (not found — using env / defaults)"
fi

iqp_load_verify_config "${APP_ROOT:-}"

# Optional CLI: bash tests/run-webhook-verify.sh [BASE_URL]
if [[ "${1:-}" == http* ]]; then
  IQP_BASE_URL="${1%/}"
fi

PATH_OK="${IQP_VERIFY_PATH:-/api/whatsapp-webhook.php}"
if [[ "$PATH_OK" != /* ]]; then
  PATH_OK="/${PATH_OK}"
fi

echo "Path:     ${PATH_OK}"
if [[ -n "$IQP_BASE_URL" ]]; then
  echo "Base URL: ${IQP_BASE_URL}"
else
  echo "Base URL: (missing)"
fi
if [[ -n "$IQP_VERIFY_TOKEN" ]]; then
  echo "Token:    set (len=${#IQP_VERIFY_TOKEN})"
else
  echo "Token:    missing"
fi
echo ""

if ! command -v curl >/dev/null 2>&1; then
  iqp_skip "curl not found"
  iqp_totals
  exit $?
fi

if [[ -z "$IQP_BASE_URL" ]]; then
  iqp_skip "BASE_URL / APP_URL not set — cannot hit the public webhook (export BASE_URL or place config.php with APP_URL)"
  iqp_totals
  exit $?
fi

if [[ -z "$IQP_VERIFY_TOKEN" ]]; then
  iqp_skip "VERIFY_TOKEN / WEBHOOK_VERIFY_TOKEN not set — cannot complete hub.verify (export VERIFY_TOKEN; do not paste YOUR_META_VERIFY_TOKEN)"
  iqp_totals
  exit $?
fi

PRIMARY="${IQP_BASE_URL}${PATH_OK}"
LEGACY="${IQP_BASE_URL}/api/whatsapp/webhook.php"
ENC_TOKEN="$(printf '%s' "$IQP_VERIFY_TOKEN" | sed 's/ /%20/g')"

raw="$(iqp_http_get "$PRIMARY")"
iqp_split_http "$raw"
if [[ "$IQP_HTTP_CODE" == "200" && "$IQP_HTTP_BODY" == *"webhook is online"* ]]; then
  iqp_pass "GET ${PATH_OK} is online"
else
  iqp_fail "GET ${PATH_OK} is online (HTTP ${IQP_HTTP_CODE})"
fi

CHAL3="abc"
raw="$(iqp_http_get "${PRIMARY}?hub.mode=subscribe&hub.verify_token=${ENC_TOKEN}&hub.challenge=${CHAL3}")"
iqp_split_http "$raw"
if [[ "$IQP_HTTP_CODE" == "200" && "$IQP_HTTP_BODY" == "$CHAL3" ]]; then
  iqp_pass "short hub.challenge echoes exactly"
else
  iqp_fail "short hub.challenge echoes exactly (HTTP ${IQP_HTTP_CODE} body_len=${#IQP_HTTP_BODY})"
fi

CHAL32="$(iqp_random_challenge)"
if [[ "${#CHAL32}" -ne 32 ]]; then
  iqp_fail "generated challenge is 32 characters (got len=${#CHAL32})"
else
  raw="$(iqp_http_get "${PRIMARY}?hub.mode=subscribe&hub.verify_token=${ENC_TOKEN}&hub.challenge=${CHAL32}")"
  iqp_split_http "$raw"
  if [[ "$IQP_HTTP_CODE" == "200" && "$IQP_HTTP_BODY" == "$CHAL32" ]]; then
    iqp_pass "random 32-char hub.challenge echoes exactly (Meta GET verify)"
  elif [[ "$IQP_HTTP_CODE" == "200" && -z "$IQP_HTTP_BODY" ]]; then
    iqp_fail "random 32-char hub.challenge echoes exactly — empty body (HTML sanitizer still stripping Meta challenge)"
  elif [[ "$IQP_HTTP_CODE" == "403" ]]; then
    iqp_fail "random 32-char hub.challenge echoes exactly — HTTP 403 (verify token does not match WEBHOOK_VERIFY_TOKEN)"
  else
    iqp_fail "random 32-char hub.challenge echoes exactly (HTTP ${IQP_HTTP_CODE} body_len=${#IQP_HTTP_BODY})"
  fi
fi

raw="$(iqp_http_get "${PRIMARY}?hub.mode=subscribe&hub.verify_token=wrong-token-value&hub.challenge=${CHAL3}")"
iqp_split_http "$raw"
if [[ "$IQP_HTTP_CODE" == "403" ]]; then
  iqp_pass "wrong verify token is HTTP 403"
else
  iqp_fail "wrong verify token is HTTP 403 (HTTP ${IQP_HTTP_CODE})"
fi

raw="$(iqp_http_get "${LEGACY}?hub.mode=subscribe&hub.verify_token=${ENC_TOKEN}&hub.challenge=${CHAL3}")"
iqp_split_http "$raw"
if [[ "$IQP_HTTP_CODE" == "200" && "$IQP_HTTP_BODY" == "$CHAL3" ]]; then
  iqp_pass "legacy /api/whatsapp/webhook.php short challenge"
elif [[ "$IQP_HTTP_CODE" == "000" || "$IQP_HTTP_CODE" == "404" ]]; then
  iqp_skip "legacy /api/whatsapp/webhook.php not reachable (HTTP ${IQP_HTTP_CODE})"
else
  iqp_fail "legacy /api/whatsapp/webhook.php short challenge (HTTP ${IQP_HTTP_CODE} body_len=${#IQP_HTTP_BODY})"
fi

if [[ "${#CHAL32}" -eq 32 ]]; then
  raw="$(iqp_http_get "${LEGACY}?hub.mode=subscribe&hub.verify_token=${ENC_TOKEN}&hub.challenge=${CHAL32}")"
  iqp_split_http "$raw"
  if [[ "$IQP_HTTP_CODE" == "200" && "$IQP_HTTP_BODY" == "$CHAL32" ]]; then
    iqp_pass "legacy 32-char hub.challenge echoes exactly"
  elif [[ "$IQP_HTTP_CODE" == "000" || "$IQP_HTTP_CODE" == "404" ]]; then
    iqp_skip "legacy 32-char challenge — endpoint not reachable (HTTP ${IQP_HTTP_CODE})"
  else
    iqp_fail "legacy 32-char hub.challenge echoes exactly (HTTP ${IQP_HTTP_CODE} body_len=${#IQP_HTTP_BODY})"
  fi
fi

iqp_totals
exit $?
