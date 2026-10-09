# Architecture

Layering, the payroll approval sequence, database constraints and the smaller design decisions.
The overview is in the [README](../README.md#architecture).

## Layers

- **Controllers** are thin: they read and validate input (`Http\Input`), call one service method
  and shape the response.
- **Services** own the rules and transaction boundaries. Authorisation lives here, not only in
  routes, so every path to the data is checked the same way.
- **Repositories** contain all SQL. Values are always bound parameters; sort columns come from
  per-repository whitelists.
- **Domain\Payroll** is the calculator: plain PHP objects, no database, no clock. It is the most
  heavily tested part.
- **Container** wires everything by hand in one file. There is no autowiring to reason about.

## Payroll approval, step by step

```mermaid
sequenceDiagram
    participant A as Admin
    participant API as PayrollService
    participant DB as MySQL
    participant N as n8n
    A->>API: POST /payroll-periods/{id}/approve
    API->>DB: BEGIN; SELECT period FOR UPDATE
    API->>API: status = calculated? approver != calculator?
    API->>DB: UPDATE period SET status = approved
    API->>DB: INSERT audit_logs
    API->>DB: INSERT outbox_events (one per payslip)
    API->>DB: COMMIT
    N->>API: GET /integrations/outbox
    N->>N: send WhatsApp / email
    N->>API: POST /integrations/outbox/ack
```

The events are consumed as described in [integrations.md](integrations.md).

## Database constraints

Reference tables not shown in the README diagram: `holidays`, `bpjs_settings` (append-only, with
`effective_from`), `login_attempts`, `api_clients`. Constraints worth noting: unique
`(employee_id, work_date)` on attendance, unique `(payroll_period_id, employee_id)` on payslips,
a CHECK that `net_pay = gross_pay - total_deductions`, a CHECK that the approver differs from the
calculator, and a CHECK limiting overtime to 12 hours per request.

Payslips store a snapshot of the employee's name, number and department so an approved payslip
does not change when master data changes later.

## Further design decisions

- **CSV import: validate all, insert valid rows in one transaction.** Users get every row error
  in one response and can fix and re-upload only the failed rows. Because the valid rows are
  inserted atomically, an unexpected failure leaves nothing half-imported; the whole file can be
  sent again. Duplicate rows within the file and rows that already exist are reported, not
  overwritten.
- **Manual wiring and plain SQL.** A small hand-written container and SQL files instead of a DI
  framework and an ORM. More typing, but nothing is hidden.
