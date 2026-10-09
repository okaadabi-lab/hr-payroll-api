# API overview

The full contract is [openapi.yaml](openapi.yaml) (OpenAPI 3.1). A test fails if a route is
registered in Slim but missing from the spec, or the other way round. The outbox endpoints are
described in [integrations.md](integrations.md).

## Endpoints

| Area | Endpoints |
| --- | --- |
| Auth | `POST /auth/login`, `POST /auth/refresh`, `POST /auth/logout`, `GET /me` |
| Master data | `/departments`, `/employees`, `POST /users`, `/holidays`, `/bpjs-settings` |
| Attendance | `/attendance`, `POST /attendance/import`, `POST /attendance/clock-in`, `POST /attendance/clock-out` |
| Overtime | `/overtime-requests`, `POST /overtime-requests/{id}/approve`, `.../reject` |
| Payroll | `/payroll-periods`, `POST .../{id}/calculate`, `.../approve`, `.../mark-paid`, `GET .../{id}/payslips` |
| Payslips | `GET /payslips`, `GET /payslips/{id}` |
| Integrations | `GET /integrations/outbox`, `POST /integrations/outbox/ack` (API key) |
| Ops | `GET /health`, `GET /audit-logs` |

All paths except `/health` are under `/api/v1`.

## Example session

```bash
TOKEN=$(curl -s -X POST localhost:8082/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"hr@example.test","password":"Password123!"}' | jq -r .access_token)

curl -s -X POST localhost:8082/api/v1/payroll-periods -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"period":"2026-08"}'

curl -s -X POST localhost:8082/api/v1/payroll-periods/1/calculate \
  -H "Authorization: Bearer $TOKEN" -H 'Idempotency-Key: calc-2026-08-001'
```

## Errors

Errors are RFC 7807 problem documents with a stable `code` and the request id:

```json
{
  "type": "urn:hr-payroll:problem:validation_failed",
  "title": "Unprocessable Content",
  "status": 422,
  "detail": "Overtime exceeds the limits of PP 35/2021.",
  "instance": "/api/v1/overtime-requests",
  "code": "validation_failed",
  "request_id": "6f1c0d0e9a3b4f52b8f1e0c7d2a4b6e8",
  "errors": [
    { "field": "hours", "message": "Overtime on a workday is limited to 4 hours per day; 0 hours already requested." }
  ]
}
```
