# PolicyFlow

Fault-tolerant insurance issuance and reconciliation engine for the Trippers Shield engineering assignment.

## Status

Stages 2–4 implement intake, premium snapshots, deterministic mock issuance, callbacks, keyed retries, reconciliation, and an audit trail. A Blade and Tailwind interface demonstrates these APIs locally or in an explicitly enabled public evaluator demo. There is no real insurer or payment connection.

## Local setup

1. Install PHP 8.2+ with `pdo_pgsql`, Composer 2, and PostgreSQL 16. Create local `policyflow` and `policyflow_test` databases, both accessible to your local role. This development setup publishes the Docker database on host port 5433.
2. Run `composer install`.
3. Copy `.env.example` to `.env`. Set `DB_USERNAME` and `DB_PASSWORD` to your local values, and adjust `DB_HOST` and `DB_PORT` if your PostgreSQL mapping differs. Keep `.env` private.
4. Run `php artisan key:generate`.
5. Check the connection with `php artisan tinker --execute="dump(DB::select('SELECT current_database() AS database, current_user AS role'))"`. This query does not modify the database.
6. Run `php artisan migrate` for the development database.
7. Run `npm install` and `npm run build` (or `npm run dev` in a second terminal). The generated `package-lock.json` makes subsequent installs repeatable with `npm ci`.
8. Run `php artisan test` and `php artisan serve`. PHPUnit forces PostgreSQL database `policyflow_test`; tests must never run against the development database. The test database must exist before testing, and the role must be able to create tables there.

### Local web demo

With `APP_ENV=local`, open `http://127.0.0.1:8000/`. The dashboard lists the newest policies first with status filtering, pagination, and refresh. Select **New request** to create a policy. The form shows HTTP status, field validation errors, and the API response; a successful or idempotently reused request provides a link to its detail page. The detail page shows the premium snapshot, state history, insurer attempts, provider events, and complete JSON responses. Controls are enabled according to the current state. All actions call the existing `/api` endpoints; the pages do not calculate premiums or issue policies themselves.

For a repeatable walkthrough, create a request with a fresh `client_request_id` for an agent, then open it:

1. **Success:** leave `SUCCESS` selected and choose **Submit**. The request becomes `ISSUED`; submit/retry/reconcile are disabled. Refresh to verify persisted state.
2. **Temporary failure and retry:** create another request, select `TEMPORARY_FAILURE`, and submit. The request becomes `FAILED`. Select `SUCCESS` and choose **Retry**. The displayed retry key is reused for the same retry operation; use **New key** only for a deliberate new attempt. The request becomes `ISSUED` with two attempts and an unchanged premium.
3. **Timeout and reconciliation:** create another request, select `TIMEOUT_AFTER_ISSUE`, and submit. The request becomes `NEEDS_RECONCILIATION` without assuming failure. Choose **Reconcile** to confirm the mock provider record and reach `ISSUED` without resubmission.
4. **Duplicate callbacks:** create another request, select `DUPLICATE_CALLBACK`, and submit. The request becomes `PENDING_CONFIRMATION`. Choose **Dispatch callback**; three deliveries share one event ID and only the first applies issuance. Choose **Dispatch callback** again to replay the event and inspect the duplicate flags and provider events. `DELAYED_CALLBACK` follows the same path with one delivery per dispatch. A request submitted with `SUCCESS` has no queued callback, so dispatch is disabled; calling that API directly returns a meaningful `409`.

The homepage is public in production. The creation/detail pages and synthetic demo records require `POLICYFLOW_PUBLIC_DEMO=true` with `APP_DEBUG=false`. Production write APIs are available only when public demo mode is explicitly enabled; they accept synthetic data and are rate limited. Local/testing behavior remains unchanged. The mock is never a real insurer integration.

## Render Docker deployment preparation

The root [`Dockerfile`](Dockerfile) builds locked npm assets and locked Composer production dependencies, then serves Laravel through Apache with `public/` as the document root. [`docker/start.sh`](docker/start.sh) binds Apache to Render's runtime `PORT` (default `10000`) and refuses to start without `APP_KEY` or with production `APP_DEBUG` enabled. The image contains no `.env`, local logs, test data, `node_modules`, or development `vendor` directory; runtime settings and secrets come from Render environment variables. Laravel and Apache write logs to standard error/output. The file system is ephemeral, so the default file session/cache stores are suitable only for a single instance and are lost on restart.

Production Vite links use same-origin `/build/...` paths, so assets follow the browser's HTTPS origin even if Render's proxy reports HTTP to PHP. The live site previously emitted `http://.../build/...` links; the CSS and JS files themselves were available over HTTPS. Set both `APP_URL` and `ASSET_URL` to the final HTTPS origin as well. A page stuck on “Loading requests…” usually means its JavaScript was blocked; the rebuilt UI also shows a retryable error if the API or response format fails.

These are the **Render settings to use after reviewing and publishing this commit**. No Render service or DNS record is created by this repository change:

1. Confirm Render is connected to the intended GitHub repository; verify `git remote -v` before connecting it. Create a **dedicated, empty demo PostgreSQL database** containing synthetic records only, and keep its internal connection URL private. Never point the public demo at a development or customer database.
2. Create a **Web Service** with **Docker** as its runtime. Leave Root Directory blank (repository root); set Dockerfile Path to `./Dockerfile`. Docker services have no separate Build Command. Leave Docker Command blank so the image's `ENTRYPOINT` and `CMD` start Apache. Set Health Check Path to `/up`. Render supplies `PORT`; the image defaults to `10000` if it is absent.
3. Add environment variables in Render's Environment settings, never in source or Docker build arguments:

   | Key | Value |
   | --- | --- |
   | `APP_ENV` | `production` |
   | `APP_DEBUG` | `false` |
   | `POLICYFLOW_PUBLIC_DEMO` | `true` only after the isolated demo database and schema are ready; defaults to `false`. The homepage works while this is `false`, but records are hidden. |
   | `APP_KEY` | One stable key generated locally with `php artisan key:generate --show`; paste it into Render's secret environment setting and do not rotate it casually. |
   | `APP_URL` | The final `https://<service>.onrender.com` URL, then the custom HTTPS URL after domain setup. |
   | `ASSET_URL` | The same HTTPS origin as `APP_URL`, to keep Vite asset links secure behind Render's TLS proxy. |
   | `DB_CONNECTION` | `pgsql` |
   | `DB_URL` | The Render PostgreSQL **internal** URL. This app's `config/database.php` reads `DB_URL`, not `DATABASE_URL`. |
   | `LOG_CHANNEL` | `stderr` |
   | `LOG_LEVEL` | `warning` (or the required operational level). |

   The image defaults to synchronous jobs and file session/cache storage. Keep `APP_KEY` and `DB_URL` stable across deploys. Do not set `APP_ENV=local` on a publicly reachable service merely to expose mock controls.

4. Leave Render's **Pre-Deploy Command blank**. The Docker entrypoint does not run migrations. Provision the required schema in a separate, isolated preparation environment before connecting the production web service; verify the schema and data are synthetic before setting `POLICYFLOW_PUBLIC_DEMO=true`. Never run `php artisan migrate` or `migrate:fresh` against the production database.
5. After deployment is separately approved, check `/up`, `/`, and `GET /api/policy-requests` in that order. `/up` proves HTTP readiness, not PostgreSQL connectivity. A `500` from the list means the Render database connection or schema still needs attention. Use `php artisan migrate:status` in a Render shell as a read-only schema check; do not run migrations in production. Resolve database connectivity before enabling the demo or accepting public writes.

   If the list returns HTTP `200` with `demo_enabled:false` and an empty `data` array, that is the intentional read-only state while `POLICYFLOW_PUBLIC_DEMO=false`; it does not verify PostgreSQL. After enabling the demo, repeat the list request and check Render's application logs plus `php artisan migrate:status` for connection/schema failures. Do not print `DB_URL` or other secret values in logs or support requests.

### Public evaluator demo

The homepage `/` and **New policy request** form at `/policies/new` are always visible in production. When the demo opt-in is off, its list is empty, the form is a preview with submission disabled, and all writes return `403`. With the opt-in on, anyone may browse only synthetic `DEMO-<UUID>` / `DEMO-AGENT` / `Demo Traveler` records and their details. Existing non-demo records are hidden from public list and detail APIs. The form generates those values and invented trip dates; production intake rejects other identity values with HTTP `422`. Keep the entire demo database synthetic.

Public demo writes require no token. They are limited to synthetic identities, mock insurer operations, and enabled demo mode. Requests are rate limited; oversized or malformed JSON is rejected. Never enter real customer data.

For the live evaluator demo, set `POLICYFLOW_PUBLIC_DEMO=true`, `APP_ENV=production`, and `APP_DEBUG=false` in Render after verifying the isolated database. After deployment, open the form and create a synthetic request, and follow its detail link to inspect state history and attempts. The server-side demo-mode and synthetic-record middleware still control every write.

To demonstrate reliability, create a fresh request for each scenario. Select `SUCCESS` for immediate issuance; `TEMPORARY_FAILURE` then `SUCCESS` on **Retry** for recovery; `TIMEOUT_AFTER_ISSUE` then **Reconcile** for an uncertain outcome; and `DELAYED_CALLBACK` or `DUPLICATE_CALLBACK` then **Dispatch callback** for callback delivery. Dispatch a second time to see an idempotent duplicate. The mock status lookup is available in the enabled demo; direct `POST /api/mock-insurer/callback` is local/testing only. A dispatch without a queued callback returns HTTP `409`, including after `SUCCESS`; this is an expected conflict.

Production limits requests per source IP to 120 reads, 20 writes, and 8 intake requests per minute; excess traffic receives HTTP `429`. These counters use Laravel's configured cache. The image defaults to file cache, so limits are per instance and reset on restart; use a shared cache and an upstream edge limit if deploying multiple instances. Evaluators can see each other's synthetic records; any visitor can change them while demo mode is enabled. The mock callback is limited to enabled demo mode and synthetic records and must never be used for a real provider. This is an assessment demo, not a production insurance system. Live Render database connectivity, schema setup, and any custom domain remain to be verified before launch.

## Local PostgreSQL bootstrap and API checks

For a fresh PostgreSQL instance, open `psql` as an administrator and run the following, skipping any role or database that already exists. `\password` prompts for a local password without putting it in shell history.

```sql
CREATE ROLE policyflow LOGIN CREATEDB;
\password policyflow
CREATE DATABASE policyflow OWNER policyflow;
CREATE DATABASE policyflow_test OWNER policyflow;
```

File sessions, file cache, and synchronous jobs keep this stage independent of queue and cache tables.

### Repeatable Postman check

Import `examples/PolicyFlow.postman_collection.json` and run its four folders in order against `http://127.0.0.1:8000/api`. Each folder's create request generates a new fabricated `client_request_id`, stores the returned policy ID, and checks its subsequent calls. It leaves those new rows in the development database. Run a folder from its first request; later requests reuse the ID and key saved by that folder. The collection explicitly tests that `POST /policy-requests` returns one object under `data`, while `GET /policy-requests` returns an array under `data` with pagination metadata. A `409` from callback dispatch on a policy issued by the `SUCCESS` scenario is expected: only `DELAYED_CALLBACK` and `DUPLICATE_CALLBACK` queue a callback. Repeating dispatch for a policy issued through a queued callback returns `200` with duplicate deliveries.

If Postman appears to show an older list after a `201` response, check the active request tab and its method and URL. The create response contains a single `data` object; the list response contains a `data` array. A repeated `(agent_id, client_request_id)` returns HTTP `200` with the original request. Clear any `status`, `page`, or `per_page` query parameters when checking the full list; the default page contains at most 15 rows. The collection's tests assert these distinctions after every fresh create.

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

`POST /api/policy-requests/{id}/submit` accepts a `VALIDATED` request and defaults to the mock's `SUCCESS` scenario. In local/testing and the enabled, public demo, the optional `scenario` field accepts `SUCCESS`, `TEMPORARY_FAILURE`, `TIMEOUT_AFTER_ISSUE`, `DELAYED_CALLBACK`, or `DUPLICATE_CALLBACK`. A new successful submission returns HTTP `200` with `ISSUED`, a provider reference, and a unique policy number. A temporary failure returns HTTP `200` with `FAILED` and no policy number. A timeout after internal issuance returns `NEEDS_RECONCILIATION`; delayed and duplicate callback scenarios return `PENDING_CONFIRMATION`. The mock does not sleep or dispatch callbacks automatically. A missing ID returns `404`, a non-submittable state returns `409`, and an unsupported scenario returns `422`.

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

After a temporary failure, `POST /api/policy-requests/{id}/retry` requires a caller-chosen `retry_key` (up to 100 characters). Repeating a key returns the current request without another attempt. A new key makes a new attempt only from `FAILED`. The optional `scenario` flag is available in local/testing or the enabled public demo and has the same values as submit. For example:

```json
{"retry_key":"agent-17-retry-1","scenario":"SUCCESS"}
```

The response is `{"data":{...}}` with HTTP `200`; `409` means the state cannot be retried, and `422` means invalid input. An already issued request returns its current record without a new attempt. Keep a retry key stable when resending the same HTTP operation.

For `DELAYED_CALLBACK` or `DUPLICATE_CALLBACK`, first submit the request, then call `POST /api/mock-insurer/policy-requests/{id}/dispatch-callbacks` in local/testing or the enabled public demo. This dispatches one event for `DELAYED_CALLBACK` and the same event three times for `DUPLICATE_CALLBACK`; repeating dispatch replays the same event ID safely. The response includes `data` and a `deliveries` array with each delivery's `duplicate` flag. `GET /api/mock-insurer/status/{provider_reference}` exposes the mock's internal policy in those same demo environments for inspection.

To exercise callback validation directly, retrieve the mock policy number from that status endpoint and send an event to `POST /api/mock-insurer/callback`:

```json
{
  "event_id": "mock-event-1",
  "provider_reference": "MOCK-REF-0000000001",
  "event_type": "ISSUED",
  "policy_number": "PF-0000000001"
}
```

The callback verifies the reference and policy number against the mock's internal record. It returns HTTP `200` with `{"data":{...},"event_id":"mock-event-1","duplicate":false}`. Repeating the event or sending another matching issued event returns `duplicate:true` and does not issue again. Invalid fields return `422`, unknown references return `404`, and conflicting events or states return `409`. The direct callback endpoint is local/testing only; public evaluators use callback dispatch in the enabled demo. A real provider integration would require provider-specific authentication.

Call `POST /api/policy-requests/{id}/reconcile` to query the local provider record after a timeout or pending response. A confirmed policy returns HTTP `200` with `{"data":{...},"resolved":true}` and transitions to `ISSUED`. If the mock lookup is unavailable or returns no record, HTTP `202` returns `resolved:false` and preserves the uncertain state; it never assumes a missing answer means failure. Invalid states return `409`. Repeating reconciliation after issuance returns the existing record.

`GET /api/policy-requests/{id}` returns `{"data":{...}}` with current state, premium snapshot, attempts, state history, and provider events. `GET /api/policy-requests?status=NEEDS_RECONCILIATION&per_page=15&page=1` returns `{"data":[...],"pagination":{"current_page":1,"last_page":1,"per_page":15,"total":1}}`; status is optional and page size is limited to 1–100. These read routes return `404` for a missing ID and `422` for invalid filters.

## Architecture direction

### Implemented reliability cases

All four mandatory reliability examples are implemented: (1) duplicate intake uses the `(agent_id, client_request_id)` database key and returns the original request; (2) timeout after internal issuance stays uncertain until provider lookup or a matching callback confirms it; (3) duplicate callbacks share a unique provider event ID and apply issuance once; and (4) temporary failures use keyed retries while preserving the premium snapshot. `PolicyRequestIntakeTest`, `PolicySubmissionTest`, and `PolicyReliabilityTest` demonstrate these cases against PostgreSQL. The suite checks deterministic duplicates and database constraints, but it does not run simultaneous HTTP requests or model an independent insurer database.

The API controller delegates validation to a Form Request and intake to a service. A separate calculator uses integer paise and scaled integer multipliers, then stores a two-decimal premium snapshot. Intake first looks up the `(agent_id, client_request_id)` pair; Laravel's `firstOrCreate` catches a database unique-key race and reads the winning row. The composite database constraint is the final safeguard. PostgreSQL's normal committed-read behavior lets a competing insert wait for the winner, then return its row. Submission has a separate service, state machine, and mock insurer. PostgreSQL JSONB stores mock responses and non-sensitive audit context; relational tables store attempt and transition history. The nullable `policy_number` has a database unique constraint.

## Assumptions and limitations

The provided insurer is fictional; no real insurer or payment service is used. Duplicate intake submissions are identified by agent and client request ID; other valid fields on a duplicate are ignored and the original premium snapshot is returned. The local mock stores its provider state in the same PostgreSQL database as the application, so it cannot model an independent provider outage or cross-system commit boundary. Callbacks require explicit demo dispatch; there is no queue, scheduler, automatic retry, or background reconciliation worker. Reconciliation is an explicit API operation. The local Windows PostgreSQL service occupies port 5432, so the Docker container is published on port 5433.
