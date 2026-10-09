# Operations reference

Seeded accounts and environment variables. Setup steps are in the
[README](../README.md#getting-started).

## Seeded users

All seeded users use the password `Password123!`:

| Email | Role | Notes |
| --- | --- | --- |
| `admin@example.test`, `admin2@example.test` | admin | two admins to show segregation of duties |
| `hr@example.test` | hr | |
| `dina.kartika@example.test` | manager | manages Budi and Sari |
| `raka.pratama@example.test` | manager | manages Nina |
| `budi.santoso@example.test`, `sari.wulandari@example.test`, `nina.lestari@example.test` | employee | |

`php bin/seed` also prints an outbox API key once; store it if you want to try the
[integration endpoints](integrations.md).

## Environment variables

| Variable | Purpose |
| --- | --- |
| `APP_ENV`, `APP_DEBUG` | `bin/seed` and `bin/migrate --fresh` refuse to run when `APP_ENV=production`. `APP_DEBUG=true` adds exception class and message to 500 responses (never the trace) |
| `APP_URL` | Base URL used in `payslip_url` of outbox events |
| `APP_TIMEZONE` | Company time zone (default `Asia/Jakarta`) that decides the work date and clock times of clock-in/out. `work_date` and clock times are stored as local values; timestamps such as `created_at` are UTC |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Database connection |
| `JWT_SECRET`, `JWT_ISSUER` | HS256 signing key (32+ characters) and issuer claim |
| `ACCESS_TOKEN_TTL`, `REFRESH_TOKEN_TTL` | Seconds; defaults 900 and 2592000 |
| `LOGIN_MAX_ATTEMPTS`, `LOGIN_WINDOW_SECONDS` | Failed logins allowed per email + IP per window |
| `LOG_LEVEL` | Monolog level for the JSON logs on stdout |

`bin/migrate --fresh` drops and recreates the schema. It and `bin/seed` refuse to run when
`APP_ENV=production`.
