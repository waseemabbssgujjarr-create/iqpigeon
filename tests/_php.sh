#!/usr/bin/env bash
# Shared PHP locator for IQPigeon CLI tests (Linux, cPanel, Git Bash on Windows).

iqp_repo_root() {
  cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd
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
    /c/wamp64/bin/php/php8.2.0/php.exe \
    /c/wamp64/bin/php/php8.3.0/php.exe \
    /c/laragon/bin/php/php.exe \
    /c/php/php.exe \
    /c/tools/php/php.exe \
    "/c/Program Files/php/php.exe" \
    "$HOME/scoop/apps/php/current/php.exe"
  do
    if [[ -x "$candidate" ]]; then
      printf '%s\n' "$candidate"
      return 0
    fi
  done
  echo "ERROR: php not found. Install PHP or add it to PATH." >&2
  return 1
}

iqp_run_php_test() {
  local php_bin="$1"
  local script="$2"
  echo ""
  echo "======== $(basename "$script") ========"
  "$php_bin" "$script"
}
