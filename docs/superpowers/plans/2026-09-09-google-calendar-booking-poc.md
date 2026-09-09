# Google Calendar Booking POC Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A standalone Laravel POC that gives tenants Calendly-equivalent booking on their own Google Calendar: setup page (OAuth + settings), Vue booking widget, Calendly-shaped API + outbound webhooks, reminders, and provider-cancellation reconciliation.

**Architecture:** Billing-gateway-style provider layer (`CalendarGatewayContract` → `Services/Google` + `Services/Mock`); our `bookings` table is the source of truth and the provider event a projection; a pure `AvailabilityCalculator` computes slots from our settings minus busy blocks; a polling reconcile job detects provider-side cancellations.

**Tech Stack:** PHP 8.2 / Laravel 12, MySQL (Herd), Blade + Vite + Vue 3 islands, Tailwind 4, PHPUnit (NOT Pest), Vitest, Pint, PHPStan(larastan).

**Spec:** `docs/superpowers/specs/2026-09-09-google-calendar-booking-poc-design.md` — read it first; every task below cites it.

## Global Constraints

- PHP `^8.2`, Laravel 12, PHPUnit (never Pest), Vue 3, Tailwind 4.
- `app.timezone = UTC`; **every** datetime column stores UTC. Tenant timezone only for slot-grid math; lead timezone only for display.
- Parity rule: nothing may be read from Google that has no Microsoft Graph equivalent — business hours, days, meeting length are OUR settings.
- No secrets to the frontend; Google tokens live encrypted in `integrations`.
- Meeting length dropdown values: 15 / 30 / 45 / 60.
- Availability API range clamp: max 7 days (422 beyond).
- Webhook signature header: `Calendar-Service-Signature: t=<unix>,v1=<hex hmac_sha256(secret, "{t}.{body}")>`.
- Reminder offsets: T-24h and T-1h (constants).
- Domain code layout: `app/Domain/{Tenants,Calendar,Bookings,Webhooks,Notifications}`; thin controllers in `app/Http`.
- Commit after every green test cycle. Run `vendor/bin/pint --dirty` before each commit.
- All PHP classes: `declare(strict_types=1);` is NOT used in al-app — do not add it. Match Laravel skeleton style.

---

### Task 1: Scaffold the Laravel app + tooling

**Files:**
- Create: entire Laravel 12 skeleton at repo root (composer create-project, merged around existing `docs/` + `.git`)
- Modify: `.env`, `.env.example`, `composer.json` (require-dev), `vite.config.js`, `resources/css/app.css`, `package.json`
- Create: `config/calendar.php`, `phpstan.neon`, `pint.json`, `vitest.config.ts`

**Interfaces:**
- Consumes: nothing (first task)
- Produces: running app at `https://calendar-service.test`, `php artisan test` green, `npm run build` green, `config('calendar.gateway')`, `config('calendar.google.client_id|client_secret|redirect_uri')`, `config('calendar.reminder_offsets_minutes')` = `[1440, 60]`, Tailwind 4 `@theme` tokens `--color-primary`, `--color-primary-contrast`, `--radius-widget`.

- [ ] **Step 1: Create skeleton in a temp dir and merge it in**

```bash
cd /Users/grantmcmullin/Code/Kirschbaum
composer create-project laravel/laravel calendar-service-skeleton "^12.0" --prefer-dist --no-interaction
rsync -a --ignore-existing calendar-service-skeleton/ calendar-service/
rm -rf calendar-service-skeleton
cd calendar-service
```

(`--ignore-existing` preserves our `docs/` and `.git`. The skeleton ships PHPUnit — verify `tests/TestCase.php` extends `Illuminate\Foundation\Testing\TestCase` and `composer.json` has `phpunit/phpunit`, NOT `pestphp/pest`. If Pest snuck in: `composer remove pestphp/pest pestphp/pest-plugin-laravel --dev` and restore the classic `tests/Feature/ExampleTest.php`/`tests/Unit/ExampleTest.php` PHPUnit classes.)

- [ ] **Step 2: Database + env**

```bash
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS calendar_service"
```

`.env` (and mirror keys with blank values into `.env.example`):

```dotenv
APP_NAME=CalendarService
APP_URL=https://calendar-service.test
APP_TIMEZONE=UTC
DB_CONNECTION=mysql
DB_DATABASE=calendar_service
DB_USERNAME=root
DB_PASSWORD=
QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=log
CALENDAR_GATEWAY=provider
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://calendar-service.test/setup/google/callback
```

Confirm `config/app.php` timezone reads `env('APP_TIMEZONE', 'UTC')` (Laravel 12 default). Run `php artisan migrate` (skeleton tables incl. cache + jobs).

- [ ] **Step 3: Herd site**

```bash
herd link calendar-service
herd secure calendar-service
curl -sk https://calendar-service.test | head -5
```

Expected: Laravel welcome HTML.

- [ ] **Step 4: Create `config/calendar.php`**

```php
<?php

return [
    // 'provider' resolves real gateways by integration type; 'mock' forces the Mock gateway app-wide.
    'gateway' => env('CALENDAR_GATEWAY', 'provider'),

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
    ],

    // Minutes before starts_at to send reminders (spec §11).
    'reminder_offsets_minutes' => [1440, 60],
];
```

- [ ] **Step 5: Frontend toolchain**

```bash
npm install
npm install vue @vitejs/plugin-vue tailwindcss @tailwindcss/vite
npm install -D vitest @vue/test-utils happy-dom
```

`vite.config.js`:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/css/app.css', 'resources/js/app.js'], refresh: true }),
        vue(),
        tailwindcss(),
    ],
});
```

`resources/css/app.css`:

```css
@import "tailwindcss";

@theme {
    --color-primary: #2563eb;
    --color-primary-contrast: #ffffff;
    --radius-widget: 0.75rem;
}
```

`vitest.config.ts`:

```ts
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    test: { environment: 'happy-dom', include: ['resources/js/**/*.test.ts'] },
});
```

Add to `package.json` scripts: `"test": "vitest run"`.

- [ ] **Step 6: PHP tooling**

```bash
composer require --dev laravel/pint larastan/larastan
```

`pint.json`: copy `/Users/grantmcmullin/Code/Kirschbaum/al-app/pint.json` verbatim (house style). `phpstan.neon`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon
parameters:
    level: 6
    paths:
        - app
```

- [ ] **Step 7: Verify green**

Run: `php artisan test && npm run build && vendor/bin/phpstan analyse --no-progress`
Expected: skeleton example tests PASS, build succeeds, phpstan clean.

- [ ] **Step 8: Commit**

```bash
git add -A && git commit -m "chore: scaffold Laravel 12 app with Vue 3, Tailwind 4, PHPUnit tooling"
```

---

### Task 2: Tenants + BookingSettings

**Files:**
- Create: `database/migrations/xxxx_create_tenants_table.php`, `xxxx_create_booking_settings_table.php`
- Create: `app/Domain/Tenants/Tenant.php`, `app/Domain/Tenants/BookingSettings.php`
- Create: `app/Domain/Tenants/Enums/Weekday.php`, `app/Domain/Tenants/Enums/MeetingLength.php`
- Create: `database/factories/Domain/Tenants/TenantFactory.php`, `BookingSettingsFactory.php`
- Create: `database/seeders/DemoSeeder.php` (registered in `DatabaseSeeder`)
- Test: `tests/Feature/Domain/Tenants/BookingSettingsTest.php`

**Interfaces:**
- Consumes: Task 1 scaffold
- Produces: `Tenant{name, email, timezone; bookingSettings(): HasOne; integration(): HasOne /*added Task 3*/; webhookEndpoints(): HasMany /*added Task 9*/}`; `BookingSettings{tenant_id, available_days: array<string>, office_starts_at: 'HH:MM:SS' string, office_ends_at: string, meeting_length_minutes: int}`; `Weekday: string` enum (`monday`…`sunday`, `Weekday::values(): array`); `MeetingLength: int` enum (`Fifteen=15, Thirty=30, FortyFive=45, Sixty=60`, `MeetingLength::values(): array`); `DemoSeeder` seeding one tenant + settings.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Domain/Tenants/BookingSettingsTest.php`:

```php
<?php

namespace Tests\Feature\Domain\Tenants;

use App\Domain\Tenants\BookingSettings;
use App\Domain\Tenants\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_belong_to_tenant_and_cast_fields(): void
    {
        $tenant = Tenant::factory()->create(['timezone' => 'America/New_York']);
        $settings = BookingSettings::factory()->for($tenant)->create([
            'available_days' => ['monday', 'wednesday'],
            'office_starts_at' => '09:00:00',
            'office_ends_at' => '17:00:00',
            'meeting_length_minutes' => 30,
        ]);

        $this->assertSame(['monday', 'wednesday'], $settings->fresh()->available_days);
        $this->assertSame('09:00:00', $settings->fresh()->office_starts_at);
        $this->assertSame(30, $settings->fresh()->meeting_length_minutes);
        $this->assertTrue($tenant->bookingSettings->is($settings));
    }

    public function test_demo_seeder_creates_tenant_with_defaults(): void
    {
        $this->seed(\Database\Seeders\DemoSeeder::class);

        $tenant = Tenant::firstOrFail();
        $this->assertNotEmpty($tenant->email);
        $this->assertSame('America/New_York', $tenant->timezone);
        $this->assertSame(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], $tenant->bookingSettings->available_days);
        $this->assertSame(30, $tenant->bookingSettings->meeting_length_minutes);
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `php artisan test --filter=BookingSettingsTest` → FAIL (class not found).

- [ ] **Step 3: Implement**

Migration `create_tenants_table`:

```php
Schema::create('tenants', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('email');
    $table->string('timezone');
    $table->timestamps();
});
```

Migration `create_booking_settings_table`:

```php
Schema::create('booking_settings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->json('available_days');
    $table->time('office_starts_at');
    $table->time('office_ends_at');
    $table->unsignedSmallInteger('meeting_length_minutes');
    $table->timestamps();
});
```

`app/Domain/Tenants/Enums/Weekday.php`:

```php
<?php

namespace App\Domain\Tenants\Enums;

enum Weekday: string
{
    case Monday = 'monday';
    case Tuesday = 'tuesday';
    case Wednesday = 'wednesday';
    case Thursday = 'thursday';
    case Friday = 'friday';
    case Saturday = 'saturday';
    case Sunday = 'sunday';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

`MeetingLength.php` (same namespace):

```php
enum MeetingLength: int
{
    case Fifteen = 15;
    case Thirty = 30;
    case FortyFive = 45;
    case Sixty = 60;

    /** @return array<int, int> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

`Tenant.php`:

```php
<?php

namespace App\Domain\Tenants;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'timezone'];

    public function bookingSettings(): HasOne
    {
        return $this->hasOne(BookingSettings::class);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Domain\Tenants\TenantFactory::new();
    }
}
```

`BookingSettings.php`:

```php
<?php

namespace App\Domain\Tenants;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingSettings extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'available_days', 'office_starts_at', 'office_ends_at', 'meeting_length_minutes'];

    protected $casts = [
        'available_days' => 'array',
        'meeting_length_minutes' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Domain\Tenants\BookingSettingsFactory::new();
    }
}
```

Factories (`database/factories/Domain/Tenants/…`, namespace `Database\Factories\Domain\Tenants`, set `protected $model`):

```php
// TenantFactory definition()
return [
    'name' => fake()->company(),
    'email' => fake()->companyEmail(),
    'timezone' => 'America/New_York',
];

// BookingSettingsFactory definition()
return [
    'tenant_id' => Tenant::factory(),
    'available_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
    'office_starts_at' => '09:00:00',
    'office_ends_at' => '17:00:00',
    'meeting_length_minutes' => 30,
];
```

`database/seeders/DemoSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Domain\Tenants\BookingSettings;
use App\Domain\Tenants\Tenant;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['email' => 'tenant@example.com'],
            ['name' => 'Demo Law Firm', 'timezone' => 'America/New_York'],
        );

        BookingSettings::firstOrCreate(['tenant_id' => $tenant->id], [
            'available_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'office_starts_at' => '09:00:00',
            'office_ends_at' => '17:00:00',
            'meeting_length_minutes' => 30,
        ]);
    }
}
```

Call `$this->call(DemoSeeder::class);` from `DatabaseSeeder`.

- [ ] **Step 4: Run test** — `php artisan test --filter=BookingSettingsTest` → PASS.
- [ ] **Step 5: Commit** — `git add -A && vendor/bin/pint --dirty && git add -A && git commit -m "feat: tenants and booking settings"`

---

### Task 3: Integration model

**Files:**
- Create: `database/migrations/xxxx_create_integrations_table.php`
- Create: `app/Domain/Integrations/Integration.php`, `app/Domain/Integrations/Enums/IntegrationType.php`
- Create: `database/factories/Domain/Integrations/IntegrationFactory.php`
- Modify: `app/Domain/Tenants/Tenant.php` (add `integration()` HasOne)
- Test: `tests/Feature/Domain/Integrations/IntegrationTest.php`

**Interfaces:**
- Consumes: `Tenant` (Task 2)
- Produces: `Integration{tenant_id, type: string, api_token: ?string (encrypted cast), refresh_token: ?string (encrypted), expires_at: ?Carbon, refresh_token_expires_at: ?Carbon, data: ?array}` with `tokenIsExpired(): bool`, `requiresReauth(): bool`, `flagReauthRequired(): void`, `clearReauthFlag(): void`, `tenant(): BelongsTo`; `IntegrationType: string` enum (`Google='google'`, `Microsoft='microsoft'`); `Tenant::integration(): HasOne`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Domain\Integrations;

use App\Domain\Integrations\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_are_encrypted_at_rest_and_decrypted_on_access(): void
    {
        $integration = Integration::factory()->create(['api_token' => 'plain-token']);

        $raw = DB::table('integrations')->where('id', $integration->id)->value('api_token');

        $this->assertNotSame('plain-token', $raw);
        $this->assertSame('plain-token', $integration->fresh()->api_token);
    }

    public function test_token_expiry_and_reauth_flag(): void
    {
        $integration = Integration::factory()->create(['expires_at' => now()->subMinute()]);
        $this->assertTrue($integration->tokenIsExpired());
        $this->assertFalse($integration->requiresReauth());

        $integration->flagReauthRequired();
        $this->assertTrue($integration->fresh()->requiresReauth());

        $integration->fresh()->clearReauthFlag();
        $this->assertFalse($integration->fresh()->requiresReauth());
    }
}
```

- [ ] **Step 2: Run to verify FAIL** — `php artisan test --filter=IntegrationTest`

- [ ] **Step 3: Implement**

Migration:

```php
Schema::create('integrations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->string('type');
    $table->text('api_token')->nullable();
    $table->text('refresh_token')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->timestamp('refresh_token_expires_at')->nullable();
    $table->json('data')->nullable();
    $table->timestamps();
    $table->unique(['tenant_id', 'type']);
});
```

`Integration.php` (uses Laravel `encrypted` casts — same at-rest behavior as al-app's manual accessors):

```php
<?php

namespace App\Domain\Integrations;

use App\Domain\Tenants\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Integration extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'type', 'api_token', 'refresh_token',
        'expires_at', 'refresh_token_expires_at', 'data',
    ];

    protected $casts = [
        'api_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'data' => 'array',
    ];

    protected $hidden = ['api_token', 'refresh_token'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tokenIsExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThan(now());
    }

    public function requiresReauth(): bool
    {
        return filled(data_get($this->data, 'requires_reauth_at'));
    }

    public function flagReauthRequired(): void
    {
        $data = $this->data ?? [];
        $data['requires_reauth_at'] = now()->toIso8601String();
        $this->update(['data' => $data]);
    }

    public function clearReauthFlag(): void
    {
        $data = $this->data ?? [];
        unset($data['requires_reauth_at']);
        $this->update(['data' => $data]);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Domain\Integrations\IntegrationFactory::new();
    }
}
```

`IntegrationType.php` (namespace `App\Domain\Integrations\Enums`):

```php
enum IntegrationType: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';
}
```

Factory `definition()`:

```php
return [
    'tenant_id' => \App\Domain\Tenants\Tenant::factory(),
    'type' => IntegrationType::Google->value,
    'api_token' => 'access-token',
    'refresh_token' => 'refresh-token',
    'expires_at' => now()->addHour(),
    'data' => ['account_email' => 'tenant-cal@example.com', 'calendar_id' => 'primary'],
];
```

Add to `Tenant`: `public function integration(): HasOne { return $this->hasOne(\App\Domain\Integrations\Integration::class); }`

- [ ] **Step 4: Run test** → PASS. **Step 5: Commit** — `git commit -m "feat: integrations table mirroring al-app shape"`

---

### Task 4: Calendar gateway contracts, DTOs, exceptions, Mock gateway, manager

**Files:**
- Create: `app/Domain/Calendar/Contracts/Services/CalendarGatewayContract.php`, `CalendarAuthServiceContract.php`, `CalendarAvailabilityServiceContract.php`, `CalendarEventServiceContract.php`
- Create: `app/Domain/Calendar/Data/BusyBlockData.php`, `CalendarEventDraftData.php`, `ProviderEventData.php`, `OAuthTokensData.php`
- Create: `app/Domain/Calendar/Exceptions/ProviderAuthExpiredException.php`, `ProviderApiFailedException.php`, `SlotConflictException.php`, `ProviderNotConfiguredException.php`
- Create: `app/Domain/Calendar/Services/Mock/CalendarMockService.php`
- Create: `app/Domain/Calendar/CalendarGatewayManager.php`, `app/Domain/Calendar/Providers/CalendarServiceProvider.php` (register in `bootstrap/providers.php`)
- Test: `tests/Feature/Domain/Calendar/CalendarGatewayManagerTest.php`, `tests/Feature/Domain/Calendar/CalendarMockServiceTest.php`

**Interfaces:**
- Consumes: `Integration`, `IntegrationType` (Task 3)
- Produces (exact — later tasks depend on these signatures):

```php
interface CalendarGatewayContract {
    public function auth(): CalendarAuthServiceContract;
    public function availability(): CalendarAvailabilityServiceContract;
    public function events(): CalendarEventServiceContract;
}
interface CalendarAuthServiceContract {
    public function authorizationUrl(string $state): string;
    public function exchangeCode(string $code): OAuthTokensData;
    public function refreshTokens(Integration $integration): void;
    public function revoke(Integration $integration): void;
}
interface CalendarAvailabilityServiceContract {
    /** @return array<int, BusyBlockData> */
    public function busyBlocks(Integration $integration, CarbonImmutable $from, CarbonImmutable $to): array;
}
interface CalendarEventServiceContract {
    public function create(Integration $integration, CalendarEventDraftData $draft): ProviderEventData;
    public function delete(Integration $integration, string $providerEventId): void;
    public function exists(Integration $integration, string $providerEventId): bool;
}
```

DTOs — all `final` with readonly promoted props:
`BusyBlockData{CarbonImmutable $start, CarbonImmutable $end}` · `CalendarEventDraftData{string $summary, string $description, CarbonImmutable $start, CarbonImmutable $end, string $timezone, string $attendeeEmail, string $attendeeName, string $bookingUuid}` · `ProviderEventData{string $id, ?string $link = null}` · `OAuthTokensData{string $accessToken, ?string $refreshToken, CarbonImmutable $expiresAt, string $accountEmail}`.

`CalendarGatewayManager::for(Integration $integration): CalendarGatewayContract` and `::forType(IntegrationType $type): CalendarGatewayContract`. `CalendarMockService` (singleton) implements ALL FOUR interfaces (`auth()/availability()/events()` return `$this`) with public test hooks: `array $busy = []`, `bool $failCreate = false`, `bool $failProbe = false`, `array $createdEvents = []`, `array $deletedEventIds = []`, and `authorizationUrl` → `'https://mock.test/authorize?state='.$state`, `exchangeCode` → fixed `OAuthTokensData('mock-access', 'mock-refresh', now +1h, 'mock@example.com')`.

- [ ] **Step 1: Write failing tests**

`CalendarGatewayManagerTest.php`:

```php
public function test_mock_gateway_is_resolved_when_config_forces_mock(): void
{
    config(['calendar.gateway' => 'mock']);
    $integration = Integration::factory()->create();

    $gateway = app(CalendarGatewayManager::class)->for($integration);

    $this->assertInstanceOf(CalendarMockService::class, $gateway);
    $this->assertSame($gateway, app(CalendarGatewayManager::class)->for($integration)); // singleton
}

public function test_microsoft_type_throws_not_configured(): void
{
    config(['calendar.gateway' => 'provider']);
    $this->expectException(ProviderNotConfiguredException::class);
    app(CalendarGatewayManager::class)->forType(IntegrationType::Microsoft);
}
```

`CalendarMockServiceTest.php`:

```php
public function test_mock_events_lifecycle(): void
{
    $mock = app(CalendarMockService::class);
    $integration = Integration::factory()->create();
    $draft = new CalendarEventDraftData(
        summary: 'Consultation: Jane Doe', description: 'Phone: +15550001111',
        start: CarbonImmutable::parse('2026-09-14T14:00:00Z'), end: CarbonImmutable::parse('2026-09-14T14:30:00Z'),
        timezone: 'America/New_York', attendeeEmail: 'jane@example.com', attendeeName: 'Jane Doe',
        bookingUuid: 'uuid-1',
    );

    $event = $mock->events()->create($integration, $draft);
    $this->assertTrue($mock->events()->exists($integration, $event->id));

    $mock->events()->delete($integration, $event->id);
    $this->assertFalse($mock->events()->exists($integration, $event->id));
}

public function test_mock_busy_blocks_filter_to_range(): void
{
    $mock = app(CalendarMockService::class);
    $mock->busy = [new BusyBlockData(CarbonImmutable::parse('2026-09-14T10:00:00Z'), CarbonImmutable::parse('2026-09-14T11:00:00Z'))];

    $inRange = $mock->availability()->busyBlocks(Integration::factory()->create(), CarbonImmutable::parse('2026-09-14T00:00:00Z'), CarbonImmutable::parse('2026-09-15T00:00:00Z'));
    $outOfRange = $mock->availability()->busyBlocks(Integration::factory()->create(), CarbonImmutable::parse('2026-09-16T00:00:00Z'), CarbonImmutable::parse('2026-09-17T00:00:00Z'));

    $this->assertCount(1, $inRange);
    $this->assertCount(0, $outOfRange);
}

public function test_mock_failure_flags_throw(): void
{
    $mock = app(CalendarMockService::class);
    $mock->failProbe = true;
    $this->expectException(ProviderApiFailedException::class);
    $mock->availability()->busyBlocks(Integration::factory()->create(), CarbonImmutable::now(), CarbonImmutable::now()->addHour());
}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement**

Contracts and DTOs exactly per the Interfaces block. Exceptions all extend `RuntimeException` (empty bodies). Mock:

```php
<?php

namespace App\Domain\Calendar\Services\Mock;

use App\Domain\Calendar\Contracts\Services\CalendarAuthServiceContract;
use App\Domain\Calendar\Contracts\Services\CalendarAvailabilityServiceContract;
use App\Domain\Calendar\Contracts\Services\CalendarEventServiceContract;
use App\Domain\Calendar\Contracts\Services\CalendarGatewayContract;
use App\Domain\Calendar\Data\BusyBlockData;
use App\Domain\Calendar\Data\CalendarEventDraftData;
use App\Domain\Calendar\Data\OAuthTokensData;
use App\Domain\Calendar\Data\ProviderEventData;
use App\Domain\Calendar\Exceptions\ProviderApiFailedException;
use App\Domain\Integrations\Integration;
use Carbon\CarbonImmutable;

class CalendarMockService implements CalendarGatewayContract, CalendarAuthServiceContract, CalendarAvailabilityServiceContract, CalendarEventServiceContract
{
    /** @var array<int, BusyBlockData> */
    public array $busy = [];

    public bool $failCreate = false;

    public bool $failProbe = false;

    /** @var array<string, CalendarEventDraftData> */
    public array $createdEvents = [];

    /** @var array<int, string> */
    public array $deletedEventIds = [];

    public function auth(): CalendarAuthServiceContract { return $this; }
    public function availability(): CalendarAvailabilityServiceContract { return $this; }
    public function events(): CalendarEventServiceContract { return $this; }

    public function authorizationUrl(string $state): string
    {
        return 'https://mock.test/authorize?state='.$state;
    }

    public function exchangeCode(string $code): OAuthTokensData
    {
        return new OAuthTokensData('mock-access', 'mock-refresh', CarbonImmutable::now()->addHour(), 'mock@example.com');
    }

    public function refreshTokens(Integration $integration): void
    {
        $integration->update(['api_token' => 'mock-access-refreshed', 'expires_at' => now()->addHour()]);
    }

    public function revoke(Integration $integration): void {}

    public function busyBlocks(Integration $integration, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($this->failProbe) {
            throw new ProviderApiFailedException('Mock probe failure.');
        }

        return array_values(array_filter(
            $this->busy,
            fn (BusyBlockData $block): bool => $block->start->lessThan($to) && $from->lessThan($block->end),
        ));
    }

    public function create(Integration $integration, CalendarEventDraftData $draft): ProviderEventData
    {
        if ($this->failCreate) {
            throw new ProviderApiFailedException('Mock create failure.');
        }

        $id = 'mock-event-'.$draft->bookingUuid;
        $this->createdEvents[$id] = $draft;

        return new ProviderEventData($id, 'https://mock.test/events/'.$id);
    }

    public function delete(Integration $integration, string $providerEventId): void
    {
        $this->deletedEventIds[] = $providerEventId;
    }

    public function exists(Integration $integration, string $providerEventId): bool
    {
        return array_key_exists($providerEventId, $this->createdEvents)
            && ! in_array($providerEventId, $this->deletedEventIds, true);
    }
}
```

Manager:

```php
<?php

namespace App\Domain\Calendar;

use App\Domain\Calendar\Contracts\Services\CalendarGatewayContract;
use App\Domain\Calendar\Exceptions\ProviderNotConfiguredException;
use App\Domain\Calendar\Services\Google\CalendarGoogleService;
use App\Domain\Calendar\Services\Mock\CalendarMockService;
use App\Domain\Integrations\Enums\IntegrationType;
use App\Domain\Integrations\Integration;

class CalendarGatewayManager
{
    public function for(Integration $integration): CalendarGatewayContract
    {
        return $this->forType(IntegrationType::from($integration->type));
    }

    public function forType(IntegrationType $type): CalendarGatewayContract
    {
        if (config('calendar.gateway') === 'mock') {
            return app(CalendarMockService::class);
        }

        return match ($type) {
            IntegrationType::Google => app(CalendarGoogleService::class),
            IntegrationType::Microsoft => throw new ProviderNotConfiguredException('Microsoft provider is not implemented in this POC.'),
        };
    }
}
```

(`CalendarGoogleService` doesn't exist yet — create an empty placeholder class implementing `CalendarGatewayContract` whose three methods `throw new ProviderNotConfiguredException('pending Task 5/6')`; Tasks 5–6 replace the bodies.)

`CalendarServiceProvider::register()`:

```php
$this->app->singleton(CalendarMockService::class);
$this->app->singleton(CalendarGatewayManager::class);
```

Add `App\Domain\Calendar\Providers\CalendarServiceProvider::class` to `bootstrap/providers.php`.

- [ ] **Step 4: Run → PASS.** **Step 5: Commit** — `git commit -m "feat: calendar gateway contracts, DTOs, mock gateway, manager"`

---

### Task 5: Google auth service + GoogleClient

**Files:**
- Create: `app/Domain/Calendar/Services/Google/GoogleClient.php`, `CalendarAuthGoogleService.php`
- Modify: `app/Domain/Calendar/Services/Google/CalendarGoogleService.php` (real `auth()`)
- Test: `tests/Feature/Domain/Calendar/Google/CalendarAuthGoogleServiceTest.php`

**Interfaces:**
- Consumes: contracts/DTOs (Task 4), `Integration` (Task 3), `config('calendar.google.*')` (Task 1)
- Produces: `CalendarAuthGoogleService implements CalendarAuthServiceContract`; `GoogleClient::request(Integration $integration): PendingRequest` (baseUrl `https://www.googleapis.com/calendar/v3`, bearer token, 401→refresh→retry-once, `->throw()`); `GoogleClient::mapException(RequestException $e): never` (409/`already_filled`-style conflicts do not exist on Google — maps 401 after failed refresh to `ProviderAuthExpiredException`, everything else to `ProviderApiFailedException`); `CalendarGoogleService{auth(), availability(), events()}` resolving sub-services from the container.

Spec references: §7 (endpoints, scopes, `access_type=offline`, `prompt=consent`, userinfo for `account_email`).

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace Tests\Feature\Domain\Calendar\Google;

use App\Domain\Calendar\Exceptions\ProviderAuthExpiredException;
use App\Domain\Calendar\Services\Google\CalendarAuthGoogleService;
use App\Domain\Integrations\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CalendarAuthGoogleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['calendar.google' => [
            'client_id' => 'test-client', 'client_secret' => 'test-secret',
            'redirect_uri' => 'https://calendar-service.test/setup/google/callback',
        ]]);
    }

    public function test_authorization_url_contains_required_parameters(): void
    {
        $url = app(CalendarAuthGoogleService::class)->authorizationUrl('state-123');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('test-client', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('state-123', $query['state']);
        $this->assertSame(
            'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy',
            $query['scope'],
        );
    }

    public function test_exchange_code_returns_tokens_and_account_email(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3599, 'token_type' => 'Bearer',
            ]),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => 'attorney@gmail.com']),
        ]);

        $tokens = app(CalendarAuthGoogleService::class)->exchangeCode('auth-code');

        $this->assertSame('at-1', $tokens->accessToken);
        $this->assertSame('rt-1', $tokens->refreshToken);
        $this->assertSame('attorney@gmail.com', $tokens->accountEmail);
        $this->assertTrue($tokens->expiresAt->isFuture());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/token')
            && $request['grant_type'] === 'authorization_code' && $request['code'] === 'auth-code');
    }

    public function test_refresh_persists_new_access_token_and_keeps_refresh_token(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-2', 'expires_in' => 3599])]);
        $integration = Integration::factory()->create(['api_token' => 'old', 'refresh_token' => 'rt-1']);

        app(CalendarAuthGoogleService::class)->refreshTokens($integration);

        $integration->refresh();
        $this->assertSame('at-2', $integration->api_token);
        $this->assertSame('rt-1', $integration->refresh_token); // Google may omit it; keep existing
        $this->assertTrue($integration->expires_at->isFuture());
    }

    public function test_refresh_failure_flags_reauth_and_throws(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $integration = Integration::factory()->create(['refresh_token' => 'rt-dead']);

        try {
            app(CalendarAuthGoogleService::class)->refreshTokens($integration);
            $this->fail('Expected ProviderAuthExpiredException');
        } catch (ProviderAuthExpiredException) {
            $this->assertTrue($integration->fresh()->requiresReauth());
        }
    }

    public function test_refresh_without_refresh_token_throws(): void
    {
        $integration = Integration::factory()->create(['refresh_token' => null]);
        $this->expectException(ProviderAuthExpiredException::class);
        app(CalendarAuthGoogleService::class)->refreshTokens($integration);
    }
}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement**

`CalendarAuthGoogleService.php`:

```php
<?php

namespace App\Domain\Calendar\Services\Google;

use App\Domain\Calendar\Contracts\Services\CalendarAuthServiceContract;
use App\Domain\Calendar\Data\OAuthTokensData;
use App\Domain\Calendar\Exceptions\ProviderApiFailedException;
use App\Domain\Calendar\Exceptions\ProviderAuthExpiredException;
use App\Domain\Integrations\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class CalendarAuthGoogleService implements CalendarAuthServiceContract
{
    protected const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    protected const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    protected const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    protected const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    protected const SCOPES = [
        'openid',
        'email',
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/calendar.freebusy',
    ];

    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('calendar.google.client_id'),
            'redirect_uri' => config('calendar.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): OAuthTokensData
    {
        try {
            $response = Http::asForm()->throw()->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => config('calendar.google.client_id'),
                'client_secret' => config('calendar.google.client_secret'),
                'redirect_uri' => config('calendar.google.redirect_uri'),
            ]);

            $email = Http::withToken($response->json('access_token'))
                ->throw()->get(self::USERINFO_URL)->json('email');
        } catch (RequestException $exception) {
            throw new ProviderApiFailedException('Google token exchange failed: '.$exception->getMessage(), previous: $exception);
        }

        return new OAuthTokensData(
            accessToken: $response->json('access_token'),
            refreshToken: $response->json('refresh_token'),
            expiresAt: CarbonImmutable::now()->addSeconds((int) $response->json('expires_in')),
            accountEmail: (string) $email,
        );
    }

    public function refreshTokens(Integration $integration): void
    {
        if (blank($integration->refresh_token)) {
            $integration->flagReauthRequired();

            throw new ProviderAuthExpiredException('No refresh token stored; tenant must reconnect Google.');
        }

        try {
            $response = Http::asForm()->throw()->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $integration->refresh_token,
                'client_id' => config('calendar.google.client_id'),
                'client_secret' => config('calendar.google.client_secret'),
            ]);
        } catch (RequestException $exception) {
            $integration->flagReauthRequired();

            throw new ProviderAuthExpiredException('Google token refresh failed; tenant must reconnect.', previous: $exception);
        }

        $integration->update([
            'api_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token') ?? $integration->refresh_token,
            'expires_at' => now()->addSeconds((int) $response->json('expires_in')),
        ]);
        $integration->clearReauthFlag();
    }

    public function revoke(Integration $integration): void
    {
        Http::asForm()->post(self::REVOKE_URL, ['token' => $integration->api_token]);
        // Best-effort: a failed revoke must not block disconnecting locally (spec §7).
    }
}
```

`GoogleClient.php`:

```php
<?php

namespace App\Domain\Calendar\Services\Google;

use App\Domain\Calendar\Exceptions\ProviderApiFailedException;
use App\Domain\Calendar\Exceptions\ProviderAuthExpiredException;
use App\Domain\Integrations\Integration;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class GoogleClient
{
    // Trailing slash matters: Guzzle base_uri drops the /calendar/v3 path segment when a
    // request path starts with "/" — so BASE_URL ends with "/" and all paths are relative.
    public const BASE_URL = 'https://www.googleapis.com/calendar/v3/';

    public function __construct(protected CalendarAuthGoogleService $auth)
    {
    }

    public function request(Integration $integration): PendingRequest
    {
        return Http::withToken($this->freshAccessToken($integration))
            ->baseUrl(self::BASE_URL)
            ->throw()
            ->retry(2, 100, function (Exception $exception, $request) use ($integration): bool {
                if ($exception instanceof RequestException && $exception->response?->status() === 401) {
                    $this->auth->refreshTokens($integration); // throws ProviderAuthExpiredException on failure
                    $request->withToken($integration->api_token);

                    return true;
                }

                return false;
            }, throw: true);
    }

    protected function freshAccessToken(Integration $integration): string
    {
        if ($integration->tokenIsExpired()) {
            $this->auth->refreshTokens($integration);
        }

        return (string) $integration->api_token;
    }

    public function mapException(RequestException $exception, string $method): never
    {
        logger()->error('[Calendar] [Google] API request failed', [
            'method' => $method,
            'status' => $exception->response?->status(),
            'body' => $exception->response?->body(),
        ]);

        throw new ProviderApiFailedException("Google {$method} failed: ".$exception->getMessage(), previous: $exception);
    }
}
```

`CalendarGoogleService.php` (replace placeholder):

```php
<?php

namespace App\Domain\Calendar\Services\Google;

use App\Domain\Calendar\Contracts\Services\CalendarAuthServiceContract;
use App\Domain\Calendar\Contracts\Services\CalendarAvailabilityServiceContract;
use App\Domain\Calendar\Contracts\Services\CalendarEventServiceContract;
use App\Domain\Calendar\Contracts\Services\CalendarGatewayContract;

class CalendarGoogleService implements CalendarGatewayContract
{
    public function auth(): CalendarAuthServiceContract
    {
        return app(CalendarAuthGoogleService::class);
    }

    public function availability(): CalendarAvailabilityServiceContract
    {
        return app(CalendarAvailabilityGoogleService::class); // implemented in Task 6
    }

    public function events(): CalendarEventServiceContract
    {
        return app(CalendarEventGoogleService::class); // implemented in Task 6
    }
}
```

(For this task, create `CalendarAvailabilityGoogleService`/`CalendarEventGoogleService` as stubs implementing their contracts with bodies `throw new ProviderApiFailedException('pending Task 6');` — Task 6 replaces them.)

- [ ] **Step 4: Run → PASS.** **Step 5: Commit** — `git commit -m "feat: Google OAuth service and HTTP client"`

---

### Task 6: Google availability + events services

**Files:**
- Modify (replace stubs): `app/Domain/Calendar/Services/Google/CalendarAvailabilityGoogleService.php`, `CalendarEventGoogleService.php`
- Test: `tests/Feature/Domain/Calendar/Google/CalendarAvailabilityGoogleServiceTest.php`, `CalendarEventGoogleServiceTest.php`

**Interfaces:**
- Consumes: `GoogleClient` (Task 5), DTOs (Task 4). Integration `data.calendar_id` (default `'primary'`).
- Produces: working `busyBlocks` / `create` / `delete` / `exists` per contracts. `create` payload per spec §7 (summary/description/start/end with IANA `timeZone`/attendees/`extendedProperties.private.booking_uuid`, query `sendUpdates=all`).

- [ ] **Step 1: Write failing tests**

`CalendarAvailabilityGoogleServiceTest.php`:

```php
public function test_busy_blocks_are_parsed_from_freebusy_response(): void
{
    Http::fake(['www.googleapis.com/calendar/v3/freeBusy' => Http::response([
        'calendars' => ['primary' => ['busy' => [
            ['start' => '2026-09-14T14:00:00Z', 'end' => '2026-09-14T15:00:00Z'],
        ]]],
    ])]);
    $integration = Integration::factory()->create();

    $blocks = app(CalendarAvailabilityGoogleService::class)->busyBlocks(
        $integration,
        CarbonImmutable::parse('2026-09-14T00:00:00Z'),
        CarbonImmutable::parse('2026-09-15T00:00:00Z'),
    );

    $this->assertCount(1, $blocks);
    $this->assertTrue($blocks[0]->start->equalTo('2026-09-14T14:00:00Z'));
    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && $r['items'] === [['id' => 'primary']]
        && $r['timeMin'] === '2026-09-14T00:00:00+00:00');
}

public function test_freebusy_calendar_errors_throw(): void
{
    Http::fake(['www.googleapis.com/calendar/v3/freeBusy' => Http::response([
        'calendars' => ['primary' => ['busy' => [], 'errors' => [['reason' => 'notFound']]]],
    ])]);

    $this->expectException(ProviderApiFailedException::class);
    app(CalendarAvailabilityGoogleService::class)->busyBlocks(Integration::factory()->create(), CarbonImmutable::now(), CarbonImmutable::now()->addDay());
}

public function test_401_triggers_token_refresh_and_replay(): void
{
    Http::fakeSequence('www.googleapis.com/calendar/v3/freeBusy')
        ->push(['error' => 'unauthorized'], 401)
        ->push(['calendars' => ['primary' => ['busy' => []]]], 200);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-new', 'expires_in' => 3599])]);
    $integration = Integration::factory()->create(['api_token' => 'at-stale', 'refresh_token' => 'rt-1', 'expires_at' => now()->addHour()]);

    $blocks = app(CalendarAvailabilityGoogleService::class)->busyBlocks($integration, CarbonImmutable::now(), CarbonImmutable::now()->addDay());

    $this->assertSame([], $blocks);
    $this->assertSame('at-new', $integration->fresh()->api_token);
}
```

`CalendarEventGoogleServiceTest.php`:

```php
public function test_create_sends_spec_payload_and_returns_event_data(): void
{
    Http::fake(['www.googleapis.com/calendar/v3/calendars/primary/events?*' => Http::response([
        'id' => 'evt-1', 'htmlLink' => 'https://calendar.google.com/event?eid=abc',
    ])]);
    $integration = Integration::factory()->create();
    $draft = new CalendarEventDraftData(
        summary: 'Consultation: Jane Doe', description: "Booked via ULH.\nPhone: +15550001111",
        start: CarbonImmutable::parse('2026-09-14T14:00:00Z'), end: CarbonImmutable::parse('2026-09-14T14:30:00Z'),
        timezone: 'America/New_York', attendeeEmail: 'jane@example.com', attendeeName: 'Jane Doe', bookingUuid: 'uuid-1',
    );

    $event = app(CalendarEventGoogleService::class)->create($integration, $draft);

    $this->assertSame('evt-1', $event->id);
    Http::assertSent(function ($r) {
        return str_contains($r->url(), 'sendUpdates=all')
            && $r['attendees'] === [['email' => 'jane@example.com', 'displayName' => 'Jane Doe']]
            && $r['extendedProperties'] === ['private' => ['booking_uuid' => 'uuid-1']]
            && $r['start'] === ['dateTime' => '2026-09-14T14:00:00+00:00', 'timeZone' => 'America/New_York'];
    });
}

public function test_delete_treats_404_and_410_as_already_gone(): void
{
    Http::fake(['www.googleapis.com/calendar/v3/calendars/primary/events/evt-gone?*' => Http::response(null, 410)]);
    app(CalendarEventGoogleService::class)->delete(Integration::factory()->create(), 'evt-gone');
    $this->assertTrue(true); // no exception
}

public function test_exists_is_false_for_404_and_for_cancelled_status(): void
{
    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/primary/events/evt-404' => Http::response(null, 404),
        'www.googleapis.com/calendar/v3/calendars/primary/events/evt-cancelled' => Http::response(['id' => 'evt-cancelled', 'status' => 'cancelled']),
        'www.googleapis.com/calendar/v3/calendars/primary/events/evt-live' => Http::response(['id' => 'evt-live', 'status' => 'confirmed']),
    ]);
    $service = app(CalendarEventGoogleService::class);
    $integration = Integration::factory()->create();

    $this->assertFalse($service->exists($integration, 'evt-404'));
    $this->assertFalse($service->exists($integration, 'evt-cancelled'));
    $this->assertTrue($service->exists($integration, 'evt-live'));
}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement**

`CalendarAvailabilityGoogleService.php`:

```php
<?php

namespace App\Domain\Calendar\Services\Google;

use App\Domain\Calendar\Contracts\Services\CalendarAvailabilityServiceContract;
use App\Domain\Calendar\Data\BusyBlockData;
use App\Domain\Calendar\Exceptions\ProviderApiFailedException;
use App\Domain\Integrations\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;

class CalendarAvailabilityGoogleService implements CalendarAvailabilityServiceContract
{
    public function __construct(protected GoogleClient $client)
    {
    }

    public function busyBlocks(Integration $integration, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $calendarId = data_get($integration->data, 'calendar_id', 'primary');

        try {
            $response = $this->client->request($integration)->post('freeBusy', [
                'timeMin' => $from->toIso8601String(),
                'timeMax' => $to->toIso8601String(),
                'items' => [['id' => $calendarId]],
            ]);
        } catch (RequestException $exception) {
            $this->client->mapException($exception, 'busyBlocks');
        }

        $calendar = $response->json("calendars.{$calendarId}", []);

        if (filled($calendar['errors'] ?? [])) {
            throw new ProviderApiFailedException('Google freeBusy returned calendar errors: '.json_encode($calendar['errors']));
        }

        return array_map(
            fn (array $block): BusyBlockData => new BusyBlockData(
                CarbonImmutable::parse($block['start'])->utc(),
                CarbonImmutable::parse($block['end'])->utc(),
            ),
            $calendar['busy'] ?? [],
        );
    }
}
```

`CalendarEventGoogleService.php`:

```php
<?php

namespace App\Domain\Calendar\Services\Google;

use App\Domain\Calendar\Contracts\Services\CalendarEventServiceContract;
use App\Domain\Calendar\Data\CalendarEventDraftData;
use App\Domain\Calendar\Data\ProviderEventData;
use App\Domain\Integrations\Integration;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Response;

class CalendarEventGoogleService implements CalendarEventServiceContract
{
    public function __construct(protected GoogleClient $client)
    {
    }

    public function create(Integration $integration, CalendarEventDraftData $draft): ProviderEventData
    {
        try {
            $response = $this->client->request($integration)->post(
                sprintf('calendars/%s/events?sendUpdates=all', $this->calendarId($integration)),
                [
                    'summary' => $draft->summary,
                    'description' => $draft->description,
                    'start' => ['dateTime' => $draft->start->toIso8601String(), 'timeZone' => $draft->timezone],
                    'end' => ['dateTime' => $draft->end->toIso8601String(), 'timeZone' => $draft->timezone],
                    'attendees' => [['email' => $draft->attendeeEmail, 'displayName' => $draft->attendeeName]],
                    'extendedProperties' => ['private' => ['booking_uuid' => $draft->bookingUuid]],
                ],
            );
        } catch (RequestException $exception) {
            $this->client->mapException($exception, 'createEvent');
        }

        return new ProviderEventData((string) $response->json('id'), $response->json('htmlLink'));
    }

    public function delete(Integration $integration, string $providerEventId): void
    {
        try {
            $this->client->request($integration)->delete(
                sprintf('calendars/%s/events/%s?sendUpdates=all', $this->calendarId($integration), $providerEventId),
            );
        } catch (RequestException $exception) {
            if (in_array($exception->response?->status(), [Response::HTTP_NOT_FOUND, Response::HTTP_GONE], true)) {
                return; // already gone — nothing to delete
            }

            $this->client->mapException($exception, 'deleteEvent');
        }
    }

    public function exists(Integration $integration, string $providerEventId): bool
    {
        try {
            $response = $this->client->request($integration)->get(
                sprintf('calendars/%s/events/%s', $this->calendarId($integration), $providerEventId),
            );
        } catch (RequestException $exception) {
            if (in_array($exception->response?->status(), [Response::HTTP_NOT_FOUND, Response::HTTP_GONE], true)) {
                return false;
            }

            $this->client->mapException($exception, 'eventExists');
        }

        return $response->json('status') !== 'cancelled';
    }

    protected function calendarId(Integration $integration): string
    {
        return data_get($integration->data, 'calendar_id', 'primary');
    }
}
```

- [ ] **Step 4: Run → PASS** (`php artisan test --filter=Google`). **Step 5: Commit** — `git commit -m "feat: Google freeBusy, event create/delete/exists services"`

---

### Task 7: AvailabilityCalculator

**Files:**
- Create: `app/Domain/Bookings/AvailabilityCalculator.php`
- Test: `tests/Unit/Domain/Bookings/AvailabilityCalculatorTest.php`

**Interfaces:**
- Consumes: `BookingSettings` (Task 2), `BusyBlockData` (Task 4)
- Produces:

```php
/** @param array<int, BusyBlockData> $busyBlocks  @return array<int, CarbonImmutable> UTC slot starts */
public function slots(BookingSettings $settings, string $timezone, CarbonImmutable $now, string $fromDate, string $toDate, array $busyBlocks): array;

/** @param array<int, BusyBlockData> $busyBlocks */
public function isBookable(BookingSettings $settings, string $timezone, CarbonImmutable $now, CarbonImmutable $startUtc, array $busyBlocks): bool;
```

`$fromDate`/`$toDate` are `Y-m-d` strings **in the tenant's timezone**. Pure class — no facades, no queries, no clock reads.

- [ ] **Step 1: Write failing tests** (use `BookingSettings::factory()->make()` — unit test, no DB; `RefreshDatabase` not needed)

```php
<?php

namespace Tests\Unit\Domain\Bookings;

use App\Domain\Bookings\AvailabilityCalculator;
use App\Domain\Calendar\Data\BusyBlockData;
use App\Domain\Tenants\BookingSettings;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AvailabilityCalculatorTest extends TestCase
{
    private AvailabilityCalculator $calculator;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new AvailabilityCalculator();
        $this->now = CarbonImmutable::parse('2026-09-10T00:00:00Z'); // Thursday
    }

    private function settings(array $overrides = []): BookingSettings
    {
        return BookingSettings::factory()->make(array_merge([
            'available_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'office_starts_at' => '09:00:00',
            'office_ends_at' => '17:00:00',
            'meeting_length_minutes' => 30,
        ], $overrides));
    }

    public function test_generates_grid_within_office_hours_in_tenant_timezone(): void
    {
        // Monday 2026-09-14, America/New_York (UTC-4): 09:00–17:00 → 16 half-hour slots
        $slots = $this->calculator->slots($this->settings(), 'America/New_York', $this->now, '2026-09-14', '2026-09-14', []);

        $this->assertCount(16, $slots);
        $this->assertTrue($slots[0]->equalTo('2026-09-14T13:00:00Z'));      // 09:00 EDT
        $this->assertTrue(end($slots)->equalTo('2026-09-14T20:30:00Z'));    // 16:30 EDT — ends exactly at close
    }

    public function test_unavailable_weekday_yields_no_slots(): void
    {
        $slots = $this->calculator->slots($this->settings(), 'America/New_York', $this->now, '2026-09-13', '2026-09-13', []); // Sunday
        $this->assertSame([], $slots);
    }

    public function test_slot_must_end_by_close(): void
    {
        // 60-minute meetings, close 17:00 → last slot starts 16:00
        $slots = $this->calculator->slots($this->settings(['meeting_length_minutes' => 60]), 'America/New_York', $this->now, '2026-09-14', '2026-09-14', []);
        $this->assertTrue(end($slots)->equalTo('2026-09-14T20:00:00Z')); // 16:00 EDT
    }

    public function test_past_slots_are_dropped(): void
    {
        $now = CarbonImmutable::parse('2026-09-14T15:00:00Z'); // 11:00 EDT that same Monday
        $slots = $this->calculator->slots($this->settings(), 'America/New_York', $now, '2026-09-14', '2026-09-14', []);
        $this->assertTrue($slots[0]->equalTo('2026-09-14T15:30:00Z')); // 11:30 EDT is first future slot
    }

    public function test_busy_overlap_kills_slot_but_touching_edges_do_not(): void
    {
        // Busy 14:00–15:00Z (10:00–11:00 EDT) kills the 10:00 and 10:30 slots only.
        $busy = [new BusyBlockData(CarbonImmutable::parse('2026-09-14T14:00:00Z'), CarbonImmutable::parse('2026-09-14T15:00:00Z'))];
        $slots = $this->calculator->slots($this->settings(), 'America/New_York', $this->now, '2026-09-14', '2026-09-14', $busy);

        $starts = array_map(fn ($s) => $s->toIso8601ZuluString(), $slots);
        $this->assertNotContains('2026-09-14T14:00:00Z', $starts);
        $this->assertNotContains('2026-09-14T14:30:00Z', $starts);
        $this->assertContains('2026-09-14T13:30:00Z', $starts); // ends exactly 14:00 — touching, not overlap
        $this->assertContains('2026-09-14T15:00:00Z', $starts); // starts exactly at busy end
    }

    public function test_dst_fall_back_day_yields_wall_clock_hours(): void
    {
        // US DST ends 2026-11-01 (America/New_York). 09:00–17:00 wall clock = 16 slots regardless.
        $slots = $this->calculator->slots($this->settings(['available_days' => ['sunday']]), 'America/New_York', $this->now, '2026-11-01', '2026-11-01', []);
        $this->assertCount(16, $slots);
        $this->assertTrue($slots[0]->equalTo('2026-11-01T14:00:00Z')); // 09:00 EST (UTC-5 after fall-back)
    }

    public function test_is_bookable_matches_grid_and_rejects_off_grid_and_busy(): void
    {
        $settings = $this->settings();
        $tz = 'America/New_York';

        $this->assertTrue($this->calculator->isBookable($settings, $tz, $this->now, CarbonImmutable::parse('2026-09-14T14:00:00Z'), []));
        $this->assertFalse($this->calculator->isBookable($settings, $tz, $this->now, CarbonImmutable::parse('2026-09-14T14:10:00Z'), [])); // off-grid
        $this->assertFalse($this->calculator->isBookable($settings, $tz, $this->now, CarbonImmutable::parse('2026-09-13T14:00:00Z'), [])); // Sunday
        $busy = [new BusyBlockData(CarbonImmutable::parse('2026-09-14T14:00:00Z'), CarbonImmutable::parse('2026-09-14T14:30:00Z'))];
        $this->assertFalse($this->calculator->isBookable($settings, $tz, $this->now, CarbonImmutable::parse('2026-09-14T14:00:00Z'), $busy));
    }
}
```

- [ ] **Step 2: Run → FAIL** (`php artisan test --filter=AvailabilityCalculatorTest`).

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Domain\Bookings;

use App\Domain\Calendar\Data\BusyBlockData;
use App\Domain\Tenants\BookingSettings;
use Carbon\CarbonImmutable;

class AvailabilityCalculator
{
    /**
     * @param  array<int, BusyBlockData>  $busyBlocks
     * @return array<int, CarbonImmutable> UTC slot start times
     */
    public function slots(BookingSettings $settings, string $timezone, CarbonImmutable $now, string $fromDate, string $toDate, array $busyBlocks): array
    {
        $slots = [];
        $length = $settings->meeting_length_minutes;
        $day = CarbonImmutable::parse($fromDate, $timezone)->startOfDay();
        $lastDay = CarbonImmutable::parse($toDate, $timezone)->startOfDay();

        while ($day->lessThanOrEqualTo($lastDay)) {
            if (in_array(strtolower($day->englishDayOfWeek), $settings->available_days, true)) {
                $cursor = $day->setTimeFromTimeString($settings->office_starts_at);
                $close = $day->setTimeFromTimeString($settings->office_ends_at);

                while ($cursor->addMinutes($length)->lessThanOrEqualTo($close)) {
                    $startUtc = $cursor->utc();
                    $endUtc = $startUtc->addMinutes($length);

                    if ($startUtc->greaterThan($now) && ! $this->overlapsAny($startUtc, $endUtc, $busyBlocks)) {
                        $slots[] = $startUtc;
                    }

                    $cursor = $cursor->addMinutes($length);
                }
            }

            $day = $day->addDay();
        }

        return $slots;
    }

    /** @param array<int, BusyBlockData> $busyBlocks */
    public function isBookable(BookingSettings $settings, string $timezone, CarbonImmutable $now, CarbonImmutable $startUtc, array $busyBlocks): bool
    {
        $date = $startUtc->setTimezone($timezone)->toDateString();

        foreach ($this->slots($settings, $timezone, $now, $date, $date, $busyBlocks) as $slot) {
            if ($slot->equalTo($startUtc)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, BusyBlockData> $busyBlocks */
    protected function overlapsAny(CarbonImmutable $start, CarbonImmutable $end, array $busyBlocks): bool
    {
        foreach ($busyBlocks as $block) {
            if ($start->lessThan($block->end) && $block->start->lessThan($end)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run → PASS.** **Step 5: Commit** — `git commit -m "feat: pure availability calculator"`

---

### Task 8: Booking model + availability API endpoint

**Files:**
- Create: `database/migrations/xxxx_create_bookings_table.php`, `app/Domain/Bookings/Booking.php`, `app/Domain/Bookings/Enums/BookingStatus.php`, `app/Domain/Bookings/Enums/CancellationSource.php`, `database/factories/Domain/Bookings/BookingFactory.php`
- Create: `app/Http/Controllers/Api/AvailabilityController.php`, `app/Http/Requests/AvailabilityRequest.php`
- Modify: `routes/api.php` (create via `install:api` if absent — `php artisan install:api` then remove sanctum routes; or add `->withRouting(api: …)` in `bootstrap/app.php`)
- Test: `tests/Feature/Api/AvailabilityTest.php`

**Interfaces:**
- Consumes: calculator (7), gateway manager + mock (4), `Tenant`/`BookingSettings` (2), `Integration` (3)
- Produces: `Booking` model — `{uuid, tenant_id, provider, provider_event_id, provider_event_link, lead_first_name, lead_last_name, lead_email, lead_phone, lead_timezone, tracking: array, starts_at: CarbonImmutable-castable datetime, ends_at, status: string, canceled_at, cancellation_source, manage_token, rescheduled_from_booking_id}`, relation `tenant()`, scope `confirmed()` (`where status = confirmed`), helper `asBusyBlock(): BusyBlockData`, `leadFullName(): string`. Enums `BookingStatus{Confirmed='confirmed', Canceled='canceled'}`, `CancellationSource{Lead='lead', Provider='provider'}`. Route name `api.availability`: `GET /api/v1/tenants/{tenant}/availability` → `{"collection":[{"status":"available","start_time","end_time"}]}`.

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace Tests\Feature\Api;

use App\Domain\Bookings\Booking;
use App\Domain\Calendar\Data\BusyBlockData;
use App\Domain\Calendar\Services\Mock\CalendarMockService;
use App\Domain\Integrations\Integration;
use App\Domain\Tenants\BookingSettings;
use App\Domain\Tenants\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['calendar.gateway' => 'mock']);
        $this->travelTo('2026-09-10T00:00:00Z');
        $this->tenant = Tenant::factory()->create(['timezone' => 'America/New_York']);
        BookingSettings::factory()->for($this->tenant)->create();
        Integration::factory()->for($this->tenant)->create();
    }

    public function test_returns_available_slots_shaped_like_calendly(): void
    {
        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/availability?from=2026-09-14&to=2026-09-14");

        $response->assertOk()
            ->assertJsonPath('collection.0.status', 'available')
            ->assertJsonPath('collection.0.start_time', '2026-09-14T13:00:00Z')
            ->assertJsonPath('collection.0.end_time', '2026-09-14T13:30:00Z')
            ->assertJsonCount(16, 'collection');
    }

    public function test_provider_busy_and_own_confirmed_bookings_are_excluded(): void
    {
        app(CalendarMockService::class)->busy = [
            new BusyBlockData(CarbonImmutable::parse('2026-09-14T13:00:00Z'), CarbonImmutable::parse('2026-09-14T13:30:00Z')),
        ];
        Booking::factory()->for($this->tenant)->create([
            'starts_at' => '2026-09-14T14:00:00Z', 'ends_at' => '2026-09-14T14:30:00Z',
        ]);
        Booking::factory()->for($this->tenant)->canceled()->create([
            'starts_at' => '2026-09-14T15:00:00Z', 'ends_at' => '2026-09-14T15:30:00Z',
        ]);

        $starts = collect($this->getJson("/api/v1/tenants/{$this->tenant->id}/availability?from=2026-09-14&to=2026-09-14")
            ->json('collection'))->pluck('start_time');

        $this->assertNotContains('2026-09-14T13:00:00Z', $starts); // provider busy
        $this->assertNotContains('2026-09-14T14:00:00Z', $starts); // own confirmed
        $this->assertContains('2026-09-14T15:00:00Z', $starts);    // canceled booking frees the slot
    }

    public function test_range_over_seven_days_is_rejected(): void
    {
        $this->getJson("/api/v1/tenants/{$this->tenant->id}/availability?from=2026-09-14&to=2026-09-21")
            ->assertStatus(422);
    }

    public function test_missing_integration_is_rejected(): void
    {
        $bare = Tenant::factory()->create();
        BookingSettings::factory()->for($bare)->create();

        $this->getJson("/api/v1/tenants/{$bare->id}/availability?from=2026-09-14&to=2026-09-14")
            ->assertStatus(422)
            ->assertJsonPath('message', 'No calendar integration is connected for this tenant.');
    }
}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement**

Migration `create_bookings_table`:

```php
Schema::create('bookings', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->string('provider');
    $table->string('provider_event_id')->nullable();
    $table->string('provider_event_link')->nullable();
    $table->string('lead_first_name');
    $table->string('lead_last_name');
    $table->string('lead_email');
    $table->string('lead_phone');
    $table->string('lead_timezone');
    $table->json('tracking')->nullable();
    $table->timestamp('starts_at');
    $table->timestamp('ends_at');
    $table->string('status')->default('confirmed');
    $table->timestamp('canceled_at')->nullable();
    $table->string('cancellation_source')->nullable();
    $table->string('manage_token', 64)->unique();
    $table->foreignId('rescheduled_from_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
    $table->timestamps();
    $table->index(['tenant_id', 'starts_at']);
});
```

`Booking.php`:

```php
<?php

namespace App\Domain\Bookings;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Calendar\Data\BusyBlockData;
use App\Domain\Tenants\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'tenant_id', 'provider', 'provider_event_id', 'provider_event_link',
        'lead_first_name', 'lead_last_name', 'lead_email', 'lead_phone', 'lead_timezone',
        'tracking', 'starts_at', 'ends_at', 'status', 'canceled_at', 'cancellation_source',
        'manage_token', 'rescheduled_from_booking_id',
    ];

    protected $casts = [
        'tracking' => 'array',
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'canceled_at' => 'immutable_datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', BookingStatus::Confirmed->value);
    }

    public function asBusyBlock(): BusyBlockData
    {
        return new BusyBlockData(CarbonImmutable::parse($this->starts_at), CarbonImmutable::parse($this->ends_at));
    }

    public function leadFullName(): string
    {
        return trim($this->lead_first_name.' '.$this->lead_last_name);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Domain\Bookings\BookingFactory::new();
    }
}
```

Factory `definition()` + `canceled()` state:

```php
public function definition(): array
{
    return [
        'uuid' => (string) Str::uuid(),
        'tenant_id' => Tenant::factory(),
        'provider' => 'google',
        'provider_event_id' => 'evt-'.fake()->uuid(),
        'lead_first_name' => 'Jane', 'lead_last_name' => 'Doe',
        'lead_email' => 'jane@example.com', 'lead_phone' => '+15550001111',
        'lead_timezone' => 'America/Chicago',
        'tracking' => ['utm_source' => 'ULH', 'utm_content' => 'encrypted-lead-token'],
        'starts_at' => now()->addDay()->setTime(14, 0),
        'ends_at' => now()->addDay()->setTime(14, 30),
        'status' => BookingStatus::Confirmed->value,
        'manage_token' => Str::random(64),
    ];
}

public function canceled(): static
{
    return $this->state(fn () => [
        'status' => BookingStatus::Canceled->value,
        'canceled_at' => now(),
        'cancellation_source' => CancellationSource::Lead->value,
    ]);
}
```

`AvailabilityRequest.php`:

```php
public function rules(): array
{
    return [
        'from' => ['required', 'date_format:Y-m-d'],
        'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
    ];
}

public function after(): array
{
    return [function ($validator) {
        if ($this->filled(['from', 'to'])
            && CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($this->input('to'))) > 6) {
            $validator->errors()->add('to', 'The range may not exceed 7 days.');
        }
    }];
}
```

`AvailabilityController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Domain\Bookings\AvailabilityCalculator;
use App\Domain\Bookings\Booking;
use App\Domain\Calendar\CalendarGatewayManager;
use App\Domain\Tenants\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\AvailabilityRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class AvailabilityController extends Controller
{
    public function __invoke(AvailabilityRequest $request, Tenant $tenant, CalendarGatewayManager $manager, AvailabilityCalculator $calculator): JsonResponse
    {
        $integration = $tenant->integration;

        abort_if($integration === null, 422, 'No calendar integration is connected for this tenant.');

        $settings = $tenant->bookingSettings;
        $timezone = $tenant->timezone;
        $fromUtc = CarbonImmutable::parse($request->input('from'), $timezone)->startOfDay()->utc();
        $toUtc = CarbonImmutable::parse($request->input('to'), $timezone)->endOfDay()->utc();

        $busy = $manager->for($integration)->availability()->busyBlocks($integration, $fromUtc, $toUtc);

        $ownBookings = Booking::query()->confirmed()
            ->where('tenant_id', $tenant->id)
            ->where('starts_at', '<', $toUtc)
            ->where('ends_at', '>', $fromUtc)
            ->get()
            ->map(fn (Booking $booking) => $booking->asBusyBlock())
            ->all();

        $slots = $calculator->slots(
            $settings, $timezone, CarbonImmutable::now(),
            $request->input('from'), $request->input('to'),
            [...$busy, ...$ownBookings],
        );

        return response()->json([
            'collection' => array_map(fn (CarbonImmutable $start) => [
                'status' => 'available',
                'start_time' => $start->toIso8601ZuluString(),
                'end_time' => $start->addMinutes($settings->meeting_length_minutes)->toIso8601ZuluString(),
            ], $slots),
        ]);
    }
}
```

`routes/api.php` (register in `bootstrap/app.php` `withRouting(api: __DIR__.'/../routes/api.php', apiPrefix: 'api')` if not present):

```php
Route::prefix('v1')->name('api.')->group(function () {
    Route::get('tenants/{tenant}/availability', AvailabilityController::class)->name('availability');
});
```

Ensure abort(422, message) renders `{"message": …}` for JSON (Laravel default does).

- [ ] **Step 4: Run → PASS** (`php artisan test --filter=AvailabilityTest`). **Step 5: Commit** — `git commit -m "feat: booking model and availability API"`

---

### Task 9: Webhooks — endpoints, deliveries, payload, jobs, sink

**Files:**
- Create: migrations `xxxx_create_webhook_endpoints_table.php`, `xxxx_create_webhook_deliveries_table.php`
- Create: `app/Domain/Webhooks/WebhookEndpoint.php`, `WebhookDelivery.php`, `Enums/WebhookEvent.php`, `BookingWebhookPayload.php`, `SendBookingWebhooksAction.php`, `Jobs/SendWebhookJob.php`, `WebhookSignature.php`
- Create: `app/Http/Controllers/Demo/WebhookSinkController.php`; route `POST /demo/webhook-sink` (name `demo.webhook-sink`, CSRF-exempt via `bootstrap/app.php` `validateCsrfTokens(except: ['demo/webhook-sink'])`)
- Create: factories for both models
- Modify: `DemoSeeder` (seed sink endpoint), `Tenant` (add `webhookEndpoints(): HasMany`)
- Test: `tests/Feature/Domain/Webhooks/WebhookDispatchTest.php`

**Interfaces:**
- Consumes: `Booking` (8), `Tenant` (2)
- Produces: `WebhookEvent{BookingCreated='booking.created', BookingCanceled='booking.canceled'}`; `BookingWebhookPayload::for(Booking $booking, WebhookEvent $event): array` (spec §10 envelope); `SendBookingWebhooksAction::execute(Booking $booking, WebhookEvent $event): void` (creates a `WebhookDelivery` per matching active endpoint, dispatches `SendWebhookJob($delivery)`); `WebhookSignature::header(string $secret, string $body, int $timestamp): string` returning `t={ts},v1={hex}` and `::verify(string $secret, string $body, string $header): bool`; models `WebhookEndpoint{tenant_id, url, secret, events: array, active: bool}` + `WebhookDelivery{webhook_endpoint_id, event, payload: array, signature, response_status, attempts, delivered_at}`.

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace Tests\Feature\Domain\Webhooks;

use App\Domain\Bookings\Booking;
use App\Domain\Webhooks\BookingWebhookPayload;
use App\Domain\Webhooks\Enums\WebhookEvent;
use App\Domain\Webhooks\Jobs\SendWebhookJob;
use App\Domain\Webhooks\SendBookingWebhooksAction;
use App\Domain\Webhooks\WebhookDelivery;
use App\Domain\Webhooks\WebhookEndpoint;
use App\Domain\Webhooks\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_matches_spec_envelope(): void
    {
        $booking = Booking::factory()->create(['tracking' => ['utm_content' => 'lead-token-abc']]);

        $payload = BookingWebhookPayload::for($booking, WebhookEvent::BookingCreated);

        $this->assertSame('booking.created', $payload['event']);
        $this->assertSame("tenant:{$booking->tenant_id}", explode(':integration', $payload['created_by'])[0]);
        $this->assertSame($booking->uuid, $payload['payload']['booking']['uuid']);
        $this->assertSame($booking->starts_at->toIso8601ZuluString(), $payload['payload']['booking']['start_time']);
        $this->assertSame('lead-token-abc', $payload['payload']['tracking']['utm_content']);
        $this->assertSame('Jane', $payload['payload']['invitee']['first_name']);
        $this->assertNull($payload['payload']['booking']['cancellation']);
    }

    public function test_action_creates_delivery_rows_and_dispatches_jobs_for_matching_endpoints(): void
    {
        Queue::fake();
        $booking = Booking::factory()->create();
        WebhookEndpoint::factory()->for($booking->tenant)->create(['events' => ['booking.created', 'booking.canceled']]);
        WebhookEndpoint::factory()->for($booking->tenant)->create(['events' => ['booking.canceled']]); // no match
        WebhookEndpoint::factory()->for($booking->tenant)->create(['active' => false]);                 // inactive

        app(SendBookingWebhooksAction::class)->execute($booking, WebhookEvent::BookingCreated);

        $this->assertSame(1, WebhookDelivery::count());
        Queue::assertPushed(SendWebhookJob::class, 1);
    }

    public function test_job_signs_and_posts_and_records_response(): void
    {
        Http::fake(['receiver.test/*' => Http::response(['ok' => true], 200)]);
        $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://receiver.test/hooks', 'secret' => 'shh']);
        $delivery = WebhookDelivery::factory()->for($endpoint, 'endpoint')->create();

        (new SendWebhookJob($delivery))->handle();

        Http::assertSent(function ($request) {
            $header = $request->header('Calendar-Service-Signature')[0] ?? '';

            return $request->url() === 'https://receiver.test/hooks'
                && WebhookSignature::verify('shh', $request->body(), $header);
        });
        $delivery->refresh();
        $this->assertSame(200, $delivery->response_status);
        $this->assertNotNull($delivery->delivered_at);
    }

    public function test_sink_route_verifies_signature(): void
    {
        $this->seed(\Database\Seeders\DemoSeeder::class);
        $endpoint = WebhookEndpoint::firstOrFail();
        $body = json_encode(['event' => 'booking.created']);
        $goodHeader = WebhookSignature::header($endpoint->secret, $body, time());

        $this->call('POST', '/demo/webhook-sink', server: ['HTTP_CALENDAR_SERVICE_SIGNATURE' => $goodHeader, 'CONTENT_TYPE' => 'application/json'], content: $body)
            ->assertNoContent();

        $this->call('POST', '/demo/webhook-sink', server: ['HTTP_CALENDAR_SERVICE_SIGNATURE' => 't=1,v1=bad', 'CONTENT_TYPE' => 'application/json'], content: $body)
            ->assertStatus(400);
    }
}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement**

Migrations:

```php
Schema::create('webhook_endpoints', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->string('url');
    $table->string('secret');
    $table->json('events');
    $table->boolean('active')->default(true);
    $table->timestamps();
});

Schema::create('webhook_deliveries', function (Blueprint $table) {
    $table->id();
    $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
    $table->string('event');
    $table->json('payload');
    $table->string('signature')->nullable();
    $table->unsignedSmallInteger('response_status')->nullable();
    $table->unsignedTinyInteger('attempts')->default(0);
    $table->timestamp('delivered_at')->nullable();
    $table->timestamps();
});
```

Models: standard `HasFactory` + fillable + casts (`events` => array, `active` => bool; `payload` => array, `delivered_at` => datetime). `WebhookDelivery::endpoint(): BelongsTo` (foreign key `webhook_endpoint_id`).

`WebhookSignature.php`:

```php
<?php

namespace App\Domain\Webhooks;

class WebhookSignature
{
    public static function header(string $secret, string $body, int $timestamp): string
    {
        return sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$body, $secret));
    }

    public static function verify(string $secret, string $body, string $header): bool
    {
        if (! preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $header, $matches)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $matches[1].'.'.$body, $secret), $matches[2]);
    }
}
```

`BookingWebhookPayload.php`:

```php
<?php

namespace App\Domain\Webhooks;

use App\Domain\Bookings\Booking;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Webhooks\Enums\WebhookEvent;

class BookingWebhookPayload
{
    public static function for(Booking $booking, WebhookEvent $event): array
    {
        $integrationId = $booking->tenant->integration?->id ?? 0;

        return [
            'event' => $event->value,
            'created_by' => sprintf('tenant:%d:integration:%d', $booking->tenant_id, $integrationId),
            'payload' => [
                'booking' => [
                    'uuid' => $booking->uuid,
                    'uri' => route('api.bookings.show', $booking->uuid),
                    'start_time' => $booking->starts_at->toIso8601ZuluString(),
                    'end_time' => $booking->ends_at->toIso8601ZuluString(),
                    'status' => $booking->status,
                    'provider' => $booking->provider,
                    'rescheduled_from' => $booking->rescheduled_from_booking_id
                        ? Booking::find($booking->rescheduled_from_booking_id)?->uuid
                        : null,
                    'cancellation' => $booking->status === BookingStatus::Canceled->value
                        ? ['source' => $booking->cancellation_source, 'canceled_at' => $booking->canceled_at?->toIso8601ZuluString()]
                        : null,
                ],
                'invitee' => [
                    'first_name' => $booking->lead_first_name,
                    'last_name' => $booking->lead_last_name,
                    'email' => $booking->lead_email,
                    'phone' => $booking->lead_phone,
                    'timezone' => $booking->lead_timezone,
                ],
                'tracking' => $booking->tracking ?? [],
            ],
        ];
    }
}
```

(Route `api.bookings.show` arrives in Task 11 — for THIS task register the route now as a named placeholder in `routes/api.php`: `Route::get('bookings/{booking:uuid}', fn () => abort(501))->name('bookings.show');` — Task 11 replaces the closure with the real controller.)

`SendBookingWebhooksAction.php`:

```php
<?php

namespace App\Domain\Webhooks;

use App\Domain\Bookings\Booking;
use App\Domain\Webhooks\Enums\WebhookEvent;
use App\Domain\Webhooks\Jobs\SendWebhookJob;

class SendBookingWebhooksAction
{
    public function execute(Booking $booking, WebhookEvent $event): void
    {
        $payload = BookingWebhookPayload::for($booking, $event);

        $booking->tenant->webhookEndpoints()
            ->where('active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => in_array($event->value, $endpoint->events, true))
            ->each(function (WebhookEndpoint $endpoint) use ($event, $payload): void {
                $delivery = WebhookDelivery::create([
                    'webhook_endpoint_id' => $endpoint->id,
                    'event' => $event->value,
                    'payload' => $payload,
                ]);

                SendWebhookJob::dispatch($delivery);
            });
    }
}
```

`Jobs/SendWebhookJob.php`:

```php
<?php

namespace App\Domain\Webhooks\Jobs;

use App\Domain\Webhooks\WebhookDelivery;
use App\Domain\Webhooks\WebhookSignature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public WebhookDelivery $delivery)
    {
    }

    public function handle(): void
    {
        $body = json_encode($this->delivery->payload);
        $signature = WebhookSignature::header($this->delivery->endpoint->secret, $body, time());

        $this->delivery->update(['attempts' => $this->delivery->attempts + 1, 'signature' => $signature]);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Calendar-Service-Signature' => $signature,
        ])->withBody($body, 'application/json')->post($this->delivery->endpoint->url);

        $this->delivery->update([
            'response_status' => $response->status(),
            'delivered_at' => $response->successful() ? now() : null,
        ]);

        if (! $response->successful()) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }
}
```

`WebhookSinkController.php` (`routes/web.php`: `Route::post('demo/webhook-sink', WebhookSinkController::class)->name('demo.webhook-sink');`, CSRF-exempt):

```php
public function __invoke(Request $request): Response
{
    $verified = WebhookEndpoint::where('active', true)->get()->contains(
        fn (WebhookEndpoint $endpoint): bool => WebhookSignature::verify(
            $endpoint->secret, $request->getContent(), (string) $request->header('Calendar-Service-Signature'),
        ),
    );

    abort_unless($verified, 400, 'Invalid webhook signature.');

    return response()->noContent();
}
```

`DemoSeeder` addition:

```php
WebhookEndpoint::firstOrCreate(['tenant_id' => $tenant->id, 'url' => route('demo.webhook-sink')], [
    'secret' => Str::random(32),
    'events' => ['booking.created', 'booking.canceled'],
    'active' => true,
]);
```

Factories: endpoint — `['tenant_id' => Tenant::factory(), 'url' => 'https://receiver.test/hooks', 'secret' => Str::random(32), 'events' => ['booking.created', 'booking.canceled'], 'active' => true]`; delivery — `['webhook_endpoint_id' => WebhookEndpoint::factory(), 'event' => 'booking.created', 'payload' => ['event' => 'booking.created']]`.

- [ ] **Step 4: Run → PASS.** **Step 5: Commit** — `git commit -m "feat: outbound webhooks with HMAC signing and delivery log"`

---

### Task 10: Notifications — channels, reminders, due-reminder job

**Files:**
- Create: migration `xxxx_create_reminders_table.php`
- Create: `app/Domain/Notifications/Contracts/NotificationChannelContract.php`, `Data/NotificationMessageData.php`, `Channels/MailChannel.php`, `Channels/SmsChannel.php`, `NotificationChannelManager.php`, `Enums/NotificationChannelType.php`, `Enums/ReminderRecipient.php`, `Reminder.php`, `ReminderScheduler.php`, `BookingNotifier.php`, `Jobs/SendDueRemindersJob.php`, `Mail/BookingNotificationMail.php`, `resources/views/mail/booking-notification.blade.php`
- Create: `database/factories/Domain/Notifications/ReminderFactory.php`
- Modify: `routes/console.php` (schedule `SendDueRemindersJob` everyMinute)
- Test: `tests/Feature/Domain/Notifications/RemindersTest.php`

**Interfaces:**
- Consumes: `Booking` (8), `config('calendar.reminder_offsets_minutes')` (1)
- Produces:

```php
interface NotificationChannelContract { public function send(NotificationMessageData $message): void; }
final class NotificationMessageData {
    public function __construct(
        public readonly string $recipientName, public readonly string $recipientEmail,
        public readonly ?string $recipientPhone, public readonly string $subject,
        /** @var array<int, string> */ public readonly array $lines,
    ) {}
}
```

`NotificationChannelManager::channel(NotificationChannelType $type): NotificationChannelContract`; `ReminderScheduler::scheduleFor(Booking $booking): void` (rows for lead+tenant at each offset, skipping past times) and `::cancelFor(Booking $booking): void` (deletes unsent rows); `BookingNotifier::sendConfirmation(Booking): void`, `::sendCancellation(Booking, CancellationSource $source): void` (notifies the party who did NOT initiate; provider-source notifies the lead only), `::sendReminder(Reminder): void`; `Reminder{booking_id, recipient, channel, send_at, sent_at}`; `SendDueRemindersJob` claims due rows atomically then sends.

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace Tests\Feature\Domain\Notifications;

use App\Domain\Bookings\Booking;
use App\Domain\Bookings\Enums\CancellationSource;
use App\Domain\Notifications\BookingNotifier;
use App\Domain\Notifications\Jobs\SendDueRemindersJob;
use App\Domain\Notifications\Mail\BookingNotificationMail;
use App\Domain\Notifications\Reminder;
use App\Domain\Notifications\ReminderScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_creates_lead_and_tenant_rows_per_offset_skipping_past(): void
    {
        $this->travelTo('2026-09-14T00:00:00Z');
        // Starts in 2 hours: the T-24h offset is in the past and must be skipped.
        $booking = Booking::factory()->create(['starts_at' => now()->addHours(2), 'ends_at' => now()->addHours(2)->addMinutes(30)]);

        app(ReminderScheduler::class)->scheduleFor($booking);

        $this->assertSame(2, Reminder::count()); // T-1h for lead + tenant only
        $this->assertSame(['lead', 'tenant'], Reminder::orderBy('recipient')->pluck('recipient')->all());
        $this->assertTrue(Reminder::first()->send_at->equalTo(now()->addHour()));
    }

    public function test_due_reminders_job_sends_and_stamps_only_due_rows(): void
    {
        Mail::fake();
        $booking = Booking::factory()->create();
        $due = Reminder::factory()->for($booking)->create(['send_at' => now()->subMinute()]);
        $future = Reminder::factory()->for($booking)->create(['send_at' => now()->addHour()]);
        $sent = Reminder::factory()->for($booking)->create(['send_at' => now()->subHour(), 'sent_at' => now()->subHour()]);

        (new SendDueRemindersJob())->handle();

        Mail::assertSent(BookingNotificationMail::class, 1);
        $this->assertNotNull($due->fresh()->sent_at);
        $this->assertNull($future->fresh()->sent_at);
    }

    public function test_confirmation_mails_both_parties_and_cancellation_mails_non_initiator(): void
    {
        Mail::fake();
        $booking = Booking::factory()->create();

        app(BookingNotifier::class)->sendConfirmation($booking);
        Mail::assertSent(BookingNotificationMail::class, 2); // lead + tenant

        app(BookingNotifier::class)->sendCancellation($booking, CancellationSource::Lead);
        Mail::assertSent(BookingNotificationMail::class, 3); // +1: tenant only

        app(BookingNotifier::class)->sendCancellation($booking, CancellationSource::Provider);
        Mail::assertSent(BookingNotificationMail::class, 4); // +1: lead only
    }

    public function test_sms_channel_is_a_visible_stub(): void
    {
        $this->expectException(\RuntimeException::class);
        app(\App\Domain\Notifications\Channels\SmsChannel::class)->send(
            new \App\Domain\Notifications\Data\NotificationMessageData('Jane', 'jane@example.com', null, 'x', []),
        );
    }
}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement**

Migration:

```php
Schema::create('reminders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
    $table->string('recipient');
    $table->string('channel')->default('mail');
    $table->timestamp('send_at');
    $table->timestamp('sent_at')->nullable();
    $table->timestamps();
});
```

Enums: `NotificationChannelType{Mail='mail', Sms='sms'}`, `ReminderRecipient{Lead='lead', Tenant='tenant'}`.

`MailChannel`:

```php
class MailChannel implements NotificationChannelContract
{
    public function send(NotificationMessageData $message): void
    {
        Mail::to($message->recipientEmail)->send(new BookingNotificationMail($message));
    }
}
```

`SmsChannel`: `send()` → `throw new \RuntimeException('SMS channel is not implemented in this POC — wiring point only.');`

`NotificationChannelManager::channel()`: `match` → `Mail => app(MailChannel::class), Sms => app(SmsChannel::class)`.

`BookingNotificationMail` (Mailable): constructor `public NotificationMessageData $message`; `envelope()` subject `$message->subject`; markdown-less blade view `mail.booking-notification` rendering `@foreach($message->lines as $line)<p>{{ $line }}</p>@endforeach`.

`ReminderScheduler`:

```php
class ReminderScheduler
{
    public function scheduleFor(Booking $booking): void
    {
        foreach (config('calendar.reminder_offsets_minutes') as $offset) {
            $sendAt = $booking->starts_at->subMinutes($offset);

            if ($sendAt->isPast()) {
                continue;
            }

            foreach (ReminderRecipient::cases() as $recipient) {
                Reminder::create([
                    'booking_id' => $booking->id,
                    'recipient' => $recipient->value,
                    'channel' => NotificationChannelType::Mail->value,
                    'send_at' => $sendAt,
                ]);
            }
        }
    }

    public function cancelFor(Booking $booking): void
    {
        Reminder::where('booking_id', $booking->id)->whereNull('sent_at')->delete();
    }
}
```

`BookingNotifier` (all message building lives here):

```php
class BookingNotifier
{
    public function __construct(protected NotificationChannelManager $channels)
    {
    }

    public function sendConfirmation(Booking $booking): void
    {
        $this->toLead($booking, 'Your appointment is confirmed', [
            sprintf('Your appointment with %s is confirmed for %s.', $booking->tenant->name, $this->leadLocalTime($booking)),
            'Manage (cancel or reschedule): '.route('manage.show', $booking->manage_token),
        ]);
        $this->toTenant($booking, 'New appointment booked', [
            sprintf('%s booked %s.', $booking->leadFullName(), $this->tenantLocalTime($booking)),
            'Lead: '.$booking->lead_email.' / '.$booking->lead_phone,
        ]);
    }

    public function sendCancellation(Booking $booking, CancellationSource $source): void
    {
        if ($source === CancellationSource::Lead) {
            $this->toTenant($booking, 'Appointment canceled', [
                sprintf('%s canceled the appointment on %s.', $booking->leadFullName(), $this->tenantLocalTime($booking)),
            ]);

            return;
        }

        $this->toLead($booking, 'Your appointment was canceled', [
            sprintf('Your appointment with %s on %s was canceled.', $booking->tenant->name, $this->leadLocalTime($booking)),
        ]);
    }

    public function sendReminder(Reminder $reminder): void
    {
        $booking = $reminder->booking;

        $reminder->recipient === ReminderRecipient::Lead->value
            ? $this->toLead($booking, 'Appointment reminder', [sprintf('Reminder: your appointment with %s is at %s.', $booking->tenant->name, $this->leadLocalTime($booking))])
            : $this->toTenant($booking, 'Appointment reminder', [sprintf('Reminder: %s at %s.', $booking->leadFullName(), $this->tenantLocalTime($booking))]);
    }

    protected function toLead(Booking $booking, string $subject, array $lines): void
    {
        $this->channels->channel(NotificationChannelType::Mail)->send(new NotificationMessageData(
            $booking->leadFullName(), $booking->lead_email, $booking->lead_phone, $subject, $lines,
        ));
    }

    protected function toTenant(Booking $booking, string $subject, array $lines): void
    {
        $this->channels->channel(NotificationChannelType::Mail)->send(new NotificationMessageData(
            $booking->tenant->name, $booking->tenant->email, null, $subject, $lines,
        ));
    }

    protected function leadLocalTime(Booking $booking): string
    {
        return $booking->starts_at->setTimezone($booking->lead_timezone)->format('D, M j Y g:ia T');
    }

    protected function tenantLocalTime(Booking $booking): string
    {
        return $booking->starts_at->setTimezone($booking->tenant->timezone)->format('D, M j Y g:ia T');
    }
}
```

`SendDueRemindersJob::handle()` (claim atomically — update first, then read what we claimed):

```php
public function handle(): void
{
    Reminder::query()
        ->whereNull('sent_at')
        ->where('send_at', '<=', now())
        ->orderBy('id')
        ->get()
        ->each(function (Reminder $reminder): void {
            $claimed = Reminder::whereKey($reminder->id)->whereNull('sent_at')->update(['sent_at' => now()]);

            if ($claimed === 1) {
                app(BookingNotifier::class)->sendReminder($reminder);
            }
        });
}
```

`Reminder` model: fillable all, casts `send_at`/`sent_at` immutable_datetime, `booking(): BelongsTo`. Factory: `['booking_id' => Booking::factory(), 'recipient' => 'lead', 'channel' => 'mail', 'send_at' => now()->addHour()]`.

`routes/console.php`: `Schedule::job(new SendDueRemindersJob())->everyMinute();`

Route `manage.show` doesn't exist yet — register placeholder in `routes/web.php`: `Route::get('manage/{token}', fn () => abort(501))->name('manage.show');` (Task 12 replaces).

- [ ] **Step 4: Run → PASS.** **Step 5: Commit** — `git commit -m "feat: notification channels, reminders, due-reminder job"`

---

### Task 11: CreateBookingAction + booking API endpoints

**Files:**
- Create: `app/Domain/Bookings/Actions/CreateBookingAction.php`, `app/Domain/Bookings/Data/InviteeData.php`
- Create: `app/Http/Controllers/Api/BookingsController.php`, `app/Http/Requests/StoreBookingRequest.php`, `app/Http/Resources/BookingResource.php`
- Modify: `routes/api.php` (real `POST tenants/{tenant}/bookings` name `api.bookings.store`; replace `bookings/{booking:uuid}` placeholder with `BookingsController@show` keeping name `api.bookings.show`)
- Modify: `bootstrap/app.php` exception rendering (map `SlotConflictException` → 409, `ProviderApiFailedException` → 502, `ProviderNotConfiguredException` → 422)
- Test: `tests/Feature/Api/CreateBookingTest.php`

**Interfaces:**
- Consumes: calculator (7), gateway (4/6), `Booking` (8), webhooks action (9), notifier + scheduler (10)
- Produces:

```php
final class InviteeData {
    public function __construct(
        public readonly string $firstName, public readonly string $lastName,
        public readonly string $email, public readonly string $phone, public readonly string $timezone,
    ) {}
}
// CreateBookingAction
public function execute(Tenant $tenant, CarbonImmutable $startTime, InviteeData $invitee, array $tracking, ?Booking $rescheduledFrom = null): Booking;
```

API: `POST /api/v1/tenants/{tenant}/bookings` → 201 `{uuid, uri, start_time, end_time, status, reschedule_url, cancel_url}`; 409 on conflict; 502 on provider failure. `GET /api/v1/bookings/{uuid}` → 200 booking resource. `reschedule_url`/`cancel_url` = `route('manage.show', manage_token)`.

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace Tests\Feature\Api;

use App\Domain\Bookings\Booking;
use App\Domain\Calendar\Data\BusyBlockData;
use App\Domain\Calendar\Services\Mock\CalendarMockService;
use App\Domain\Integrations\Integration;
use App\Domain\Notifications\Mail\BookingNotificationMail;
use App\Domain\Notifications\Reminder;
use App\Domain\Tenants\BookingSettings;
use App\Domain\Tenants\Tenant;
use App\Domain\Webhooks\Jobs\SendWebhookJob;
use App\Domain\Webhooks\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateBookingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['calendar.gateway' => 'mock']);
        $this->travelTo('2026-09-10T00:00:00Z');
        $this->tenant = Tenant::factory()->create(['timezone' => 'America/New_York']);
        BookingSettings::factory()->for($this->tenant)->create();
        Integration::factory()->for($this->tenant)->create();
        WebhookEndpoint::factory()->for($this->tenant)->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'start_time' => '2026-09-14T14:00:00Z', // Monday 10:00 EDT — on grid
            'invitee' => [
                'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
                'phone' => '+15550001111', 'timezone' => 'America/Chicago',
            ],
            'tracking' => ['utm_source' => 'ULH', 'utm_content' => 'lead-token-abc'],
        ], $overrides);
    }

    public function test_happy_path_books_creates_event_reminders_mails_and_webhook(): void
    {
        Mail::fake();
        Queue::fake();

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", $this->payload());

        $response->assertCreated()
            ->assertJsonPath('start_time', '2026-09-14T14:00:00Z')
            ->assertJsonPath('status', 'confirmed');

        $booking = Booking::firstOrFail();
        $this->assertSame('mock-event-'.$booking->uuid, $booking->provider_event_id);
        $this->assertSame(['utm_source' => 'ULH', 'utm_content' => 'lead-token-abc'], $booking->tracking);
        $this->assertStringContainsString($booking->manage_token, $response->json('reschedule_url'));
        $this->assertSame(4, Reminder::count());                       // 2 offsets × 2 recipients
        Mail::assertSent(BookingNotificationMail::class, 2);           // confirmations
        Queue::assertPushed(SendWebhookJob::class, 1);                 // booking.created
        $mock = app(CalendarMockService::class);
        $this->assertSame('Consultation: Jane Doe', $mock->createdEvents[$booking->provider_event_id]->summary);
    }

    public function test_double_booking_same_slot_returns_409(): void
    {
        Mail::fake();
        Queue::fake();
        $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", $this->payload())->assertCreated();

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", $this->payload())->assertStatus(409);
        $this->assertSame(1, Booking::count());
    }

    public function test_provider_busy_at_booking_time_returns_409(): void
    {
        app(CalendarMockService::class)->busy = [
            new BusyBlockData(CarbonImmutable::parse('2026-09-14T14:00:00Z'), CarbonImmutable::parse('2026-09-14T14:30:00Z')),
        ];

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", $this->payload())->assertStatus(409);
        $this->assertSame(0, Booking::count());
    }

    public function test_probe_error_returns_502_and_creates_nothing(): void
    {
        app(CalendarMockService::class)->failProbe = true;

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", $this->payload())->assertStatus(502);
        $this->assertSame(0, Booking::count());
    }

    public function test_provider_create_failure_returns_502_and_rolls_back_row(): void
    {
        Mail::fake();
        Queue::fake();
        app(CalendarMockService::class)->failCreate = true;

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", $this->payload())->assertStatus(502);
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Reminder::count());
        Queue::assertNothingPushed();
    }

    public function test_off_grid_start_time_returns_409(): void
    {
        $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", $this->payload(['start_time' => '2026-09-14T14:10:00Z']))
            ->assertStatus(409);
    }

    public function test_show_booking_by_uuid(): void
    {
        Mail::fake();
        Queue::fake();
        $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", $this->payload());
        $uuid = Booking::firstOrFail()->uuid;

        $this->getJson("/api/v1/bookings/{$uuid}")
            ->assertOk()
            ->assertJsonPath('uuid', $uuid)
            ->assertJsonPath('status', 'confirmed');
    }
}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement**

`InviteeData` per Interfaces block. `CreateBookingAction`:

```php
<?php

namespace App\Domain\Bookings\Actions;

use App\Domain\Bookings\AvailabilityCalculator;
use App\Domain\Bookings\Booking;
use App\Domain\Bookings\Data\InviteeData;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Calendar\CalendarGatewayManager;
use App\Domain\Calendar\Data\CalendarEventDraftData;
use App\Domain\Calendar\Exceptions\ProviderNotConfiguredException;
use App\Domain\Calendar\Exceptions\SlotConflictException;
use App\Domain\Notifications\BookingNotifier;
use App\Domain\Notifications\ReminderScheduler;
use App\Domain\Tenants\Tenant;
use App\Domain\Webhooks\Enums\WebhookEvent;
use App\Domain\Webhooks\SendBookingWebhooksAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class CreateBookingAction
{
    public function __construct(
        protected CalendarGatewayManager $manager,
        protected AvailabilityCalculator $calculator,
        protected ReminderScheduler $reminders,
        protected BookingNotifier $notifier,
        protected SendBookingWebhooksAction $webhooks,
    ) {
    }

    public function execute(Tenant $tenant, CarbonImmutable $startTime, InviteeData $invitee, array $tracking, ?Booking $rescheduledFrom = null): Booking
    {
        $integration = $tenant->integration
            ?? throw new ProviderNotConfiguredException('No calendar integration is connected for this tenant.');

        $settings = $tenant->bookingSettings;
        $endTime = $startTime->addMinutes($settings->meeting_length_minutes);
        $gateway = $this->manager->for($integration);

        // Spec §9: per-tenant atomic lock; grid + own-bookings + fresh provider probe are
        // the ONLY double-booking protection — Google does not conflict-check on insert.
        $booking = Cache::lock("booking:tenant:{$tenant->id}", 15)->block(10, function () use ($tenant, $settings, $integration, $gateway, $startTime, $endTime, $invitee, $tracking, $rescheduledFrom): Booking {
            $busy = [
                ...$gateway->availability()->busyBlocks($integration, $startTime, $endTime), // ProviderApiFailedException bubbles → 502
                ...Booking::query()->confirmed()
                    ->where('tenant_id', $tenant->id)
                    ->where('starts_at', '<', $endTime)
                    ->where('ends_at', '>', $startTime)
                    ->get()
                    ->map(fn (Booking $existing) => $existing->asBusyBlock()),
            ];

            if (! $this->calculator->isBookable($settings, $tenant->timezone, CarbonImmutable::now(), $startTime, $busy)) {
                throw new SlotConflictException('This slot is no longer available.');
            }

            return Booking::create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'provider' => $integration->type,
                'lead_first_name' => $invitee->firstName,
                'lead_last_name' => $invitee->lastName,
                'lead_email' => $invitee->email,
                'lead_phone' => $invitee->phone,
                'lead_timezone' => $invitee->timezone,
                'tracking' => $tracking,
                'starts_at' => $startTime,
                'ends_at' => $endTime,
                'status' => BookingStatus::Confirmed->value,
                'manage_token' => Str::random(64),
                'rescheduled_from_booking_id' => $rescheduledFrom?->id,
            ]);
        });

        try {
            $event = $gateway->events()->create($integration, new CalendarEventDraftData(
                summary: 'Consultation: '.$booking->leadFullName(),
                description: "Booked via ULH.\nPhone: ".$booking->lead_phone,
                start: $startTime,
                end: $endTime,
                timezone: $tenant->timezone,
                attendeeEmail: $booking->lead_email,
                attendeeName: $booking->leadFullName(),
                bookingUuid: $booking->uuid,
            ));
        } catch (Throwable $exception) {
            $booking->delete(); // spec §9: provider failure rolls the row back

            throw $exception;
        }

        $booking->update(['provider_event_id' => $event->id, 'provider_event_link' => $event->link]);

        $this->reminders->scheduleFor($booking);
        $this->notifier->sendConfirmation($booking);
        $this->webhooks->execute($booking, WebhookEvent::BookingCreated);

        return $booking;
    }
}
```

`StoreBookingRequest` rules:

```php
return [
    'start_time' => ['required', 'date'],
    'invitee.first_name' => ['required', 'string', 'max:100'],
    'invitee.last_name' => ['required', 'string', 'max:100'],
    'invitee.email' => ['required', 'email'],
    'invitee.phone' => ['required', 'string', 'max:30'],
    'invitee.timezone' => ['required', 'timezone'],
    'tracking' => ['sometimes', 'array'],
];
```

`BookingResource::toArray()`:

```php
return [
    'uuid' => $this->uuid,
    'uri' => route('api.bookings.show', $this->uuid),
    'start_time' => $this->starts_at->toIso8601ZuluString(),
    'end_time' => $this->ends_at->toIso8601ZuluString(),
    'status' => $this->status,
    'provider' => $this->provider,
    'reschedule_url' => route('manage.show', $this->manage_token),
    'cancel_url' => route('manage.show', $this->manage_token),
];
```

`BookingsController`:

```php
public function store(StoreBookingRequest $request, Tenant $tenant, CreateBookingAction $action): JsonResponse
{
    $booking = $action->execute(
        $tenant,
        CarbonImmutable::parse($request->input('start_time'))->utc(),
        new InviteeData(
            firstName: $request->input('invitee.first_name'),
            lastName: $request->input('invitee.last_name'),
            email: $request->input('invitee.email'),
            phone: $request->input('invitee.phone'),
            timezone: $request->input('invitee.timezone'),
        ),
        $request->input('tracking', []),
    );

    return (new BookingResource($booking))->response()->setStatusCode(201);
}

public function show(Booking $booking): BookingResource
{
    return new BookingResource($booking);
}
```

Routes: `Route::post('tenants/{tenant}/bookings', [BookingsController::class, 'store'])->name('bookings.store');` and replace the Task 9 placeholder with `Route::get('bookings/{booking:uuid}', [BookingsController::class, 'show'])->name('bookings.show');`

`bootstrap/app.php` `withExceptions`:

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(fn (SlotConflictException $e) => response()->json(['message' => $e->getMessage() ?: 'This slot is no longer available.'], 409));
    $exceptions->render(fn (ProviderApiFailedException $e) => response()->json(['message' => 'The calendar provider request failed.'], 502));
    $exceptions->render(fn (ProviderNotConfiguredException $e) => response()->json(['message' => $e->getMessage() ?: 'Calendar not configured.'], 422));
})
```

- [ ] **Step 4: Run → PASS** (also rerun full suite: `php artisan test`). **Step 5: Commit** — `git commit -m "feat: booking creation with slot re-validation, API endpoints"`

---

### Task 12: Cancel + Reschedule actions, manage endpoints

**Files:**
- Create: `app/Domain/Bookings/Actions/CancelBookingAction.php`, `RescheduleBookingAction.php`
- Create: `app/Http/Controllers/ManageBookingController.php`
- Modify: `routes/web.php` — replace `manage.show` placeholder: `GET manage/{token}` (`manage.show`), `POST manage/{token}/cancel` (`manage.cancel`), `POST manage/{token}/reschedule` (`manage.reschedule`)
- Test: `tests/Feature/ManageBookingTest.php`

**Interfaces:**
- Consumes: create action (11), notifier/reminders (10), webhooks (9), gateway (4)
- Produces: `CancelBookingAction::execute(Booking $booking, CancellationSource $source): Booking` — marks canceled, deletes provider event unless source is Provider, `ReminderScheduler::cancelFor`, `BookingNotifier::sendCancellation`, webhook `booking.canceled`; idempotent (already-canceled returns unchanged, no side effects). `RescheduleBookingAction::execute(Booking $booking, CarbonImmutable $newStartTime): Booking` — cancel(Lead) + create with copied invitee/tracking, link via `rescheduled_from_booking_id`. Manage JSON contract: `GET` → view (Task 17 blade; for now return the booking as JSON when `expectsJson`, else the blade — this task registers the route returning `BookingResource` for JSON and a placeholder `abort(501)` for HTML, Task 17 adds the page); `POST cancel` → 200 `{status:'canceled'}`; `POST reschedule {start_time}` → 200 `{manage_url, uuid, start_time}`.

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace Tests\Feature;

use App\Domain\Bookings\Booking;
use App\Domain\Bookings\Enums\CancellationSource;
use App\Domain\Calendar\Services\Mock\CalendarMockService;
use App\Domain\Integrations\Integration;
use App\Domain\Notifications\Reminder;
use App\Domain\Tenants\BookingSettings;
use App\Domain\Tenants\Tenant;
use App\Domain\Webhooks\Jobs\SendWebhookJob;
use App\Domain\Webhooks\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManageBookingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['calendar.gateway' => 'mock']);
        Mail::fake();
        Queue::fake();
        $this->travelTo('2026-09-10T00:00:00Z');
        $this->tenant = Tenant::factory()->create(['timezone' => 'America/New_York']);
        BookingSettings::factory()->for($this->tenant)->create();
        Integration::factory()->for($this->tenant)->create();
        WebhookEndpoint::factory()->for($this->tenant)->create();
    }

    private function book(string $startTime = '2026-09-14T14:00:00Z'): Booking
    {
        $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", [
            'start_time' => $startTime,
            'invitee' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com', 'phone' => '+15550001111', 'timezone' => 'America/Chicago'],
            'tracking' => ['utm_content' => 'lead-token-abc'],
        ])->assertCreated();

        return Booking::latest('id')->firstOrFail();
    }

    public function test_lead_cancel_deletes_provider_event_clears_reminders_and_webhooks(): void
    {
        $booking = $this->book();

        $this->postJson("/manage/{$booking->manage_token}/cancel")->assertOk()->assertJsonPath('status', 'canceled');

        $booking->refresh();
        $this->assertSame('canceled', $booking->status);
        $this->assertSame('lead', $booking->cancellation_source);
        $this->assertContains($booking->provider_event_id, app(CalendarMockService::class)->deletedEventIds);
        $this->assertSame(0, Reminder::whereNull('sent_at')->count());
        Queue::assertPushed(SendWebhookJob::class, 2); // created + canceled
    }

    public function test_cancel_is_idempotent(): void
    {
        $booking = $this->book();
        $this->postJson("/manage/{$booking->manage_token}/cancel")->assertOk();
        $this->postJson("/manage/{$booking->manage_token}/cancel")->assertOk();

        Queue::assertPushed(SendWebhookJob::class, 2); // no extra webhook on second cancel
    }

    public function test_provider_sourced_cancel_skips_provider_delete(): void
    {
        $booking = $this->book();
        $mock = app(CalendarMockService::class);
        $before = count($mock->deletedEventIds);

        app(\App\Domain\Bookings\Actions\CancelBookingAction::class)->execute($booking, CancellationSource::Provider);

        $this->assertCount($before, $mock->deletedEventIds); // no delete call
        $this->assertSame('provider', $booking->fresh()->cancellation_source);
    }

    public function test_reschedule_cancels_old_creates_linked_new_and_returns_new_manage_url(): void
    {
        $booking = $this->book();

        $response = $this->postJson("/manage/{$booking->manage_token}/reschedule", ['start_time' => '2026-09-15T14:00:00Z'])
            ->assertOk();

        $new = Booking::where('uuid', $response->json('uuid'))->firstOrFail();
        $this->assertSame('canceled', $booking->fresh()->status);
        $this->assertSame($booking->id, $new->rescheduled_from_booking_id);
        $this->assertSame('lead-token-abc', $new->tracking['utm_content']);
        $this->assertStringContainsString($new->manage_token, $response->json('manage_url'));
        Queue::assertPushed(SendWebhookJob::class, 3); // created + canceled + created
    }

    public function test_invalid_manage_token_is_404(): void
    {
        $this->postJson('/manage/not-a-real-token/cancel')->assertNotFound();
    }
}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement**

`CancelBookingAction`:

```php
<?php

namespace App\Domain\Bookings\Actions;

use App\Domain\Bookings\Booking;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Enums\CancellationSource;
use App\Domain\Calendar\CalendarGatewayManager;
use App\Domain\Notifications\BookingNotifier;
use App\Domain\Notifications\ReminderScheduler;
use App\Domain\Webhooks\Enums\WebhookEvent;
use App\Domain\Webhooks\SendBookingWebhooksAction;

class CancelBookingAction
{
    public function __construct(
        protected CalendarGatewayManager $manager,
        protected ReminderScheduler $reminders,
        protected BookingNotifier $notifier,
        protected SendBookingWebhooksAction $webhooks,
    ) {
    }

    public function execute(Booking $booking, CancellationSource $source): Booking
    {
        if ($booking->status === BookingStatus::Canceled->value) {
            return $booking; // idempotent
        }

        $booking->update([
            'status' => BookingStatus::Canceled->value,
            'canceled_at' => now(),
            'cancellation_source' => $source->value,
        ]);

        $integration = $booking->tenant->integration;

        if ($source !== CancellationSource::Provider && $integration !== null && $booking->provider_event_id !== null) {
            $this->manager->for($integration)->events()->delete($integration, $booking->provider_event_id);
        }

        $this->reminders->cancelFor($booking);
        $this->notifier->sendCancellation($booking, $source);
        $this->webhooks->execute($booking->refresh(), WebhookEvent::BookingCanceled);

        return $booking;
    }
}
```

`RescheduleBookingAction`:

```php
class RescheduleBookingAction
{
    public function __construct(protected CancelBookingAction $cancel, protected CreateBookingAction $create)
    {
    }

    public function execute(Booking $booking, CarbonImmutable $newStartTime): Booking
    {
        // Order matters: create-first would see the old booking as an own-booking busy block.
        $this->cancel->execute($booking, CancellationSource::Lead);

        return $this->create->execute(
            $booking->tenant,
            $newStartTime,
            new InviteeData($booking->lead_first_name, $booking->lead_last_name, $booking->lead_email, $booking->lead_phone, $booking->lead_timezone),
            $booking->tracking ?? [],
            rescheduledFrom: $booking,
        );
    }
}
```

`ManageBookingController` (resolve booking with `Booking::where('manage_token', $token)->firstOrFail()` in a private method):

```php
public function show(string $token): mixed
{
    $booking = $this->booking($token);

    if (request()->expectsJson()) {
        return new BookingResource($booking);
    }

    abort(501); // blade page arrives in Task 17
}

public function cancel(string $token, CancelBookingAction $action): JsonResponse
{
    $booking = $action->execute($this->booking($token), CancellationSource::Lead);

    return response()->json(['status' => $booking->status]);
}

public function reschedule(string $token, Request $request, RescheduleBookingAction $action): JsonResponse
{
    $request->validate(['start_time' => ['required', 'date']]);

    $new = $action->execute($this->booking($token), CarbonImmutable::parse($request->input('start_time'))->utc());

    return response()->json([
        'uuid' => $new->uuid,
        'start_time' => $new->starts_at->toIso8601ZuluString(),
        'manage_url' => route('manage.show', $new->manage_token),
    ]);
}
```

Routes (web, but exclude the two POSTs from CSRF like the sink — they're called by the widget via fetch):
`validateCsrfTokens(except: ['demo/webhook-sink', 'manage/*'])`.

- [ ] **Step 4: Run → PASS** (full suite). **Step 5: Commit** — `git commit -m "feat: cancel and reschedule actions with manage endpoints"`

---

### Task 13: ReconcileBookingsJob

**Files:**
- Create: `app/Domain/Bookings/Jobs/ReconcileBookingsJob.php`
- Modify: `routes/console.php` (schedule everyTwoMinutes)
- Test: `tests/Feature/Domain/Bookings/ReconcileBookingsTest.php`

**Interfaces:**
- Consumes: `Booking::confirmed()` (8), gateway `events()->exists` (4/6), `CancelBookingAction` (12)
- Produces: scheduled job cancelling bookings whose provider event is definitively gone, `cancellation_source = provider`.

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace Tests\Feature\Domain\Bookings;

use App\Domain\Bookings\Booking;
use App\Domain\Bookings\Jobs\ReconcileBookingsJob;
use App\Domain\Calendar\Services\Mock\CalendarMockService;
use App\Domain\Integrations\Integration;
use App\Domain\Tenants\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReconcileBookingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['calendar.gateway' => 'mock']);
        Mail::fake();
        Queue::fake();
    }

    private function bookingWithLiveEvent(Tenant $tenant): Booking
    {
        $booking = Booking::factory()->for($tenant)->create(['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addMinutes(30)]);
        $mock = app(CalendarMockService::class);
        $mock->createdEvents[$booking->provider_event_id] = \Mockery::mock(\App\Domain\Calendar\Data\CalendarEventDraftData::class); // exists() only checks the key

        return $booking;
    }

    public function test_gone_provider_event_cancels_booking_with_provider_source(): void
    {
        $tenant = Tenant::factory()->has(Integration::factory())->create();
        $live = $this->bookingWithLiveEvent($tenant);
        $gone = $this->bookingWithLiveEvent($tenant);
        app(CalendarMockService::class)->deletedEventIds[] = $gone->provider_event_id;

        (new ReconcileBookingsJob())->handle();

        $this->assertSame('canceled', $gone->fresh()->status);
        $this->assertSame('provider', $gone->fresh()->cancellation_source);
        $this->assertSame('confirmed', $live->fresh()->status);
    }

    public function test_provider_error_leaves_bookings_untouched(): void
    {
        $tenant = Tenant::factory()->has(Integration::factory())->create();
        $booking = $this->bookingWithLiveEvent($tenant);
        // Simulate probe error by making exists() throw: reuse failProbe? exists doesn't use it —
        // point the booking at a tenant whose gateway throws instead:
        app(CalendarMockService::class)->failExists = true;

        (new ReconcileBookingsJob())->handle();

        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_past_and_eventless_bookings_are_skipped(): void
    {
        $tenant = Tenant::factory()->has(Integration::factory())->create();
        Booking::factory()->for($tenant)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addMinutes(30)]);
        Booking::factory()->for($tenant)->create(['provider_event_id' => null, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(30)]);

        (new ReconcileBookingsJob())->handle();

        $this->assertSame(2, Booking::confirmed()->count());
    }
}
```

Add to `CalendarMockService` (part of this task): `public bool $failExists = false;` and at the top of `exists()`: `if ($this->failExists) { throw new ProviderApiFailedException('Mock exists failure.'); }`

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Domain\Bookings\Jobs;

use App\Domain\Bookings\Actions\CancelBookingAction;
use App\Domain\Bookings\Booking;
use App\Domain\Bookings\Enums\CancellationSource;
use App\Domain\Calendar\CalendarGatewayManager;
use App\Domain\Calendar\Exceptions\ProviderApiFailedException;
use App\Domain\Calendar\Exceptions\ProviderAuthExpiredException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileBookingsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        Booking::query()->confirmed()
            ->whereNotNull('provider_event_id')
            ->where('starts_at', '>', now())
            ->with('tenant.integration')
            ->chunkById(100, function ($bookings): void {
                foreach ($bookings as $booking) {
                    $this->reconcile($booking);
                }
            });
    }

    protected function reconcile(Booking $booking): void
    {
        $integration = $booking->tenant->integration;

        if ($integration === null) {
            return;
        }

        try {
            $exists = app(CalendarGatewayManager::class)->for($integration)
                ->events()->exists($integration, $booking->provider_event_id);
        } catch (ProviderAuthExpiredException) {
            return; // integration already flagged for re-auth by the auth service; skip
        } catch (ProviderApiFailedException $exception) {
            logger()->warning('[Reconcile] Provider error while probing booking; skipping', [
                'booking_id' => $booking->id, 'message' => $exception->getMessage(),
            ]);

            return; // an API blip must never mass-cancel (spec §12)
        }

        if (! $exists) {
            app(CancelBookingAction::class)->execute($booking, CancellationSource::Provider);
        }
    }
}
```

`routes/console.php`: `Schedule::job(new ReconcileBookingsJob())->everyTwoMinutes();`

- [ ] **Step 4: Run → PASS** (full suite). **Step 5: Commit** — `git commit -m "feat: reconcile job detecting provider-side cancellations"`

---

### Task 14: BookingWidget (Vue) + Vitest

**Files:**
- Create: `resources/js/widget/BookingWidget.vue`, `resources/js/widget/InviteeForm.vue`, `resources/js/widget/ConfirmationCard.vue`, `resources/js/widget/api.ts`, `resources/js/widget/BookingWidget.test.ts`
- Modify: `resources/js/app.js` (mount widget on `[data-booking-widget]` elements)

**Interfaces:**
- Consumes: `GET /api/v1/tenants/{id}/availability`, `POST /api/v1/tenants/{id}/bookings`, `POST /manage/{token}/reschedule` (Tasks 8/11/12 shapes)
- Produces: `<BookingWidget :tenant-id :api-base :tracking :reschedule-token?>` — `rescheduleToken` (the manage_token) switches submit to the reschedule endpoint and redirects to the returned `manage_url`. Mount contract for Blade pages (Tasks 16/17):

```html
<div data-booking-widget
     data-tenant-id="1"
     data-api-base="/api/v1"
     data-tracking='{"utm_content":"..."}'
     data-reschedule-token=""></div>
```

Styling: Tailwind utilities only, all colors via `--color-primary` / `--color-primary-contrast`, radius via `--radius-widget` (e.g. `bg-primary text-primary-contrast rounded-widget` — Tailwind 4 resolves these from `@theme`).

- [ ] **Step 1: Write failing Vitest tests**

`resources/js/widget/BookingWidget.test.ts`:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import BookingWidget from './BookingWidget.vue';

const slots = {
    collection: [
        { status: 'available', start_time: '2026-09-14T14:00:00Z', end_time: '2026-09-14T14:30:00Z' },
        { status: 'available', start_time: '2026-09-14T14:30:00Z', end_time: '2026-09-14T15:00:00Z' },
    ],
};

function fetchMock(responses: Array<{ status: number; body: unknown }>) {
    const fn = vi.fn();
    for (const r of responses) {
        fn.mockResolvedValueOnce({ ok: r.status < 400, status: r.status, json: async () => r.body });
    }
    vi.stubGlobal('fetch', fn);
    return fn;
}

describe('BookingWidget', () => {
    beforeEach(() => vi.unstubAllGlobals());

    it('renders available time slots for the selected day', async () => {
        fetchMock([{ status: 200, body: slots }]);
        const wrapper = mount(BookingWidget, { props: { tenantId: 1, apiBase: '/api/v1', tracking: {} } });
        await flushPromises();

        await wrapper.find('[data-test="day-2026-09-14"]').trigger('click');
        expect(wrapper.findAll('[data-test="slot"]').length).toBe(2);
    });

    it('books a slot and shows confirmation', async () => {
        fetchMock([
            { status: 200, body: slots },
            { status: 201, body: { uuid: 'u1', start_time: '2026-09-14T14:00:00Z', reschedule_url: '/manage/tok' } },
        ]);
        const wrapper = mount(BookingWidget, { props: { tenantId: 1, apiBase: '/api/v1', tracking: { utm_content: 'x' } } });
        await flushPromises();
        await wrapper.find('[data-test="day-2026-09-14"]').trigger('click');
        await wrapper.findAll('[data-test="slot"]')[0].trigger('click');
        await wrapper.find('[data-test="first-name"]').setValue('Jane');
        await wrapper.find('[data-test="last-name"]').setValue('Doe');
        await wrapper.find('[data-test="email"]').setValue('jane@example.com');
        await wrapper.find('[data-test="phone"]').setValue('+15550001111');
        await wrapper.find('[data-test="submit"]').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[data-test="confirmation"]').exists()).toBe(true);
    });

    it('recovers from a 409 by refetching slots and showing a notice', async () => {
        fetchMock([
            { status: 200, body: slots },
            { status: 409, body: { message: 'This slot is no longer available.' } },
            { status: 200, body: { collection: [slots.collection[1]] } }, // refetch
        ]);
        const wrapper = mount(BookingWidget, { props: { tenantId: 1, apiBase: '/api/v1', tracking: {} } });
        await flushPromises();
        await wrapper.find('[data-test="day-2026-09-14"]').trigger('click');
        await wrapper.findAll('[data-test="slot"]')[0].trigger('click');
        await wrapper.find('[data-test="first-name"]').setValue('Jane');
        await wrapper.find('[data-test="last-name"]').setValue('Doe');
        await wrapper.find('[data-test="email"]').setValue('jane@example.com');
        await wrapper.find('[data-test="phone"]').setValue('+15550001111');
        await wrapper.find('[data-test="submit"]').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[data-test="slot-taken-notice"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-test="slot"]').length).toBe(1);
    });
});
```

- [ ] **Step 2: Run → FAIL** (`npm test`).

- [ ] **Step 3: Implement**

`api.ts` — tiny fetch helpers:

```ts
export interface Slot { status: string; start_time: string; end_time: string }

export async function fetchAvailability(apiBase: string, tenantId: number, from: string, to: string): Promise<Slot[]> {
    const res = await fetch(`${apiBase}/tenants/${tenantId}/availability?from=${from}&to=${to}`);
    if (!res.ok) throw new Error(`availability failed: ${res.status}`);
    return (await res.json()).collection;
}

export class SlotTakenError extends Error {}

export async function submitBooking(url: string, payload: unknown): Promise<any> {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload),
    });
    if (res.status === 409) throw new SlotTakenError();
    if (!res.ok) throw new Error(`booking failed: ${res.status}`);
    return res.json();
}
```

`BookingWidget.vue` — single-file component, `<script setup lang="ts">`. Required behavior (implementer writes idiomatic Vue 3; the states and test hooks are the contract):

- Props: `tenantId: number`, `apiBase: string`, `tracking: object`, `rescheduleToken?: string`, `invitee?: object` (prefill for reschedule).
- State machine: `pick` (day strip + slots) → `form` → `submitting` → `confirmed`; `error` banner for 5xx.
- Day strip: current 7-day window starting `weekStart` (init: today), prev/next week buttons refetch availability for the window (`from`/`to` = window edges as `YYYY-MM-DD`); each day button `data-test="day-YYYY-MM-DD"` with a dot when it has slots; slots grouped by day from the flat collection using the **viewer timezone**.
- Viewer timezone: `ref(Intl.DateTimeFormat().resolvedOptions().timeZone)`, `<select>` with common zones + detected one; changing re-groups/re-renders times via `toLocaleTimeString([], { timeZone, hour: 'numeric', minute: '2-digit' })`.
- Slot buttons: `data-test="slot"`. Form inputs: `data-test="first-name" | last-name | email | phone"`; submit button in a `<form data-test="submit" @submit.prevent>`... (put `data-test="submit"` on the form; tests trigger submit on it).
- Submit: normal mode → `submitBooking(`${apiBase}/tenants/${tenantId}/bookings`, { start_time, invitee: {..., timezone: viewerTz}, tracking })`; reschedule mode → `submitBooking(`/manage/${rescheduleToken}/reschedule`, { start_time })` then `window.location.assign(response.manage_url)`.
- On `SlotTakenError`: show `data-test="slot-taken-notice"`, refetch the current window, return to `pick`.
- `ConfirmationCard.vue` (`data-test="confirmation"`): booked time in viewer tz, manage link from `reschedule_url`.
- `InviteeForm.vue`: the four inputs + validation required attributes; emits `submit(invitee)`.
- Styling per the Interfaces block (primary-tokened buttons, `rounded-widget` card, neutral grays otherwise).

`resources/js/app.js`:

```js
import { createApp } from 'vue';
import BookingWidget from './widget/BookingWidget.vue';

document.querySelectorAll('[data-booking-widget]').forEach((el) => {
    createApp(BookingWidget, {
        tenantId: Number(el.dataset.tenantId),
        apiBase: el.dataset.apiBase,
        tracking: JSON.parse(el.dataset.tracking || '{}'),
        rescheduleToken: el.dataset.rescheduleToken || undefined,
    }).mount(el);
});
```

- [ ] **Step 4: Run → PASS** (`npm test`, then `npm run build`). **Step 5: Commit** — `git commit -m "feat: Vue booking widget with timezone handling and 409 recovery"`

---

### Task 15: Setup page (settings + Google OAuth flow)

**Files:**
- Create: `app/Http/Controllers/Setup/SetupController.php`, `BookingSettingsController.php`, `GoogleOAuthController.php`
- Create: `app/Http/Requests/UpdateBookingSettingsRequest.php`
- Create: `resources/views/layouts/app.blade.php`, `resources/views/setup.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/SetupTest.php`

**Interfaces:**
- Consumes: settings (2), `Integration` (3), gateway `auth()` (4/5)
- Produces routes: `GET /setup` (`setup.show`), `PUT /setup/settings` (`setup.settings.update`), `GET /setup/google/redirect` (`setup.google.redirect`), `GET /setup/google/callback` (`setup.google.callback` — must equal `config('calendar.google.redirect_uri')` path), `POST /setup/google/disconnect` (`setup.google.disconnect`). Single-tenant POC: controllers act on `Tenant::firstOrFail()`.

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace Tests\Feature;

use App\Domain\Integrations\Enums\IntegrationType;
use App\Domain\Integrations\Integration;
use App\Domain\Tenants\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['calendar.gateway' => 'mock']);
        $this->seed(\Database\Seeders\DemoSeeder::class);
    }

    public function test_setup_page_renders_settings_and_disconnected_state(): void
    {
        $this->get('/setup')->assertOk()
            ->assertSee('Connect Google Calendar')
            ->assertSee('Meeting length');
    }

    public function test_settings_update_persists_and_validates_meeting_length(): void
    {
        $payload = [
            'available_days' => ['monday', 'wednesday'],
            'office_starts_at' => '10:00',
            'office_ends_at' => '16:00',
            'meeting_length_minutes' => 45,
        ];

        $this->put('/setup/settings', $payload)->assertRedirect('/setup');
        $settings = Tenant::firstOrFail()->bookingSettings->fresh();
        $this->assertSame(['monday', 'wednesday'], $settings->available_days);
        $this->assertSame(45, $settings->meeting_length_minutes);

        $this->put('/setup/settings', array_merge($payload, ['meeting_length_minutes' => 20]))
            ->assertSessionHasErrors('meeting_length_minutes');
    }

    public function test_google_redirect_stores_state_and_redirects_to_provider(): void
    {
        $response = $this->get('/setup/google/redirect');

        $response->assertRedirect();
        $this->assertStringStartsWith('https://mock.test/authorize?state=', $response->headers->get('Location'));
        $this->assertNotNull(session('calendar_oauth_state'));
    }

    public function test_callback_with_valid_state_creates_integration(): void
    {
        session(['calendar_oauth_state' => 'state-1']);

        $this->withSession(['calendar_oauth_state' => 'state-1'])
            ->get('/setup/google/callback?code=abc&state=state-1')
            ->assertRedirect('/setup');

        $integration = Integration::firstOrFail();
        $this->assertSame(IntegrationType::Google->value, $integration->type);
        $this->assertSame('mock-access', $integration->api_token);
        $this->assertSame('mock@example.com', data_get($integration->data, 'account_email'));
        $this->assertSame('primary', data_get($integration->data, 'calendar_id'));
    }

    public function test_callback_with_bad_state_is_rejected(): void
    {
        $this->withSession(['calendar_oauth_state' => 'state-1'])
            ->get('/setup/google/callback?code=abc&state=wrong')
            ->assertStatus(403);
        $this->assertSame(0, Integration::count());
    }

    public function test_disconnect_revokes_and_deletes_integration(): void
    {
        Integration::factory()->for(Tenant::firstOrFail())->create();

        $this->post('/setup/google/disconnect')->assertRedirect('/setup');
        $this->assertSame(0, Integration::count());
    }
}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement**

`GoogleOAuthController`:

```php
public function redirect(CalendarGatewayManager $manager): RedirectResponse
{
    $state = Str::random(40);
    session(['calendar_oauth_state' => $state]);

    return redirect()->away($manager->forType(IntegrationType::Google)->auth()->authorizationUrl($state));
}

public function callback(Request $request, CalendarGatewayManager $manager): RedirectResponse
{
    abort_unless(
        filled($request->query('state')) && $request->query('state') === session()->pull('calendar_oauth_state'),
        403, 'OAuth state mismatch.',
    );

    $tokens = $manager->forType(IntegrationType::Google)->auth()->exchangeCode((string) $request->query('code'));

    Integration::updateOrCreate(
        ['tenant_id' => Tenant::firstOrFail()->id, 'type' => IntegrationType::Google->value],
        [
            'api_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'expires_at' => $tokens->expiresAt,
            'data' => ['account_email' => $tokens->accountEmail, 'calendar_id' => 'primary'],
        ],
    );

    return redirect()->route('setup.show')->with('status', 'Google Calendar connected.');
}

public function disconnect(CalendarGatewayManager $manager): RedirectResponse
{
    $integration = Tenant::firstOrFail()->integration;

    if ($integration !== null) {
        $manager->for($integration)->auth()->revoke($integration);
        $integration->delete();
    }

    return redirect()->route('setup.show')->with('status', 'Disconnected.');
}
```

`UpdateBookingSettingsRequest`:

```php
return [
    'available_days' => ['required', 'array', 'min:1'],
    'available_days.*' => [Rule::enum(Weekday::class)],
    'office_starts_at' => ['required', 'date_format:H:i'],
    'office_ends_at' => ['required', 'date_format:H:i', 'after:office_starts_at'],
    'meeting_length_minutes' => ['required', Rule::in(MeetingLength::values())],
];
```

`BookingSettingsController@update`: `Tenant::firstOrFail()->bookingSettings->update([...validated, 'office_starts_at' => $validated['office_starts_at'].':00', 'office_ends_at' => $validated['office_ends_at'].':00'])`, redirect route `setup.show`.

`SetupController@show`: pass `$tenant`, `$settings`, `$integration` (with `requiresReauth()` flag), `$webhookEndpoint = $tenant->webhookEndpoints()->first()` to `setup.blade.php`.

`setup.blade.php` — extends `layouts/app.blade.php` (`@vite(['resources/css/app.css', 'resources/js/app.js'])`, simple header nav Setup / Demo). Panels (plain HTML + Tailwind):
1. Integration card: when `$integration === null` → button-link to `route('setup.google.redirect')` labeled **Connect Google Calendar**; when connected → account email, calendar id, `expires_at`, disconnect form, `@if($integration->requiresReauth())` red banner "Google authorization expired — reconnect required" with reconnect link; always an amber note: *"Google OAuth apps in Testing status expire refresh tokens after 7 days — expect weekly re-auth in this POC."*
2. Settings form (PUT `setup.settings.update`): checkboxes for each `Weekday::cases()`, `<input type="time">` × 2, `<select name="meeting_length_minutes">` over `MeetingLength::cases()` labeled "Meeting length".
3. Read-only webhook card: endpoint URL + secret.

Routes in `routes/web.php` per Interfaces block.

- [ ] **Step 4: Run → PASS.** **Step 5: Commit** — `git commit -m "feat: setup page with Google OAuth connect flow and settings form"`

---

### Task 16: Demo page

**Files:**
- Create: `app/Http/Controllers/Demo/DemoController.php`, `resources/views/demo.blade.php`
- Modify: `routes/web.php` — `GET /demo` (`demo.show`), `GET /demo/feed/deliveries` (`demo.feed.deliveries`), `GET /demo/feed/bookings` (`demo.feed.bookings`); make `/` redirect to `/demo`
- Test: `tests/Feature/DemoPageTest.php`

**Interfaces:**
- Consumes: widget mount contract (14), `WebhookDelivery` (9), `Booking` (8)
- Produces: demo page mounting the widget with a fake-lead tracking blob; two polled JSON feeds:
  - `demo.feed.deliveries` → `{data: [{id, event, url, payload, signature, response_status, attempts, delivered_at, created_at}]}` (latest 20, endpoint URL joined)
  - `demo.feed.bookings` → `{data: [{uuid, lead: 'Jane Doe', start_time, status, cancellation_source, created_at}]}` (latest 20)

- [ ] **Step 1: Write failing tests**

```php
public function test_demo_page_mounts_widget_with_tracking(): void
{
    $this->seed(DemoSeeder::class);

    $this->get('/demo')->assertOk()
        ->assertSee('data-booking-widget', false)
        ->assertSee('utm_content', false);
}

public function test_delivery_feed_returns_latest_deliveries_with_payload(): void
{
    $delivery = WebhookDelivery::factory()->create(['response_status' => 200]);

    $this->getJson('/demo/feed/deliveries')->assertOk()
        ->assertJsonPath('data.0.event', 'booking.created')
        ->assertJsonPath('data.0.response_status', 200);
}

public function test_bookings_feed_returns_status_and_cancellation(): void
{
    Booking::factory()->canceled()->create();

    $this->getJson('/demo/feed/bookings')->assertOk()
        ->assertJsonPath('data.0.status', 'canceled')
        ->assertJsonPath('data.0.cancellation_source', 'lead');
}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement**

`DemoController@show`: `$tenant = Tenant::firstOrFail()`, tracking blob `['utm_source' => 'ULH', 'utm_medium' => 'demo', 'utm_content' => 'demo-lead-'.Str::random(20)]`, render `demo.blade.php`. Feed methods return the shapes above (`WebhookDelivery::with('endpoint')->latest()->limit(20)`, map; `Booking::latest()->limit(20)`, map).

`demo.blade.php`: three sections —
1. Widget column: `<div data-booking-widget data-tenant-id="{{ $tenant->id }}" data-api-base="/api/v1" data-tracking='@json($tracking)'></div>`, plus a **theme switcher**: three buttons writing `document.documentElement.style.setProperty('--color-primary', …)` (`#2563eb`, `#16a34a`, `#9333ea`) — proves CSS-variable restyling.
2. Webhook inspector: `<table>` re-rendered every 3s from `demo.feed.deliveries` by a small inline `<script>` (plain fetch + innerHTML rows; payload pretty-printed in a `<details><pre>`).
3. Bookings table: same polling pattern from `demo.feed.bookings`, showing status badge + cancellation source — the reconcile demo (delete the event in Google Calendar, watch it flip).

- [ ] **Step 4: Run → PASS.** **Step 5: Commit** — `git commit -m "feat: demo page with widget, webhook inspector, live bookings table"`

---

### Task 17: Manage page (lead cancel/reschedule UI)

**Files:**
- Create: `resources/views/manage.blade.php`
- Modify: `app/Http/Controllers/ManageBookingController.php` (`show()` HTML branch renders the view)
- Test: `tests/Feature/ManageBookingPageTest.php`

**Interfaces:**
- Consumes: manage endpoints (12), widget reschedule mode (14)
- Produces: `GET /manage/{token}` HTML page.

- [ ] **Step 1: Write failing tests**

```php
public function test_manage_page_shows_booking_and_actions(): void
{
    $booking = Booking::factory()->create();

    $this->get("/manage/{$booking->manage_token}")->assertOk()
        ->assertSee($booking->tenant->name)
        ->assertSee('Cancel appointment')
        ->assertSee('data-reschedule-token="'.$booking->manage_token.'"', false);
}

public function test_canceled_booking_shows_terminal_state_without_actions(): void
{
    $booking = Booking::factory()->canceled()->create();

    $this->get("/manage/{$booking->manage_token}")->assertOk()
        ->assertSee('This appointment was canceled')
        ->assertDontSee('Cancel appointment');
}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement**

`show()` HTML branch: `return view('manage', ['booking' => $booking]);`

`manage.blade.php`: booking summary card (tenant name, lead name, start in lead timezone via `$booking->starts_at->setTimezone($booking->lead_timezone)->format('D, M j Y g:ia T')`, status badge). When confirmed:
- **Cancel appointment** button → inline `<script>` fetch POST `route('manage.cancel', $booking->manage_token)` then reload (page then shows canceled state).
- **Reschedule** `<details>` section mounting the widget in reschedule mode:
  `<div data-booking-widget data-tenant-id="{{ $booking->tenant_id }}" data-api-base="/api/v1" data-tracking='@json($booking->tracking ?? [])' data-reschedule-token="{{ $booking->manage_token }}"></div>` (widget redirects to the new booking's manage URL on success — Task 14 behavior).
When canceled: "This appointment was canceled." + no actions.

- [ ] **Step 4: Run → PASS.** **Step 5: Commit** — `git commit -m "feat: lead manage page with cancel and reschedule"`

---

### Task 18: README, polish, full verification

**Files:**
- Create: `README.md`
- Modify: anything Pint/PHPStan flags

**Interfaces:**
- Consumes: everything
- Produces: documented, verified POC.

- [ ] **Step 1: Write `README.md`** covering: what this POC is (link the spec); prerequisites (Herd, MySQL, Node); setup (`composer install`, `npm install`, `.env`, `php artisan migrate --seed`, `herd link && herd secure`, `npm run build`); **Google Cloud setup** (project → OAuth consent screen External/Testing → enable Google Calendar API → Web OAuth client with redirect `https://calendar-service.test/setup/google/callback` → env keys) including the 7-day Testing-status refresh-token caveat; running (`php artisan queue:work`, `php artisan schedule:work` — both required for webhooks/reminders/reconcile; `CALENDAR_GATEWAY=mock` to demo without Google); the demo script (connect → book on /demo → watch webhook inspector → delete event in Google Calendar → watch reconcile flip it); testing (`php artisan test`, `npm test`); known caveats (copy spec §18).

- [ ] **Step 2: Full verification**

```bash
vendor/bin/pint
vendor/bin/phpstan analyse --no-progress
php artisan test
npm test
npm run build
```

Expected: all green. Fix anything flagged.

- [ ] **Step 3: Manual smoke test** (mock mode): `CALENDAR_GATEWAY=mock` in `.env`, `php artisan migrate:fresh --seed`, open `https://calendar-service.test/demo`, book a slot, verify confirmation card, webhook row in inspector, reminder rows in DB, manage page cancel works.

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "docs: README with setup, Google Cloud config, demo script"
```

---

## Plan self-review notes (already applied)

- Spec §7 userinfo/account_email → Task 5; §9 lock → `Cache::lock` per amended spec; §10 envelope → Task 9 payload test asserts exact keys; §12 skip-on-error → Task 13 test; §14 theme switcher → Task 16; §16 DST test → Task 7.
- Cross-task names verified: `CalendarGatewayManager::for/forType`, `busyBlocks`, `create/delete/exists`, `isBookable`, `SendBookingWebhooksAction::execute`, `ReminderScheduler::scheduleFor/cancelFor`, `BookingNotifier::sendConfirmation/sendCancellation/sendReminder`, route names `api.availability`, `api.bookings.store/show`, `manage.show/cancel/reschedule`, `setup.*`, `demo.*`.
- Placeholder routes (Task 9 `api.bookings.show`, Task 10 `manage.show`) are explicitly replaced in Tasks 11/12 — not leftovers.
