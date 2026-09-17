# Calendar Service

A proof-of-concept Laravel 12 app that proves Calendly-equivalent booking is achievable
directly on top of a tenant's own **Google Calendar** — a booking widget, an API a
DirectConnect-style consumer could call, and outbound webhooks — designed so
**Microsoft Office 365 (Graph)** slots in later as a second provider without
restructuring.

Full design and rationale: [`docs/superpowers/specs/2026-09-09-google-calendar-booking-poc-design.md`](docs/superpowers/specs/2026-09-09-google-calendar-booking-poc-design.md).

This is a POC: no auth on the app or its API, a single seeded tenant, and the caveats
in [Known Caveats](#known-caveats) below.

## Prerequisites

- PHP 8.2+ with [Laravel Herd](https://herd.laravel.com/)
- MySQL (local, e.g. via Herd)
- Node 22+ (the demo widget's build tooling requires it — this machine's default
  `node` is v12, so run `nvm use 22.14.0` before any `npm` command)
- A Google Cloud project, if you want to exercise real Google OAuth (optional — see
  [Demo without Google](#demo-without-google) to skip this)

## Setup

```bash
composer install
cp .env.example .env   # if .env doesn't already exist
php artisan key:generate
```

Create the local database (matching `.env`'s `DB_DATABASE=calendar_service`), then:

```bash
php artisan migrate --seed
```

The seeder creates a demo tenant (Mon–Fri, 09:00–17:00, 30-minute meetings) and a
webhook-sink endpoint used by the `/demo` webhook inspector.

```bash
herd link
herd secure
```

This serves the app at `https://calendar-service.test`.

```bash
nvm use 22.14.0
npm install
npm run build
```

## Google Cloud setup

To connect a real Google Calendar (rather than running in mock mode):

1. Create a project in the [Google Cloud Console](https://console.cloud.google.com/).
2. **Enable the Google Calendar API** for that project (APIs & Services → Library).
3. Configure the **OAuth consent screen**: User Type **External**, publishing status
   **Testing**. Add the Google account of the tenant you'll connect with as a
   **Test user** (Testing-status apps only allow test users to complete the OAuth
   flow).
4. Create an **OAuth client ID** of type **Web application**, with an authorized
   redirect URI of:

   ```
   https://calendar-service.test/setup/google/callback
   ```

5. Copy the generated Client ID and Client Secret into `.env`:

   ```
   GOOGLE_CLIENT_ID=...
   GOOGLE_CLIENT_SECRET=...
   GOOGLE_REDIRECT_URI=https://calendar-service.test/setup/google/callback
   ```

6. Visit `/setup` and click **Connect Google**.

**Caveat:** while the OAuth consent screen is in **Testing** status, Google expires
refresh tokens after **7 days** — you'll need to re-auth weekly. `/setup` shows a
warning about this, and flags the integration for re-auth if a refresh attempt fails.

## Demo without Google

Set `CALENDAR_GATEWAY=mock` in `.env` to run the whole booking flow (availability,
create, delete/exists probes) against an in-memory fake gateway instead of real Google
API calls — no Google Cloud project needed. This is the fastest way to exercise the
app end-to-end.

## Running

Two background processes are required alongside `php artisan serve` (or Herd, which
serves the app automatically) for webhooks, reminders, and reconciliation to work:

```bash
php artisan queue:work
```

Runs the `database` queue: webhook deliveries (`SendWebhookJob`) and outbound
notification sends are queued jobs.

```bash
php artisan schedule:work
```

Runs the scheduler locally, which drives:

- `SendDueRemindersJob` — every minute, sends due T-24h/T-1h reminder rows.
- `ReconcileBookingsJob` — every two minutes, polls each confirmed upcoming booking's
  provider event and cancels ours if the provider event is gone.

Without both processes running, bookings still get created via the widget/API, but
webhooks, reminders, and cancellation-by-deletion-in-Google will not fire.

## Demo script

1. Visit `/setup`. Either connect a real Google account (see above) or set
   `CALENDAR_GATEWAY=mock` in `.env` and skip straight to step 2.
2. Visit `/demo`. Book a slot through the widget (pick a day → time → enter lead
   details → confirm).
3. Watch the **webhook inspector** on `/demo` — a `booking.created` event should
   appear, with its pretty-printed payload, `Calendar-Service-Signature` header, and
   delivery status.
4. The **bookings table** on `/demo` shows the new booking as `confirmed`.
5. If connected to real Google: open Google Calendar and delete the event. Within two
   minutes, `ReconcileBookingsJob` detects it's gone and flips the booking to
   `canceled` — watch the bookings table update and a `booking.canceled` event land in
   the webhook inspector.
6. Try the **theme switcher** on `/demo` to see the widget restyle via CSS variables.
7. Visit the booking's `/manage/{token}` link (from the confirmation screen) to cancel
   or reschedule it directly.

## API

- `GET /api/v1/tenants/{tenant}/availability?from=YYYY-MM-DD&to=YYYY-MM-DD` — available
  slots (max 7-day range).
- `POST /api/v1/tenants/{tenant}/bookings` — create a booking.
- `GET /api/v1/bookings/{uuid}` — fetch a booking.

Unauthenticated in this POC; field names deliberately track al-app's
`CalendlyCreateInviteeRequestData` / `CalendlyAvailableTimeData` /
`BookedCalendarEventData` shapes.

## Webhooks

`booking.created` / `booking.canceled` events are POSTed to each registered endpoint
(the seeder registers `/demo/webhook-sink`), signed with:

```
Calendar-Service-Signature: t=<unix timestamp>,v1=<hmac_sha256(secret, "{t}.{body}")>
```

matching Calendly's signature scheme. Deliveries retry up to 3 times (10s/60s/300s
backoff) and are recorded in `webhook_deliveries` regardless of outcome — delivery
failure never affects the booking itself.

## Testing

```bash
php artisan test
nvm use 22.14.0 && npm test
```

## Known Caveats

1. Google refresh tokens expire after 7 days while the OAuth app is in Testing status
   — weekly re-auth expected; a warning is shown on `/setup`.
2. Reconcile detects deletion/cancellation only, not the tenant dragging the event to
   a new time (Calendly has no such event either).
3. Double notification emails (ours + Google's invite) are accepted.
4. No API auth; single tenant seeded; no rate limiting.
5. Graph free/busy path is work/school-account only; personal-MSA fallback
   (`calendarView`) noted for the O365 build.
