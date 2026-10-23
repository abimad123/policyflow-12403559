# PolicyFlow

Fault-tolerant insurance issuance and reconciliation engine for the Trippers Shield engineering assignment.

## Status

Stages 2–4 implement intake, premium snapshots, deterministic mock issuance, callbacks, keyed retries, reconciliation, and an audit trail. This is a local assessment system with no real insurer connection.

## Local setup

1. Install PHP 8.2+ with `pdo_pgsql`, Composer 2, and PostgreSQL 16. Create local `policyflow` and `policyflow_test` databases, both accessible to your local role. This development setup publishes the Docker database on host port 5433.
2. Run `composer install`.
3. Copy `.env.example` to `.env`. Set `DB_USERNAME` and `DB_PASSWORD` to your local values, and adjust `DB_HOST` and `DB_PORT` if your PostgreSQL mapping differs. Keep `.env` private.
4. Run `php artisan key:generate`.
5. Check the connection with `php artisan tinker --execute="dump(DB::select('SELECT current_database() AS database, current_user AS role'))"`. This query does not modify the database.
6. Run `php artisan migrate` for the development database.
7. Run `php artisan test` and `php artisan serve`. PHPUnit forces PostgreSQL database `policyflow_test`; tests must never run against the development database. The test database must exist before testing, and the role must be able to create tables there.

For a fresh PostgreSQL instance, open `psql` as an administrator and run the following, skipping any role or database that already exists. `\password` prompts for a local password without putting it in shell history.

```sql
CREATE ROLE policyflow LOGIN CREATEDB;
\password policyflow
CREATE DATABASE policyflow OWNER policyflow;
CREATE DATABASE policyflow_test OWNER policyflow;
```

File sessions, file cache, and synchronous jobs keep this stage independent of queue and cache tables.

## Intake API

`POST /api/policy-requests` accepts JSON. All fields below are required. Plans are `BASIC`, `PLUS`, or `PREMIUM`; destinations are `DOMESTIC` or `INTERNATIONAL`; and `sum_insured` is one of `100000`, `300000`, or `500000`. Dates use `YYYY-MM-DD`; birth must precede trip start, and trip end must not precede trip start.

```json
{
  "client_request_id": "AGT-2026-0001",
  "agent_id": "AGENT-17",
  "customer_name": "Sample Customer",
  "date_of_birth": "1998-04-10",
  "destination": "INTERNATIONAL",
  "trip_start": "2026-11-10",
  "trip_end": "2026-11-14",
  "plan_code": "PLUS",
  "sum_insured": 300000
}
```

For example, save that JSON as `request.json` and run `curl --json @request.json http://127.0.0.1:8000/api/policy-requests` after starting the server.

A new request returns HTTP `201` and the stored snapshot. This example has five inclusive travel days and a premium of ₹225.00. IDs and timestamps will vary.

```json
{
  "data": {
    "id": 1,
    "agent_id": "AGENT-17",
    "client_request_id": "AGT-2026-0001",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000,
    "premium_amount": "225.00",
    "status": "VALIDATED",
    "provider_reference": null,
    "last_provider_response": null,
    "submission_attempt_count": 0,
    "policy_number": null,
    "created_at": "2026-10-23T12:00:00.000000Z",
    "updated_at": "2026-10-23T12:00:00.000000Z"
  }
}
```

Submitting the same `agent_id` and `client_request_id` again returns HTTP `200` with the same persisted request record and original premium, even if other valid fields differ. Mutable fields such as status and provider details reflect any subsequent processing. Validation errors return HTTP `422` as `{"message":"...","errors":{"field":["..."]}}`.

## Insurer submission API

`POST /api/policy-requests/{id}/submit` accepts a `VALIDATED` request and defaults to the local mock's `SUCCESS` scenario. In local and testing environments, the optional `scenario` field accepts `SUCCESS`, `TEMPORARY_FAILURE`, `TIMEOUT_AFTER_ISSUE`, `DELAYED_CALLBACK`, or `DUPLICATE_CALLBACK`. A new successful submission returns HTTP `200` with `ISSUED`, a provider reference, and a unique policy number. A temporary failure returns HTTP `200` with `FAILED` and no policy number. A timeout after internal issuance returns `NEEDS_RECONCILIATION`; delayed and duplicate callback scenarios return `PENDING_CONFIRMATION`. The mock does not sleep or dispatch callbacks automatically. A missing ID returns `404`, a non-submittable state returns `409`, and an unsupported scenario returns `422`.

The response envelope is always `{"data":{...}}` for a processed submission. `data` contains every persisted intake field and timestamp, plus `provider_reference`, `last_provider_response`, `submission_attempt_count`, `policy_number`, `state_history`, `issuance_attempts`, and `provider_events`. The history entries contain `previous_state`, `new_state`, `context`, and `occurred_at`; attempt entries contain `attempt_number`, `retry_key`, `scenario`, `result`, `provider_reference`, `response`, and timestamps. The following examples show selected fields; IDs and timestamps vary.

```json
{
  "data": {
    "id": 1,
    "status": "ISSUED",
    "premium_amount": "225.00",
    "provider_reference": "MOCK-REF-0000000001",
    "policy_number": "PF-0000000001",
    "submission_attempt_count": 1,
    "last_provider_response": {
      "outcome": "SUCCESS",
      "provider_reference": "MOCK-REF-0000000001",
      "policy_number": "PF-0000000001"
    }
  }
}
```

For `TEMPORARY_FAILURE`, the selected fields are `"status":"FAILED"`, `"policy_number":null`, `"submission_attempt_count":1`, and `"last_provider_response":{"outcome":"TEMPORARY_FAILURE","error_code":"MOCK_TEMPORARILY_UNAVAILABLE"}`. Repeating a submit on an `ISSUED` request returns the current issued record with no new attempt. A failed submission is retried through the separate retry endpoint.

### State rules and mock scope

Intake creates `VALIDATED`. Submission transitions `VALIDATED → SUBMITTED → ISSUED` for `SUCCESS`, `→ FAILED` for `TEMPORARY_FAILURE`, `→ NEEDS_RECONCILIATION` for `TIMEOUT_AFTER_ISSUE`, and `→ PENDING_CONFIRMATION` for delayed/duplicate callbacks. A keyed retry transitions `FAILED → SUBMITTED` and then to the mock outcome. A matching callback or successful status lookup transitions `PENDING_CONFIRMATION` or `NEEDS_RECONCILIATION → ISSUED`. `ISSUED` is terminal.

The mock is an in-process function with no network call or sleep. Submission locks the request row with PostgreSQL `FOR UPDATE` inside a transaction. The mock stores at most one internal provider policy per request, with unique provider reference and policy number constraints. A timeout leaves the request uncertain even though the mock internally issued; submitting again is blocked until reconciliation. Repeated callbacks with the same event ID or same confirmed policy do not apply another state transition. The test suite verifies deterministic duplicate behavior but does not run simultaneous HTTP requests. A real external insurer would require an idempotency key and a durable outbox or equivalent claim before network calls.

## Retry, callback, and reconciliation APIs

After a temporary failure, `POST /api/policy-requests/{id}/retry` requires a caller-chosen `retry_key` (up to 100 characters). Repeating a key returns the current request without another attempt. A new key makes a new attempt only from `FAILED`. The optional local/testing `scenario` flag has the same values as submit. For example:

```json
{"retry_key":"agent-17-retry-1","scenario":"SUCCESS"}
```

The response is `{"data":{...}}` with HTTP `200`; `409` means the state cannot be retried, and `422` means invalid input. An already issued request returns its current record without a new attempt. Keep a retry key stable when resending the same HTTP operation.

For `DELAYED_CALLBACK` or `DUPLICATE_CALLBACK`, first submit the request, then call `POST /api/mock-insurer/policy-requests/{id}/dispatch-callbacks` in local/testing. This dispatches one event for `DELAYED_CALLBACK` and the same event three times for `DUPLICATE_CALLBACK`; repeating dispatch replays the same event ID safely. The response includes `data` and a `deliveries` array with each delivery's `duplicate` flag. `GET /api/mock-insurer/status/{provider_reference}` exposes the mock's internal policy in local/testing for inspection.

To exercise callback validation directly, retrieve the mock policy number from that status endpoint and send an event to `POST /api/mock-insurer/callback`:

```json
{
  "event_id": "mock-event-1",
  "provider_reference": "MOCK-REF-0000000001",
  "event_type": "ISSUED",
  "policy_number": "PF-0000000001"
}
```

The callback verifies the reference and policy number against the mock's internal record. It returns HTTP `200` with `{"data":{...},"event_id":"mock-event-1","duplicate":false}`. Repeating the event or sending another matching issued event returns `duplicate:true` and does not issue again. Invalid fields return `422`, unknown references return `404`, and conflicting events or states return `409`. The callback route is an unauthenticated **local mock** endpoint; it needs provider authentication before any real integration.

Call `POST /api/policy-requests/{id}/reconcile` to query the local provider record after a timeout or pending response. A confirmed policy returns HTTP `200` with `{"data":{...},"resolved":true}` and transitions to `ISSUED`. If the mock lookup is unavailable or returns no record, HTTP `202` returns `resolved:false` and preserves the uncertain state; it never assumes a missing answer means failure. Invalid states return `409`. Repeating reconciliation after issuance returns the existing record.

`GET /api/policy-requests/{id}` returns `{"data":{...}}` with current state, premium snapshot, attempts, state history, and provider events. `GET /api/policy-requests?status=NEEDS_RECONCILIATION&per_page=15&page=1` returns `{"data":[...],"pagination":{"current_page":1,"last_page":1,"per_page":15,"total":1}}`; status is optional and page size is limited to 1–100. These read routes return `404` for a missing ID and `422` for invalid filters.

## Architecture direction

The API controller delegates validation to a Form Request and intake to a service. A separate calculator uses integer paise and scaled integer multipliers, then stores a two-decimal premium snapshot. Intake first looks up the `(agent_id, client_request_id)` pair; Laravel's `firstOrCreate` catches a database unique-key race and reads the winning row. The composite database constraint is the final safeguard. PostgreSQL's normal committed-read behavior lets a competing insert wait for the winner, then return its row. Submission has a separate service, state machine, and mock insurer. PostgreSQL JSONB stores mock responses and non-sensitive audit context; relational tables store attempt and transition history. The nullable `policy_number` has a database unique constraint.

## Assumptions and limitations

The provided insurer is fictional; no real insurer or payment service is used. Duplicate intake submissions are identified by agent and client request ID; other valid fields on a duplicate are ignored and the original premium snapshot is returned. The local mock stores its provider state in the same PostgreSQL database as the application, so it cannot model an independent provider outage or cross-system commit boundary. Callbacks require explicit local dispatch; there is no queue, scheduler, automatic retry, or background reconciliation worker. Reconciliation is an explicit API operation. The local Windows PostgreSQL service occupies port 5432, so the Docker container is published on port 5433.
