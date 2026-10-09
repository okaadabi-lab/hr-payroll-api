# Integration contract: payroll outbox

Payroll events are published through a **transactional outbox**. When a payroll period is
approved or marked paid, the API writes the state change, the audit entry and one event per
payslip into `outbox_events` **in the same database transaction**. Either all of it is
committed or none of it is, so a consumer can never see an event for an approval that was
rolled back, and an approval can never be committed without its events.

Consumers pull events over HTTP and acknowledge them. The intended consumer is the n8n
project [omnichannel-inbox-automation](https://github.com/__GITHUB_USER__/omnichannel-inbox-automation)
(a separate repository), which sends each
employee a WhatsApp template message when their payslip is published and when salary is paid.

## Authentication

Machine clients use an API key, not a user JWT:

```
X-Api-Key: hrp_<12 hex prefix>_<48 hex secret>
```

- The key is shown once when it is created (`php bin/seed` prints one for local use).
- Only a SHA-256 hash of the key is stored; it is compared in constant time.
- The key must carry the scope `outbox:read`. A valid key without it gets 403
  `insufficient_scope`; a missing or wrong key gets 401 `invalid_api_key`.

## Reading events

```
GET /api/v1/integrations/outbox?after={id}&limit={1..100}
```

Returns **unacknowledged** events with `id > after`, oldest first. `limit` defaults to 50.

```json
{
  "data": [
    {
      "id": 41,
      "type": "payslip.published",
      "occurred_at": "2026-09-30T03:15:00Z",
      "payload": {
        "employee_id": 2,
        "employee_name": "Budi Santoso",
        "phone": "+62812000002",
        "period": "2026-08",
        "net_pay": 9275318,
        "payslip_url": "http://localhost:8082/api/v1/payslips/2"
      }
    }
  ],
  "meta": { "next_after": 41, "pending_total": 6 }
}
```

## Event shape

The field names below are a stable contract. New fields may be added; existing fields will
not be renamed or removed without a new event type.

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Monotonic per database. Use it to de-duplicate. |
| `type` | string | `payslip.published` or `payroll.paid` |
| `occurred_at` | string | UTC, ISO 8601 (`2026-09-30T03:15:00Z`) |
| `payload.employee_id` | integer | |
| `payload.employee_name` | string | Name at the time of calculation |
| `payload.phone` | string or null | E.164-style, null when unknown. The consumer decides the channel. |
| `payload.period` | string | `YYYY-MM` |
| `payload.net_pay` | integer | Rupiah, no decimals |
| `payload.payslip_url` | string | API URL; the employee needs to log in to open it |

| Type | Emitted when | One event per |
| --- | --- | --- |
| `payslip.published` | Period approved by an admin | payslip |
| `payroll.paid` | Period marked paid | payslip |

Suggested messages:

- `payslip.published`: "Hi {employee_name}, your payslip for {period} is ready: {payslip_url}"
- `payroll.paid`: "Hi {employee_name}, your salary for {period} (Rp {net_pay}) has been paid."

## Acknowledging events

```
POST /api/v1/integrations/outbox/ack
Content-Type: application/json

{ "ids": [41, 42, 43] }
```

```json
{ "data": { "acked": [41, 42], "already_acked": [43], "unknown": [] } }
```

- Up to 100 ids per call.
- Acking is idempotent: acking twice is harmless and is reported in `already_acked`.
- Acked events no longer appear in `GET /outbox`.

## Delivery semantics

Delivery is **at least once**. If the consumer sends a message and crashes before acking,
the event is returned again on the next poll. Consumers must therefore de-duplicate on the
event `id`, for example by recording every id in a small table before sending. A single
"last processed id" is not enough: failed events stay behind it and are retried later.

## Recommended polling loop (n8n)

1. **Schedule Trigger** every 5 minutes.
2. **HTTP Request** `GET /api/v1/integrations/outbox?after=0&limit=50` with header `X-Api-Key`
   from n8n credentials. While a page comes back full, request the next one with
   `after={meta.next_after}`.
3. **Split Out** `data`.
4. **Switch** on `type` and send the message (WhatsApp template message).
5. **Aggregate** the ids of the messages that were sent successfully.
6. **HTTP Request** `POST /api/v1/integrations/outbox/ack` with `{ "ids": [...] }`.

Only ack after the message was handed to the provider. Failed sends stay unacked and are
retried on the next run.

Start every run from `after=0` rather than storing a cursor between runs. Unacked events
from earlier runs then come back automatically, and an event id is never skipped: ids are
assigned when a row is inserted, so a transaction that commits later can still make a
lower id visible after a higher one was already read.

## Error responses

All errors are RFC 7807 problem documents (`application/problem+json`) with a stable `code`:

| Status | code | Meaning |
| --- | --- | --- |
| 401 | `invalid_api_key` | Missing, malformed or unknown key |
| 403 | `insufficient_scope` | Key lacks `outbox:read` |
| 422 | `validation_failed` | Bad `after`, `limit` or `ids` |
