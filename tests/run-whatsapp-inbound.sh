#!/usr/bin/env bash
# Real IQPigeon WhatsApp inbound tests against api/whatsapp-webhook.php.
#
#   bash tests/run-whatsapp-inbound.sh
#
# Discovers the application root (repo, public_html, or cPanel addon domain).
# HTTP checks use a local php -S copy of the real webhook when PHP is available,
# otherwise the live APP_URL diagnose endpoint. Never invents test PHP filenames.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ ! -f "${SCRIPT_DIR}/_iqp.sh" ]]; then
  echo "FAIL  tests/_iqp.sh is missing next to this script (needed to locate the real app)"
  exit 1
fi
# shellcheck source=tests/_iqp.sh
source "${SCRIPT_DIR}/_iqp.sh"

SEARCH_FROM="$(cd "${SCRIPT_DIR}/.." && pwd)"
APP_ROOT=""
if APP_ROOT="$(iqp_find_app_root "$SEARCH_FROM")"; then
  :
else
  APP_ROOT=""
fi

echo "IQPigeon WhatsApp inbound tests"
echo "Search from: ${SEARCH_FROM}"
if [[ -n "$APP_ROOT" ]]; then
  echo "App root:    ${APP_ROOT}"
else
  echo "App root:    (not found)"
fi
echo ""

WEBHOOK=""
ALIAS=""
ENGINE=""
WORKER=""
LOG=""
BOTCHAT=""
TRAINING=""
QUAL=""
SEC=""
CFG=""

if [[ -n "$APP_ROOT" && -f "${APP_ROOT}/api/whatsapp-webhook.php" ]]; then
  WEBHOOK="${APP_ROOT}/api/whatsapp-webhook.php"
  ALIAS="${APP_ROOT}/api/whatsapp/webhook.php"
  ENGINE="${APP_ROOT}/includes/conversation-turn-engine.php"
  WORKER="${APP_ROOT}/api/turn-worker.php"
  LOG="${APP_ROOT}/includes/whatsapp-webhook-log.php"
  BOTCHAT="${APP_ROOT}/api/bot-chat.php"
  TRAINING="${APP_ROOT}/includes/views/client-training.php"
  QUAL="${APP_ROOT}/includes/qualification-flow.php"
  SEC="${APP_ROOT}/includes/security-output.php"
  CFG="${APP_ROOT}/config.php"
  iqp_pass "located real webhook ${WEBHOOK#${APP_ROOT}/}"
else
  iqp_skip "api/whatsapp-webhook.php not found under ${SEARCH_FROM} (searched public_html, app.iqpigeon.com, iqpigeon.com, maxdepth 4). Point tests/ at the PHP app or run from the app repo."
fi

if [[ -n "$WEBHOOK" ]]; then
  iqp_file_has "hub.mode" "$WEBHOOK" && iqp_pass "GET handler reads hub.mode / hub.verify_token / hub.challenge" || iqp_fail "GET handler reads hub.mode / hub.verify_token / hub.challenge"
  iqp_file_has "function meta_webhook_verify_ok" "$WEBHOOK" && iqp_pass "GET verification uses meta_webhook_verify_ok" || iqp_fail "GET verification uses meta_webhook_verify_ok"
  iqp_file_has "echo \$challenge" "$WEBHOOK" && iqp_pass "GET subscribe echoes hub.challenge" || iqp_fail "GET subscribe echoes hub.challenge"
  iqp_file_has "verify_meta_signature" "$WEBHOOK" && iqp_pass "POST verifies Meta X-Hub-Signature-256" || iqp_fail "POST verifies Meta X-Hub-Signature-256"
  if iqp_file_has "HTTP_X_AILEADS_DIAGNOSE" "$WEBHOOK" || iqp_file_has "X_AILEADS_DIAGNOSE" "$WEBHOOK"; then
    iqp_pass "POST diagnose header can skip signature (self-test)"
  else
    iqp_fail "POST diagnose header can skip signature (self-test)"
  fi
  iqp_file_has "function wa_webhook_ack_meta" "$WEBHOOK" && iqp_pass "POST ACKs Meta via wa_webhook_ack_meta" || iqp_fail "POST ACKs Meta via wa_webhook_ack_meta"
  iqp_file_has "echo 'OK'" "$WEBHOOK" && iqp_pass "ACK body is OK" || iqp_fail "ACK body is OK"
  iqp_file_has "turn_engine_ingest" "$WEBHOOK" && iqp_pass "inbound messages are parsed into turn_engine_ingest" || iqp_fail "inbound messages are parsed into turn_engine_ingest"
  iqp_file_has "wa_webhook_event_id" "$WEBHOOK" && iqp_pass "webhook assigns wa_webhook_event_id" || iqp_fail "webhook assigns wa_webhook_event_id"
  iqp_file_has "bot_resolve_by_whatsapp_phone_id" "$WEBHOOK" && iqp_pass "inbound routes by WhatsApp phone_number_id" || iqp_fail "inbound routes by WhatsApp phone_number_id"

  INGEST="$(iqp_first_line 'turn_engine_ingest($bot' "$WEBHOOK")"
  ACK="$(iqp_last_line 'wa_webhook_ack_meta();' "$WEBHOOK")"
  MARK="$(iqp_last_line 'whatsapp_mark_message_read' "$WEBHOOK")"
  SEND="$(iqp_last_line 'turn_engine_send_leads_now' "$WEBHOOK")"
  [[ -n "$INGEST" && -n "$ACK" && "$INGEST" -lt "$ACK" ]] && iqp_pass "ingest/save is before HTTP 200 ACK in webhook.php" || iqp_fail "ingest/save is before HTTP 200 ACK in webhook.php"
  [[ -n "$ACK" && -n "$MARK" && "$ACK" -lt "$MARK" ]] && iqp_pass "mark-read is after HTTP 200 ACK" || iqp_fail "mark-read is after HTTP 200 ACK"
  [[ -n "$ACK" && -n "$SEND" && "$ACK" -lt "$SEND" ]] && iqp_pass "compose/send is after HTTP 200 ACK" || iqp_fail "compose/send is after HTTP 200 ACK"
  ! iqp_file_has "Inline send before Meta ACK" "$WEBHOOK" && iqp_pass "webhook does not hold Meta for inline send-before-ACK" || iqp_fail "webhook does not hold Meta for inline send-before-ACK"
fi

if [[ -n "$ENGINE" && -f "$ENGINE" ]]; then
  iqp_file_has "DUPLICATE_EVENT_IGNORED" "$ENGINE" && iqp_pass "turn engine ignores duplicate wa_message_id" || iqp_fail "turn engine ignores duplicate wa_message_id"
  iqp_file_has "Silent wait until 7s after last inbound" "$ENGINE" && iqp_pass "7s quiet window is in compose (not before ACK)" || iqp_fail "7s quiet window is in compose (not before ACK)"
else
  iqp_skip "includes/conversation-turn-engine.php not found — duplicate/debounce source check"
fi

if [[ -n "$LOG" && -f "$LOG" ]]; then
  iqp_file_has "event_id" "$LOG" && iqp_pass "whatsapp-webhook-log.php persists event_id" || iqp_fail "whatsapp-webhook-log.php persists event_id"
else
  iqp_skip "includes/whatsapp-webhook-log.php not found"
fi

if [[ -n "$WORKER" && -f "$WORKER" ]]; then
  iqp_file_has "lead_ids" "$WORKER" && iqp_pass "api/turn-worker.php is the async inbound composer" || iqp_fail "api/turn-worker.php is the async inbound composer"
  iqp_file_has "turn_engine_send_leads_now" "$WORKER" && iqp_pass "worker recover uses the webhook send pipeline" || iqp_fail "worker recover uses the webhook send pipeline"
  iqp_file_has 'dry' "$WORKER" && iqp_pass "worker supports dry=1 SELECT-only inspect" || iqp_fail "worker supports dry=1 SELECT-only inspect"
else
  iqp_skip "api/turn-worker.php not found"
fi

CRON="${APP_ROOT}/api/cron.php"
if [[ -f "$CRON" ]]; then
  iqp_file_has "-m 90" "$CRON" && iqp_file_has "nohup curl" "$CRON" && iqp_pass "cron detaches turn-worker for 90s" || iqp_fail "cron detaches turn-worker for 90s"
else
  iqp_skip "api/cron.php not found"
fi

if [[ -n "$BOTCHAT" && -f "$BOTCHAT" ]]; then
  if iqp_file_has "Training page" "$BOTCHAT" && ! grep -qF "whatsapp-webhook.php" "$BOTCHAT"; then
    iqp_pass "Test & Publish is api/bot-chat.php (not the Meta webhook)"
  else
    iqp_fail "Test & Publish is api/bot-chat.php (not the Meta webhook)"
  fi
else
  iqp_skip "api/bot-chat.php not found — Test vs Publish split"
fi

if [[ -n "$TRAINING" && -f "$TRAINING" ]]; then
  iqp_file_has "/api/bot-chat.php" "$TRAINING" && iqp_pass "training UI posts to /api/bot-chat.php" || iqp_fail "training UI posts to /api/bot-chat.php"
else
  iqp_skip "includes/views/client-training.php not found"
fi

if [[ -n "$QUAL" && -f "$QUAL" ]]; then
  iqp_pass "qualification lives in includes/qualification-flow.php (not the Meta webhook ACK path)"
else
  iqp_skip "includes/qualification-flow.php not found — qualification is not on the webhook hot path"
fi

if [[ -n "$SEC" && -f "$SEC" && -n "$CFG" && -f "$CFG" ]]; then
  iqp_file_has "security_output_is_webhook_script" "$CFG" && iqp_pass "config.php skips HTML sanitizer on webhook scripts" || iqp_fail "config.php skips HTML sanitizer on webhook scripts"
fi

if [[ -n "$ALIAS" && -f "$ALIAS" ]]; then
  iqp_file_has "whatsapp-webhook.php" "$ALIAS" && iqp_pass "legacy api/whatsapp/webhook.php delegates POST to primary webhook" || iqp_fail "legacy api/whatsapp/webhook.php delegates POST to primary webhook"
else
  iqp_skip "legacy api/whatsapp/webhook.php not present"
fi

# --- HTTP against the real webhook (local php -S or live diagnose) ---
iqp_load_verify_config "${APP_ROOT:-}"
HTTP_BASE=""
PHP_BIN=""
PHP_SERVER_PID=""
cleanup_php_server() {
  if [[ -n "${PHP_SERVER_PID}" ]]; then
    kill "${PHP_SERVER_PID}" 2>/dev/null || true
    wait "${PHP_SERVER_PID}" 2>/dev/null || true
    PHP_SERVER_PID=""
  fi
}
trap cleanup_php_server EXIT

if [[ -n "$APP_ROOT" ]] && PHP_BIN="$(iqp_find_php 2>/dev/null)"; then
  PORT="${IQP_HTTP_PORT:-18765}"
  "$PHP_BIN" -S "127.0.0.1:${PORT}" -t "$APP_ROOT" >/dev/null 2>&1 &
  PHP_SERVER_PID=$!
  HTTP_BASE="http://127.0.0.1:${PORT}"
  ready=0
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    if curl -sS --max-time 1 "${HTTP_BASE}/api/whatsapp-webhook.php" >/dev/null 2>&1; then
      ready=1
      break
    fi
    sleep 0.2
  done
  if [[ "$ready" -ne 1 ]]; then
    cleanup_php_server
    HTTP_BASE=""
    iqp_skip "php -S did not become ready on 127.0.0.1:${PORT} — falling back to live URL if configured"
  else
    probe="$(iqp_http_post "${HTTP_BASE}${IQP_VERIFY_PATH}" '{"object":"whatsapp_business_account","entry":[]}' -H 'X-AILeads-Diagnose: 1')"
    iqp_split_http "$probe"
    if [[ "$IQP_HTTP_CODE" != "200" ]]; then
      iqp_skip "local php -S POST returned HTTP ${IQP_HTTP_CODE} (often missing DB on this host) — falling back to live URL"
      cleanup_php_server
      HTTP_BASE=""
    else
      echo "HTTP target: ${HTTP_BASE} (local php -S of ${APP_ROOT})"
    fi
  fi
else
  iqp_skip "PHP CLI not available for php -S around the real webhook"
fi

if [[ -z "$HTTP_BASE" && -n "${IQP_BASE_URL}" ]]; then
  HTTP_BASE="$IQP_BASE_URL"
  echo "HTTP target: ${HTTP_BASE} (live APP_URL / BASE_URL)"
fi

WEBHOOK_URL=""
if [[ -n "$HTTP_BASE" ]]; then
  WEBHOOK_URL="${HTTP_BASE}${IQP_VERIFY_PATH}"
fi

if [[ -z "$WEBHOOK_URL" ]]; then
  iqp_skip "no HTTP target (need PHP for php -S, or BASE_URL / APP_URL) — cannot execute inbound POST/GET"
elif ! command -v curl >/dev/null 2>&1; then
  iqp_skip "curl not found — cannot execute inbound HTTP tests"
else
  raw="$(iqp_http_get "$WEBHOOK_URL")"
  iqp_split_http "$raw"
  if [[ "$IQP_HTTP_CODE" == "200" && "$IQP_HTTP_BODY" == *"webhook is online"* ]]; then
    iqp_pass "GET webhook without hub.* returns online 200"
  else
    iqp_fail "GET webhook without hub.* returns online 200 (HTTP ${IQP_HTTP_CODE})"
  fi

  EMPTY='{"object":"whatsapp_business_account","entry":[]}'
  raw="$(iqp_http_post "$WEBHOOK_URL" "$EMPTY" -H 'X-AILeads-Diagnose: 1')"
  iqp_split_http "$raw"
  if [[ "$IQP_HTTP_CODE" == "200" && "$IQP_HTTP_BODY" == "OK" ]]; then
    iqp_pass "diagnose POST empty entry is ACK 200 OK"
  else
    iqp_fail "diagnose POST empty entry is ACK 200 OK (HTTP ${IQP_HTTP_CODE} body='${IQP_HTTP_BODY:0:80}')"
  fi

  raw="$(iqp_http_post "$WEBHOOK_URL" 'not-json{' -H 'X-AILeads-Diagnose: 1')"
  iqp_split_http "$raw"
  if [[ "$IQP_HTTP_CODE" == "200" && "$IQP_HTTP_BODY" == "OK" ]]; then
    iqp_pass "malformed POST is ACK 200 OK (webhook does not 5xx)"
  else
    iqp_fail "malformed POST is ACK 200 OK (HTTP ${IQP_HTTP_CODE} body='${IQP_HTTP_BODY:0:80}')"
  fi

  WAMID="wamid.iqp.test.$(date +%s).${RANDOM}"
  UNKNOWN='{"object":"whatsapp_business_account","entry":[{"id":"0","changes":[{"field":"messages","value":{"messaging_product":"whatsapp","metadata":{"phone_number_id":"0","display_phone_number":"0"},"messages":[{"from":"00000000000","id":"'"$WAMID"'","timestamp":"1","type":"text","text":{"body":"inbound-suite"}}]}}]}]}'
  raw="$(iqp_http_post "$WEBHOOK_URL" "$UNKNOWN" -H 'X-AILeads-Diagnose: 1')"
  iqp_split_http "$raw"
  if [[ "$IQP_HTTP_CODE" == "200" && "$IQP_HTTP_BODY" == "OK" ]]; then
    iqp_pass "unknown phone_number_id inbound is ACK 200 (no bot match, no outbound send)"
  else
    iqp_fail "unknown phone_number_id inbound is ACK 200 (HTTP ${IQP_HTTP_CODE})"
  fi

  raw="$(iqp_http_post "$WEBHOOK_URL" "$UNKNOWN" -H 'X-AILeads-Diagnose: 1')"
  iqp_split_http "$raw"
  if [[ "$IQP_HTTP_CODE" == "200" && "$IQP_HTTP_BODY" == "OK" ]]; then
    iqp_pass "duplicate wamid POST is still ACK 200 (idempotent at webhook)"
  else
    iqp_fail "duplicate wamid POST is still ACK 200 (HTTP ${IQP_HTTP_CODE})"
  fi

  raw="$(iqp_http_post "$WEBHOOK_URL" "$EMPTY")"
  iqp_split_http "$raw"
  if [[ "$IQP_HTTP_CODE" == "200" && "$IQP_HTTP_BODY" == "OK" ]]; then
    iqp_pass "unsigned POST is ACK 200 without processing (invalid signature must not 403 Meta)"
  else
    iqp_fail "unsigned POST is ACK 200 without processing (HTTP ${IQP_HTTP_CODE} body='${IQP_HTTP_BODY:0:80}')"
  fi

  WA_LOG="${APP_ROOT}/storage/whatsapp-webhook.log"
  if [[ "$HTTP_BASE" == "http://127.0.0.1:"* || "$HTTP_BASE" == "http://localhost:"* ]]; then
    if [[ -f "$WA_LOG" ]] && grep -q "event_id" "$WA_LOG" && grep -q "POST received" "$WA_LOG"; then
      iqp_pass "storage/whatsapp-webhook.log contains event_id and POST received"
    elif [[ -f "$WA_LOG" ]]; then
      iqp_fail "storage/whatsapp-webhook.log exists but is missing event_id / POST received after local diagnose POST"
    else
      iqp_skip "storage/whatsapp-webhook.log not written (log dir not writable under ${APP_ROOT}/storage)"
    fi
  else
    iqp_skip "webhook log is on the HTTP host (${HTTP_BASE}), not this working copy — cannot verify event_id persistence here"
  fi
fi

cleanup_php_server
trap - EXIT

iqp_totals
exit $?
