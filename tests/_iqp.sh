#!/usr/bin/env bash
# Shared helpers for IQPigeon WhatsApp tests.
# Discovers the real application root by locating api/whatsapp-webhook.php
# (cPanel home, public_html, or the repo that contains tests/).

iqp_pass=0
iqp_fail=0
iqp_skip=0

iqp_script_dir() {
  cd "$(dirname "${BASH_SOURCE[1]:-${BASH_SOURCE[0]}}")" && pwd
}

iqp_pass() {
  echo "PASS  $1"
  iqp_pass=$((iqp_pass + 1))
}

iqp_fail() {
  echo "FAIL  $1"
  iqp_fail=$((iqp_fail + 1))
}

iqp_skip() {
  echo "SKIP  $1"
  iqp_skip=$((iqp_skip + 1))
}

iqp_check() {
  if [[ "$1" -eq 1 ]]; then
    iqp_pass "$2"
  else
    iqp_fail "$2"
  fi
}

iqp_totals() {
  echo ""
  echo "PASS=${iqp_pass}  FAIL=${iqp_fail}  SKIP=${iqp_skip}"
  if [[ "$iqp_fail" -gt 0 ]]; then
    echo "RESULT: FAIL"
    return 1
  fi
  echo "RESULT: PASS"
  return 0
}

iqp_find_php() {
  local candidate
  if command -v php >/dev/null 2>&1; then
    command -v php
    return 0
  fi
  for candidate in \
    /usr/bin/php \
    /usr/local/bin/php \
    /opt/alt/php83/usr/bin/php \
    /opt/alt/php82/usr/bin/php \
    /opt/cpanel/ea-php83/root/usr/bin/php \
    /opt/cpanel/ea-php82/root/usr/bin/php \
    /opt/cpanel/ea-php81/root/usr/bin/php \
    /c/xampp/php/php.exe \
    /c/php/php.exe \
    /c/laragon/bin/php/php.exe
  do
    if [[ -x "$candidate" ]]; then
      printf '%s\n' "$candidate"
      return 0
    fi
  done
  return 1
}

# Walk likely cPanel / repo layouts. Never uses a placeholder path.
iqp_find_app_root() {
  local start="${1:-}"
  local cand found
  if [[ -z "$start" ]]; then
    start="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  fi

  for cand in \
    "$start" \
    "$start/public_html" \
    "$start/www" \
    "$start/app.iqpigeon.com" \
    "$start/iqpigeon.com" \
    "$start/iqpigeon" \
    "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  do
    if [[ -f "$cand/api/whatsapp-webhook.php" ]]; then
      cd "$cand" && pwd
      return 0
    fi
  done

  found="$(find "$start" -maxdepth 4 -type f -name 'whatsapp-webhook.php' -path '*/api/whatsapp-webhook.php' 2>/dev/null | head -n 1 || true)"
  if [[ -n "$found" && -f "$found" ]]; then
    cd "$(dirname "$found")/.." && pwd
    return 0
  fi

  return 1
}

iqp_read_php_define() {
  local name="$1"
  local file="$2"
  local line
  [[ -f "$file" ]] || return 1
  line="$(grep -E "define\\('${name}'" "$file" 2>/dev/null | head -n 1 || true)"
  [[ -n "$line" ]] || return 1
  printf '%s\n' "$line" | sed -E "s/.*'${name}'[[:space:]]*,[[:space:]]*'([^']*)'.*/\\1/"
}

iqp_file_has() {
  local needle="$1"
  local file="$2"
  [[ -f "$file" ]] && grep -qF -- "$needle" "$file"
}

iqp_first_line() {
  grep -nF -- "$1" "$2" 2>/dev/null | head -n 1 | cut -d: -f1
}

iqp_last_line() {
  grep -nF -- "$1" "$2" 2>/dev/null | tail -n 1 | cut -d: -f1
}

iqp_http_get() {
  local url="$1"
  curl -sS --max-time 20 -w $'\n%{http_code}' "$url" 2>/dev/null || printf '\n000'
}

iqp_http_post() {
  local url="$1"
  local body="$2"
  shift 2
  curl -sS --max-time 25 -w $'\n%{http_code}' -X POST \
    -H 'Content-Type: application/json' \
    "$@" \
    --data-binary "$body" \
    "$url" 2>/dev/null || printf '\n000'
}

iqp_split_http() {
  IQP_HTTP_CODE="$(printf '%s\n' "$1" | tail -n 1)"
  IQP_HTTP_BODY="$(printf '%s\n' "$1" | sed '$d' | tr -d '\r')"
}

iqp_random_challenge() {
  local raw=""
  if command -v openssl >/dev/null 2>&1; then
    raw="$(openssl rand -hex 16 2>/dev/null | tr -d '\r\n')"
  fi
  if [[ "${#raw}" -lt 32 ]]; then
    raw="${raw}$(date +%s 2>/dev/null)${RANDOM}${RANDOM}abcdef0123456789"
  fi
  raw="$(printf '%s' "$raw" | tr -d '\r\n[:space:]')"
  printf '%s' "${raw:0:32}"
}

iqp_load_verify_config() {
  local root="$1"
  IQP_VERIFY_PATH="${VERIFY_PATH:-/api/whatsapp-webhook.php}"
  IQP_BASE_URL="${BASE_URL:-}"
  IQP_VERIFY_TOKEN="${VERIFY_TOKEN:-}"

  if [[ -z "$IQP_BASE_URL" ]]; then
    IQP_BASE_URL="$(iqp_read_php_define APP_URL "${root}/config.local.php" 2>/dev/null || true)"
  fi
  if [[ -z "$IQP_BASE_URL" || "$IQP_BASE_URL" != http* ]]; then
    IQP_BASE_URL="$(iqp_read_php_define APP_URL "${root}/config.php" 2>/dev/null || true)"
  fi
  if [[ -z "$IQP_BASE_URL" || "$IQP_BASE_URL" != http* ]]; then
    IQP_BASE_URL=""
  fi
  IQP_BASE_URL="${IQP_BASE_URL%/}"

  if [[ -z "$IQP_VERIFY_TOKEN" ]]; then
    IQP_VERIFY_TOKEN="$(iqp_read_php_define WEBHOOK_VERIFY_TOKEN "${root}/config.local.php" 2>/dev/null || true)"
  fi
  if [[ -z "$IQP_VERIFY_TOKEN" || "$IQP_VERIFY_TOKEN" == WEBHOOK_VERIFY_TOKEN ]]; then
    IQP_VERIFY_TOKEN="$(iqp_read_php_define WEBHOOK_VERIFY_TOKEN "${root}/config.php" 2>/dev/null || true)"
  fi
  if [[ "$IQP_VERIFY_TOKEN" == WEBHOOK_VERIFY_TOKEN ]]; then
    IQP_VERIFY_TOKEN=""
  fi
}
