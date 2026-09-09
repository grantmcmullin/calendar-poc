# Calendar Service — Google Calendar Booking POC

**Date:** 2026-09-09
**Status:** Approved design, pre-implementation
**Repo:** `calendar-service` (fresh Laravel app; no changes to al-app/UA-app in this phase)

## 1. Purpose

Tenants who don't subscribe to Calendly should still get booking functionality. This POC
proves we can provide Calendly-equivalent booking on top of a tenant's own calendar,
using **Google Calendar** first but designed so **Microsoft Office 365 (Graph)** slots in
as a second provider without restructuring.

The POC replicates the three avenues al-app uses Calendly for today:

1. **Booking widget** — a lead picks a day/time and books (our own Vue widget instead of
   Calendly's embed).
2. **Availability lookups + booking via API** — endpoints shaped so UA-app's
   DirectConnect agent could pull availability and create bookings, mirroring its
   existing `CalendarServiceContract` consumption.
3. **Webhooks** — we push `booking.created` / `booking.canceled` events to UA-app in an
   envelope modeled on the Calendly webhooks it already consumes.

### Parity rule (governs every design decision)

Any capability used from Google must have a Microsoft Graph equivalent. Where Google
offers something Graph doesn't (or vice versa — e.g. Graph returns `workingHours` from
`getSchedule`; Google has no business-hours concept), the capability becomes **our own
setting in our own DB** and is never read from a provider.

### Non-goals

- No auth on the POC app or its API (discovery only; API is shaped so auth slots in later).
- No changes to UA-app.
- No O365 implementation (design-on-paper only, verified against Graph docs).
- No provider push-notification channels (documented as the production path; POC polls).
- No detection of the tenant *moving* an event to a new time (Calendly can't express
  that either; only cancellation is detected).
- No SMS sending (channel contract + stub only).

## 2. What al-app expects (shapes we mirror)

Verified in the al-app codebase:

- **`Integration` model / `integrations` table** — `tenant_id`, `type`, encrypted
  `api_token`/`refresh_token` via accessor mutators, `expires_at`,
  `refresh_token_expires_at`, `data` JSON for provider extras
  (`app/Domain/Integrations/Integration.php`).
- **`CalendarServiceContract`** (`app/Domain/DirectConnect/Services/`) —
  `getAvailableSlots(tenant, from, to): AvailableCalendarSlotData[]` (bare start times)
  and `bookSlot(tenant, startTime, lead): BookedCalendarEventData{uri, rescheduleUrl}`.
- **Booking request shape** — `CalendlyCreateInviteeRequestData`: `event_type`,
  `start_time`, `invitee{first_name,last_name,email,timezone,text_reminder_number}`,
  `tracking{utm_*}`, optional `location`. UA-app passes the encrypted lead id in
  `tracking.utm_content`.
- **Webhook consumption** — `CalendlyV2WebhookController` + `WithCalendlyV2Payload`
  read: top-level `event` name and `created_by` (used to find the Integration),
  `payload.event.uri` / `payload.event.start_time`, `payload.tracking.utm_content`.
  Booking-time writes are deduped against webhook writes by `event_uuid`.
- **BillingGateway pattern** (`app/Domain/Billing/Contracts/Services/` +
  `Services/Stripe/`, `Services/Mock/`) — top-level gateway contract exposing named
  sub-service contracts, per-provider implementations, full mock gateway, singleton
  bindings in a domain ServiceProvider. Our calendar gateway copies this structure.

## 3. Provider parity matrix (doc-verified)

| Capability | Google Calendar | Microsoft Graph (O365) | Consequence |
|---|---|---|---|
| Free/busy | `POST /calendar/v3/freeBusy` (scope `calendar.freebusy`; ≤50 calendars/query) | `POST /me/calendar/getSchedule` (`Calendars.ReadBasic`+; **work/school accounts only**; `availabilityViewInterval` 5–1440 min) | Common denominator: providers answer "busy blocks", we compute slots |
| Create event + invite attendee | `events.insert` with `sendUpdates=all` (scope `calendar.events`) | `POST /me/events` (`Calendars.ReadWrite`); invites to attendees always sent (not configurable) | Attendee-mode booking works on both |
| Delete/cancel event | `events.delete` with `sendUpdates=all` | `DELETE /me/events/{id}` | Cancellation email to lead for free |
| Detect deleted event | `events.get` → 404/410 **or** 200 with `status: "cancelled"` (soft delete) | `GET /me/events/{id}` → 404 | Reconcile probe |
| Idempotent create | none | `transactionId` | Our `bookings.uuid` is the idempotency anchor; pass `transactionId` on Graph later |
| Custom metadata on event | `extendedProperties.private` | open extensions / `singleValueExtendedProperties` | Store `booking_uuid` on the provider event |
| Business hours / working days | none | `workingHours` in `getSchedule` response — **never read** | Our own `booking_settings` |
| Timezones | IANA names | `dateTimeTimeZone` accepts Windows names; IANA support to be verified at O365 build time — normalize in the Microsoft service if needed | Store IANA in our DB; provider service owns any mapping |
| Change push | `events.watch` channels: HTTPS + domain verification, empty-body poke (must re-fetch), no auto-renewal | Graph subscriptions on `event`: ≤10,080 min (~7 days) lifetime, renewal + validation handshake, basic notifications carry only the resource id | Both are "poke then re-fetch with babysat lifetimes" → POC polls; push is the documented production path |
| POC token gotcha | OAuth apps in **Testing** publishing status (external user type): refresh tokens expire after **7 days**; ≤100 refresh tokens per account/client | n/a | Warn on the setup page; expect weekly re-auth during discovery |

## 4. Architecture overview

```
                       ┌──────────────────────────────────────────────┐
 Vue BookingWidget ──▶ │  HTTP API (/api/v1)                          │
 Manage page ────────▶ │   availability / bookings / manage           │
 (UA-app, later) ────▶ │                                              │
                       │  Domain                                      │
                       │   Bookings: AvailabilityCalculator,          │
                       │     Create/Cancel/RescheduleBookingAction    │
                       │   Webhooks: SendWebhookJob (HMAC)  ──────────┼──▶ UA-app-shaped endpoint
                       │   Notifications: reminders + MailChannel     │     (POC: local sink)
                       │                                              │
                       │  CalendarGatewayContract (billing-style)     │
                       │   ├── Services/Google  (POC)                 │
                       │   ├── Services/Mock    (tests + no-creds demo)│
                       │   └── Services/Microsoft (future)            │
                       └──────────────────────────────────────────────┘
 Scheduler: ReconcileBookingsJob (2 min) · SendDueRemindersJob (1 min)
```

**Source of truth:** the `bookings` row. The provider event is a projection of it; the
reconcile job detects when the projection was destroyed and cancels the booking.

**Time:** `app.timezone = UTC`; every datetime column stores UTC. Tenant timezone is
used only for slot-grid math; lead timezone only for display/notifications.

## 5. Data model

### `tenants`
POC stand-in for al-app tenants. `id`, `name`, `timezone` (IANA string), timestamps.
Seeded with one demo tenant.

### `integrations`
Mirrors al-app's table so the code lifts across. `id`, `tenant_id`, `type` (string:
`google`, later `microsoft`), `api_token` (text, encrypted via accessor),
`refresh_token` (text, encrypted), `expires_at`, `refresh_token_expires_at` (nullable;
Microsoft rotates refresh tokens), `data` JSON (`account_email`, `calendar_id` —
`primary` for POC — plus provider extras). One active integration per tenant in the POC.
No `price`/presenter baggage.

### `booking_settings`
1:1 with tenant; every field is **ours** (parity rule):
- `tenant_id`
- `available_days` — JSON array of lowercase weekday names
- `office_starts_at`, `office_ends_at` — `TIME`, interpreted in tenant timezone
- `meeting_length_minutes` — int, dropdown values **15 / 30 / 45 / 60**; also the slot
  grid increment

### `bookings`
- `id`, `uuid` (public identifier; plays Calendly's `event_uuid` role in webhooks/dedupe)
- `tenant_id`, `provider` (string), `provider_event_id` (nullable until created),
  `provider_event_link` (nullable; Google `htmlLink`)
- Lead snapshot: `lead_first_name`, `lead_last_name`, `lead_email`, `lead_phone`,
  `lead_timezone`
- `tracking` JSON — accepted verbatim on the booking request, echoed verbatim in
  webhooks (how UA-app's encrypted-lead-id-in-`utm_content` keeps working unchanged)
- `starts_at`, `ends_at` (UTC datetimes)
- `status` (`confirmed` | `canceled`), `canceled_at`, `cancellation_source`
  (`lead` | `provider`, nullable)
- `manage_token` — random 64-char string; powers the lead manage page
- `rescheduled_from_booking_id` (nullable self-FK)
- timestamps. Index on (`tenant_id`, `starts_at`); uniqueness of a confirmed slot is
  enforced in the create transaction (MySQL has no partial unique indexes), see §9.

### `webhook_endpoints`
`id`, `tenant_id`, `url`, `secret`, `events` JSON (e.g.
`["booking.created","booking.canceled"]`), `active` bool. Seeded with one row pointing
at the local `/demo/webhook-sink` route.

### `webhook_deliveries`
`id`, `webhook_endpoint_id`, `event`, `payload` JSON, `signature`, `response_status`
(nullable), `attempts`, `delivered_at` (nullable), timestamps. Feeds the demo page's
webhook inspector.

### `reminders`
`id`, `booking_id`, `recipient` (`lead` | `tenant`), `channel` (`mail`; `sms` reserved),
`send_at` (UTC), `sent_at` (nullable), timestamps.

### Enums (PHP string-backed)
`IntegrationType` (`google`, `microsoft`), `BookingStatus`, `CancellationSource`,
`Weekday`, `MeetingLength` (15/30/45/60), `ReminderRecipient`, `NotificationChannel`,
`WebhookEvent` (`booking.created`, `booking.canceled`).

## 6. Calendar gateway layer (billing pattern)

```
app/Domain/Calendar/
  Contracts/Services/
    CalendarGatewayContract.php
    CalendarAuthServiceContract.php
    CalendarAvailabilityServiceContract.php
    CalendarEventServiceContract.php
  Services/Google/
    CalendarGoogleService.php            (gateway: auth()/availability()/events())
    CalendarAuthGoogleService.php
    CalendarAvailabilityGoogleService.php
    CalendarEventGoogleService.php
    GoogleClient.php                     (shared HTTP + token plumbing)
  Services/Mock/
    CalendarMockService.php (+ sub-services, in-memory busy blocks & events)
  Data/   (BusyBlockData, CalendarEventDraftData, ProviderEventData, OAuthTokensData)
  Exceptions/ (ProviderAuthExpiredException, ProviderApiFailedException,
               SlotConflictException, ProviderNotConfiguredException)
  Providers/CalendarServiceProvider.php
  CalendarGatewayManager.php
```

### Contracts

```php
interface CalendarGatewayContract
{
    public function auth(): CalendarAuthServiceContract;
    public function availability(): CalendarAvailabilityServiceContract;
    public function events(): CalendarEventServiceContract;
}

interface CalendarAuthServiceContract
{
    public function authorizationUrl(string $state): string;
    public function exchangeCode(string $code): OAuthTokensData;
    public function refreshTokens(Integration $integration): void; // persists new tokens
    public function revoke(Integration $integration): void;
}

interface CalendarAvailabilityServiceContract
{
    /** @return array<int, BusyBlockData>  start/end UTC */
    public function busyBlocks(Integration $integration, CarbonImmutable $from, CarbonImmutable $to): array;
}

interface CalendarEventServiceContract
{
    public function create(Integration $integration, CalendarEventDraftData $draft): ProviderEventData;
    public function delete(Integration $integration, string $providerEventId): void;
    public function exists(Integration $integration, string $providerEventId): bool;
}
```

Deliberately absent: business hours, working-hours reads, slot logic — providers only
answer "when are you busy" and "create/delete/check this event", keeping the contract
inside the Google∩Graph intersection by construction.

**Difference from billing:** provider varies per tenant, not app-wide, so sub-services
are stateless singletons whose methods take the `Integration`, and
`CalendarGatewayManager::for(Integration $integration): CalendarGatewayContract`
selects the gateway by `integration->type`. `config('calendar.gateway')` /
`CALENDAR_GATEWAY=mock` forces the Mock gateway app-wide (tests; credential-less demo).

### DTOs

- `BusyBlockData{start, end}` (CarbonImmutable UTC)
- `CalendarEventDraftData{summary, description, start, end, timezone, attendeeEmail,
  attendeeName, bookingUuid}`
- `ProviderEventData{id, link}`
- `OAuthTokensData{accessToken, refreshToken, expiresAt, accountEmail}`

## 7. Google implementation (wire-level, doc-verified)

`GoogleClient` follows al-app's `CalendlyClient`: Laravel `Http` base-url client, bearer
token from the Integration, `retry` hook that on 401 refreshes tokens once and replays,
`RequestException` mapped to the typed exceptions above.

- **OAuth authorize:** redirect to `https://accounts.google.com/o/oauth2/v2/auth` with
  `client_id`, `redirect_uri` (route back to `/setup`), `response_type=code`,
  `scope=https://www.googleapis.com/auth/calendar.events
  https://www.googleapis.com/auth/calendar.freebusy`,
  `access_type=offline`, `prompt=consent` (forces refresh-token issuance on re-auth),
  `state` (random, verified on callback via session).
- **Token exchange / refresh:** `POST https://oauth2.googleapis.com/token`
  (`authorization_code` / `refresh_token` grants). Store tokens encrypted. The authorize
  request also includes the `openid email` scopes; at connect time we call the OAuth
  userinfo endpoint once and store the address in `integrations.data.account_email`.
- **Revoke (disconnect):** `POST https://oauth2.googleapis.com/revoke?token=…`, then
  delete the Integration row.
- **Busy blocks:** `POST https://www.googleapis.com/calendar/v3/freeBusy` body
  `{timeMin, timeMax (RFC3339 UTC), items: [{id: data.calendar_id}]}` →
  flatten `calendars.{id}.busy[]`; surface `calendars.{id}.errors` as
  `ProviderApiFailedException`.
- **Create event:** `POST /calendar/v3/calendars/{calendarId}/events?sendUpdates=all`

  ```json
  {
    "summary": "Consultation: {lead name}",
    "description": "Booked via ULH.\nPhone: {lead phone}",
    "start": {"dateTime": "...", "timeZone": "<tenant IANA tz>"},
    "end":   {"dateTime": "...", "timeZone": "<tenant IANA tz>"},
    "attendees": [{"email": "<lead email>", "displayName": "<lead name>"}],
    "extendedProperties": {"private": {"booking_uuid": "<uuid>"}}
  }
  ```

  Response → `ProviderEventData{id, htmlLink}`. Google emails the invite to the lead
  (`sendUpdates=all`).

  **Finding:** unlike Calendly (which rejects an `already_filled` start time),
  `events.insert` performs **no conflict check** — Google happily double-books. Our
  create-transaction guard (§9) is therefore the sole double-booking protection;
  `SlotConflictException` originates from our own guard, never from Google.
- **Delete event:** `DELETE .../events/{eventId}?sendUpdates=all` (lead gets the
  cancellation email). 404/410 responses are treated as already-gone, not errors.
- **Exists probe:** `GET .../events/{eventId}` → `false` on 404/410 **or** on 200 with
  `status === "cancelled"` (Google soft-deletes; a trashed event still returns 200).

### Microsoft Graph build notes (future, verified against Graph v1.0 docs)

- Auth: Azure app registration, MSAL auth-code flow, delegated `Calendars.ReadWrite`
  (+ `offline_access`); refresh tokens rotate → persist both on refresh and use
  `refresh_token_expires_at`.
- Busy: `POST /me/calendar/getSchedule` (`Calendars.ReadBasic` suffices; work/school
  accounts only — acceptable for "Office 365" scope) or `GET /me/calendarView` as the
  personal-account fallback.
- Create: `POST /me/events` with `transactionId = booking uuid` (idempotent retries);
  invites always sent. Metadata via `singleValueExtendedProperties`.
- Delete: `DELETE /me/events/{id}`. Exists: `GET /me/events/{id}` → 404.
- Timezones: send IANA if accepted (verify at build time); otherwise map IANA→Windows
  inside `Services/Microsoft/` only.
- Push (production): Graph subscriptions on `/me/events`, ≤10,080-minute lifetime,
  renewal job + `validationToken` handshake.

## 8. Availability engine

`App\Domain\Bookings\AvailabilityCalculator` — **pure** (no I/O): inputs are the
settings, "now", the requested range, provider busy blocks, and the tenant's confirmed
bookings; output is UTC slot start times.

1. Clamp range to **7 days** (Calendly's own `available_times` limit; keeps the widget
   paging weekly, matching real-Calendly behavior).
2. For each day in range (tenant timezone) whose weekday ∈ `available_days`, lay a grid
   from `office_starts_at` stepping `meeting_length_minutes`; a slot is kept only if it
   **ends** by `office_ends_at`.
3. Drop slots starting ≤ now.
4. Drop slots overlapping any provider busy block (any overlap kills the slot).
5. Drop slots overlapping the tenant's own `confirmed` bookings (belt-and-braces
   against provider sync lag).
6. Convert to UTC, return start times.

DST note: grid times are built as tenant-timezone wall-clock times and converted per
slot, so a DST-transition day simply yields the wall-clock hours that exist that day.
This is a unit-test case, not a special code path.

The HTTP layer fetches busy blocks once per request via
`gateway->availability()->busyBlocks(...)` and hands them to the calculator.

## 9. Booking lifecycle

### `CreateBookingAction`
1. `DB::transaction` with a per-tenant advisory lock
   (`GET_LOCK("booking:tenant:{id}")`, released after commit): re-validate the slot —
   grid-valid for current settings **and** no overlapping confirmed booking — then
   insert the `bookings` row (`confirmed`, uuid + manage_token generated). A failed
   re-validation throws `SlotConflictException` (→ API 409) — our guard is the only
   double-booking protection; Google does not conflict-check (§7).
2. After commit, call `events()->create(...)`. On provider failure → delete the row and
   rethrow (API 502). On success → persist `provider_event_id` / `provider_event_link`.
3. Create reminder rows (§11), send confirmation mails immediately (lead: manage link;
   tenant: lead details), dispatch `booking.created` webhook job.

Used by the widget, the reschedule flow, and (later) the DirectConnect agent — one
write path.

### `CancelBookingAction(booking, source)`
Mark `canceled` (+`canceled_at`, `cancellation_source`), delete the provider event
**unless** `source === provider` (it's already gone), delete pending reminders, send a
cancellation notice to the non-initiating party, dispatch `booking.canceled`.

### `RescheduleBookingAction(booking, newStartTime)`
`CancelBookingAction(source: lead)` + `CreateBookingAction` with the same lead snapshot
and `tracking`, linked via `rescheduled_from_booking_id`. Emits `booking.canceled` then
`booking.created` — the same event sequence UA-app already handles from Calendly
reschedules.

## 10. Outbound webhooks

Envelope grammar mirrors Calendly (top-level `event` + `created_by` + `payload`
containing entity + `tracking`); vocabulary is ours:

```json
{
  "event": "booking.created",
  "created_by": "tenant:{tenant_id}:integration:{integration_id}",
  "payload": {
    "booking": {
      "uuid": "…",
      "uri": "https://calendar-service.test/api/v1/bookings/{uuid}",
      "start_time": "2026-09-10T16:00:00Z",
      "end_time": "2026-09-10T16:30:00Z",
      "status": "confirmed",
      "provider": "google",
      "rescheduled_from": null,
      "cancellation": null
    },
    "invitee": {
      "first_name": "…", "last_name": "…", "email": "…",
      "phone": "…", "timezone": "…"
    },
    "tracking": { "…echoed verbatim from the booking request…": "…" }
  }
}
```

`booking.canceled` sets `status: "canceled"` and
`cancellation: {source, canceled_at}`. UA-app's `WithCalendlyV2Payload` trait maps
almost line-for-line (`payload.booking.uuid` ↔ event uuid, `payload.booking.start_time`,
`payload.tracking.utm_content`).

**`SendWebhookJob`** (queued, per endpoint × event): body = JSON above;
`Calendar-Service-Signature: t=<unix>,v1=<hmac_sha256(secret, t.".".body)>` (Calendly's
scheme); 3 tries with backoff (10s/60s/300s); every attempt updates the
`webhook_deliveries` row. Delivery failure never affects the booking.

POC seeds an endpoint at `POST /demo/webhook-sink`, which verifies the signature and
stores the received body for the demo page to render.

## 11. Notifications (reminders plumbing)

- **`NotificationChannelContract`** — `send(NotificationMessageData $message): void`.
  `NotificationMessageData{recipientName, recipientEmail, recipientPhone, subject,
  lines, booking}`.
- **`MailChannel`** — Laravel mailables; local mailer is `log` (or Mailpit via Herd).
- **`SmsChannel`** — stub throwing `NotImplementedException`; exists to make the wiring
  point visible.
- **`ReminderScheduler`** — at booking time creates `reminders` rows for lead + tenant
  at **T-24h** and **T-1h** (constants for POC; called out as a future per-tenant
  setting), skipping times already past.
- **`SendDueRemindersJob`** — every minute: claim due unsent rows (update-then-select to
  avoid double sends), resolve channel from the row, send, stamp `sent_at`.
- Immediate sends (not `reminders` rows): booking confirmation (both parties, from
  `CreateBookingAction`) and cancellation notice (non-initiating party, from
  `CancelBookingAction`).
- Google's own invite/cancellation emails ride alongside ours; duplication accepted for
  the POC.

## 12. Reconcile loop (cancellation detection — chosen option: polling)

`ReconcileBookingsJob`, scheduled every 2 minutes: for each `confirmed` booking with
`starts_at > now` and a `provider_event_id`, call `events()->exists(...)`.

- **Definitive gone** (404/410 or `status: "cancelled"`) →
  `CancelBookingAction(source: provider)` → reminders cleared, `booking.canceled`
  pushed, demo page shows the flip.
- **Provider error** (auth, 5xx, network) → log and skip; an API blip must never
  mass-cancel bookings. `ProviderAuthExpiredException` additionally flags the
  integration for re-auth (surfaced on `/setup`).

Production path (documented, not built): provider push (Google `events.watch` /
Graph subscriptions) demotes this poll to a low-frequency fallback sweep.

## 13. HTTP API (`/api/v1`, unauthenticated in POC)

```
GET  /api/v1/tenants/{tenant}/availability?from=YYYY-MM-DD&to=YYYY-MM-DD
  200 { "collection": [ {"status":"available","start_time":"…Z","end_time":"…Z"}, … ] }
  422 invalid/oversized range (>7 days)

POST /api/v1/tenants/{tenant}/bookings
  { "start_time":"…Z",
    "invitee": {"first_name","last_name","email","phone","timezone"},
    "tracking": {…} }
  201 { "uuid","uri","start_time","end_time","status":"confirmed",
        "reschedule_url","cancel_url" }        // manage-page URLs
  409 slot no longer available
  502 provider failure

GET  /api/v1/bookings/{uuid}
  200 booking resource (as in webhook payload)
```

Field names deliberately track `CalendlyCreateInviteeRequestData` /
`CalendlyAvailableTimeData` / `BookedCalendarEventData`, so a future
`GoogleCalendarService implements CalendarServiceContract` in al-app is a
near-mechanical mapping.

**Web routes:** `/setup` (+ settings POST, OAuth redirect/callback, disconnect),
`/demo`, `/demo/webhook-sink` (POST) + deliveries/bookings JSON feeds for the demo
panels, `/manage/{manage_token}` (+ cancel POST, reschedule POST).

## 14. Frontend

Vite, **Tailwind 4**, **Vue 3** mounted as islands on Blade pages.

### `/setup`
- **Integration panel:** Connect Google (OAuth redirect) / connected state (account
  email, calendar, token expiry, Disconnect) / a visible warning that Testing-status
  Google apps expire refresh tokens after 7 days / re-auth banner when the integration
  is flagged.
- **Booking settings panel:** timezone select, weekday checkboxes, office start/end
  time selects, meeting-length dropdown (15/30/45/60). Plain Blade forms.
- Read-only card: seeded webhook endpoint URL + secret.

### `/demo`
- Mounts `BookingWidget` with a fake-lead `tracking` blob (mimicking UA-app's
  encrypted-lead-id pattern).
- **Webhook inspector:** polls the deliveries feed; renders event, pretty-printed
  payload, signature header, response status.
- **Bookings table:** live status; deleting the event in Google Calendar demonstrates
  reconcile flipping it to `canceled`.
- **Theme switcher** on the widget proving CSS-variable restyling.

### `/manage/{token}`
Booking summary; **Cancel** (confirm → done screen); **Reschedule** (re-mounts the
widget preloaded with the invitee; picking a slot calls the reschedule endpoint, which
returns the new booking's manage URL).

### `BookingWidget.vue`
Props: `tenantId`, `apiBase`, `tracking`, optional `reschedulingBookingUuid`.
Flow: week strip of days with availability dots (7-day pages, matching the API
clamp) → time list for the picked day in the
**lead's** timezone (auto-detected via `Intl`, switchable) → details form (name, email,
phone) → confirmation screen (booking summary + manage link). Fetches availability
week-by-week. 409 on submit → re-fetch slots + "that time was just taken" notice;
5xx → retry prompt.

**Theming:** renders inline in the host DOM (no iframe). All colors/radii/spacing
tokens are CSS custom properties via Tailwind 4 `@theme` (`--color-primary`, etc.), so
an embedding page restyles the widget by overriding variables. (Production embed as a
self-contained script bundle is noted as the eventual delivery mechanism; POC mounts
the SFC directly.)

## 15. Error handling

- Provider boundary maps to typed exceptions: `ProviderAuthExpiredException` (flag
  integration, surface on `/setup`), `SlotConflictException` (→ 409),
  `ProviderApiFailedException` (→ 502, logged with tenant/integration context).
- Webhook delivery failures: retried, then visible in `webhook_deliveries`; never
  affect booking state.
- Reconcile: only definitive absence cancels (see §12).
- Widget distinguishes 409 (refresh + gentle message) from 5xx (retry).

## 16. Testing (PHPUnit + Vitest)

- **Unit — `AvailabilityCalculator`:** weekday filtering, grid vs meeting length,
  end-by-close boundary, past-slot cutoff, busy-block overlap permutations (touching
  edges are not overlaps), own-booking subtraction, a DST-transition day, 7-day clamp.
- **Unit — `Services/Google/*`:** `Http::fake()` with doc-accurate fixtures: freeBusy
  parse, event create payload assertions (attendee, extendedProperties, sendUpdates),
  401→refresh→replay, delete on already-gone event, exists probe incl.
  `status: "cancelled"`, token exchange/refresh persistence.
- **Feature (Mock gateway):** availability endpoint (range validation, shape), booking
  happy path (row + provider call + reminders + confirmation mails + webhook job),
  slot-race 409 (second booking of same slot), cancel (provider delete, reminder
  cleanup, webhook), reschedule (linked rows, canceled+created sequence), reconcile
  (gone event → canceled; provider error → untouched), manage-token resolution +
  invalid token 404, webhook HMAC signature correctness, `SendDueRemindersJob`
  claiming.
- **Vitest — widget:** slot rendering, timezone switch re-rendering, 409 recovery flow.

## 17. Scaffold & tooling

- Laravel 12, PHP 8.2 (matches al-app), fresh app in this repo; **Herd site**
  `calendar-service.test`; MySQL local DB.
- `app.timezone = UTC`; all datetimes stored UTC.
- Domain layout: `app/Domain/{Tenants,Calendar,Bookings,Webhooks,Notifications}` per
  §6; thin controllers in `app/Http`.
- Pint + PHPStan configured to al-app's standards; PHPUnit (not Pest); Vite + Vue 3 +
  Tailwind 4; Vitest.
- Seeder: demo tenant (settings defaulted: Mon–Fri, 09:00–17:00, 30 min), webhook-sink
  endpoint. `.env.example` documents `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` /
  `CALENDAR_GATEWAY`.
- Google Cloud setup (manual, documented in README): project, OAuth consent screen
  (External + Testing), Calendar API enabled, Web client with
  `https://calendar-service.test/setup/google/callback` redirect.
- Queue: `database` driver + `php artisan queue:work`; scheduler via
  `schedule:work` (both noted in README).

## 18. Known POC caveats

1. Google refresh tokens expire after 7 days while the OAuth app is in Testing status —
   weekly re-auth expected; warning shown on `/setup`.
2. Reconcile detects deletion/cancellation only, not the tenant dragging the event to a
   new time (Calendly has no such event either).
3. Double notification emails (ours + Google's invite) accepted.
4. No API auth; single tenant seeded; no rate limiting.
5. Graph free/busy path is work/school-account only; personal-MSA fallback
   (`calendarView`) noted for the O365 build.
