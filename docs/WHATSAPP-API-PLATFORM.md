# WhatsApp API platform (separate product)

The **IQPigeon WhatsApp API** (Partner API) is **not** part of the main IQPigeon CRM codebase or admin.

| | Main IQPigeon CRM | WhatsApp API platform |
|---|-------------------|------------------------|
| Purpose | WhatsApp CRM, bots, Agent Core, client businesses | External CRM / tech providers integrating via REST API |
| URL | Your CRM deployment | **https://whatsappapi.iqpigeon.com** |
| Codebase | This repository (`iqpigeon`) | **`iqpigeon-whatsapp-api`** (separate repo) |

## What the CRM does

- Provides a **header link** (“WhatsApp API”) that opens the platform in a **new tab**.
- Does **not** administer Partner API API keys, Stripe billing, partner provisioning, Partner API webhooks, or Partner API subscriptions from CRM admin.

## What the platform does

- Partner signup, Stripe **platform** subscription, API keys, scopes, connections (Embedded Signup), outbound webhooks, usage metering.

## Billing separation

IQPigeon **platform** subscription (Stripe on whatsappapi.iqpigeon.com) is separate from **Meta/WhatsApp messaging** charges. Connected businesses keep their own Meta billing; IQPigeon CRM does not pay or mark up Meta messaging for Partner API customers.

## No runtime bridge (MVP)

The CRM must not call the Partner API application for normal admin flows. No shared database, session, or authentication between products.
