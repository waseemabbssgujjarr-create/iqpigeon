# Moved — separate product

The IQPigeon WhatsApp API / Partner API platform is a **separate product** and repository.

| | |
|---|---|
| **Production URL** | https://whatsappapi.iqpigeon.com |
| **Repository** | `iqpigeon-whatsapp-api` |
| **Local path** | `../iqpigeon-whatsapp-api/` |

The main **IQPigeon CRM** (`iqpigeon`) links to the platform from the app header only. It does not host Partner API admin, billing, or provisioning.

See also:

- `docs/WHATSAPP-API-PLATFORM.md` (in the main CRM repo)
- `docs/ARCHITECTURE.md` (pointer to the separate repo)
- `docs/PHASE0-SIGNOFF.md`

Do not add Laravel application code under this folder in the main `iqpigeon` repo.
