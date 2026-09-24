# MyCRM → IQPigeon WhatsApp API test client

## URLs

| URL | Purpose |
|-----|---------|
| `https://iqpigeon.com/mycrm` | Test CRM UI |
| `https://iqpigeon.com/mycrm-iqpigeon-webhook` | Partner webhook receiver (IQPIGEON_API mode) |

Do **not** point Meta webhooks at MyCRM when using `iqpigeon_api` transport.

## Server configuration

Copy `mycrm.local.example.php` → `mycrm.local.php` on the server.

```php
define('MYCRM_TRANSPORT', 'iqpigeon_api');
define('IQPIGEON_API_BASE_URL', 'https://whatsappapi.iqpigeon.com');
define('IQPIGEON_API_KEY', '...');           // from dashboard, once
define('IQPIGEON_CONNECTION_ID', '...');    // connection UUID
define('IQPIGEON_WEBHOOK_SECRET', '...');   // from webhook endpoint setup
```

Register the webhook URL in **whatsappapi.iqpigeon.com** → Webhooks.

## IQPigeon API endpoints used

- `GET /api/v1/me`
- `GET /api/v1/connections`
- `POST /api/v1/messages` with `Idempotency-Key`

## Webhook verification

Headers: `X-IQP-Signature`, `X-IQP-Timestamp`, `X-IQP-Event-Id`, `X-IQP-Request-Id`  
Signature: `HMAC-SHA256(secret, timestamp + "." + raw_body)`

## Manual test checklist

1. Activate plan + connect WhatsApp on whatsappapi.iqpigeon.com  
2. Configure `mycrm.local.php`  
3. Open `/mycrm` → **Test API Connection**  
4. Send test message  
5. Reply from WhatsApp → verify `/mycrm-iqpigeon-webhook` delivery in conversation list  
