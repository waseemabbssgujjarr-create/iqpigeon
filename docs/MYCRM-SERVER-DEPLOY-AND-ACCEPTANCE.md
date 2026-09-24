# MyCRM server deploy & acceptance (overlay only)

## WinSCP overlay — upload to `iqpigeon.com` document root

Do **not** mirror-delete. Upload only:

```text
mycrm.php
mycrm-iqpigeon-webhook.php
mycrm.local.example.php

includes/mycrm/config.php
includes/mycrm/storage.php
includes/mycrm/iqpigeon-api-client.php
includes/mycrm/send.php
includes/mycrm/direct-meta.php
includes/mycrm/webhook-verify.php
includes/mycrm/webhook-events.php

storage/mycrm/.htaccess
storage/mycrm/.gitkeep

tests/mycrm-iqpigeon-api-test.php
docs/MYCRM-IQPIGEON-INTEGRATION.md
docs/MYCRM-SERVER-DEPLOY-AND-ACCEPTANCE.md

.htaccess          (merge: adds mycrm.local.php deny rule)
.gitignore         (merge: adds mycrm.local.php)
```

Create on server only (never commit):

```text
mycrm.local.php
```

Ensure `storage/mycrm/` is writable by PHP (messages/state JSON).

## `mycrm.local.php` — exact constant names

```php
define('MYCRM_TRANSPORT', 'iqpigeon_api');
define('IQPIGEON_API_BASE_URL', 'https://whatsappapi.iqpigeon.com');
define('IQPIGEON_API_KEY', '...');
define('IQPIGEON_CONNECTION_ID', '...');  // UUID from GET /api/v1/connections
define('IQPIGEON_WEBHOOK_SECRET', '...'); // endpoint secret from platform
```

Leave `MYCRM_META_*` empty in API mode.

## Platform webhook

- URL: `https://iqpigeon.com/mycrm-iqpigeon-webhook`
- Subscribe to **`messages`** (Meta field name) or `*`
- Secret must match `IQPIGEON_WEBHOOK_SECRET`

## Acceptance (four proofs before git push)

| # | Test | Pass criteria |
|---|------|----------------|
| 1 | **Test API Connection** | Flash OK; Status **Connected**; partner + connection from GET `/me` + `/connections`; **no** POST `/messages` |
| 2 | Outbound send | WhatsApp receives text; note HTTP status, `request_id`, message id, initial status in UI |
| 3 | Inbound reply | Second phone → “Hello MyCRM” → row in conversation via signed webhook only |
| 4 | Status | `statuses[]` in webhook → status line on message (e.g. sent/delivered/read) |

## Security (after happy path)

- Wrong `IQPIGEON_API_KEY` → error, no send
- Wrong `IQPIGEON_CONNECTION_ID` → `connection_not_found` (or equivalent), no send
- Same **Idempotency-Key** twice → one outbound logical send
- Replay same webhook (`id` / `X-IQP-Event-Id`) → response `duplicate`, one CRM row

## On-server smoke (optional)

```bash
php tests/mycrm-iqpigeon-api-test.php
```

(Live API tests still require manual UI steps above.)

## Git after acceptance — stage MyCRM only

Do **not** `git add .`. Example:

```bash
git add mycrm.php mycrm-iqpigeon-webhook.php mycrm.local.example.php
git add includes/mycrm/
git add storage/mycrm/.htaccess storage/mycrm/.gitkeep
git add tests/mycrm-iqpigeon-api-test.php
git add docs/MYCRM-IQPIGEON-INTEGRATION.md docs/MYCRM-SERVER-DEPLOY-AND-ACCEPTANCE.md
git add .gitignore .htaccess
git commit -m "feat: MyCRM IQPigeon API integration test client"
# push only when user confirms four proofs passed
```
