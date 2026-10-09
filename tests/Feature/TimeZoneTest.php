<?php

use App\Models\AuditLog;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\ReconciliationQueries;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;

// Phase 22: the program's time zone. Stored times stay UTC; the setting
// changes how they're shown and which day is "today".
//
// The clock is frozen at 20:30 UTC on 9 October 2026 — already 10 October,
// 04:30, in Manila (UTC+8) — so the two zones disagree about the date.

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 20:30:00', 'UTC'));
});

function setSiteZone(string $zone): void
{
    DB::table('site_settings')->where('id', 1)->update(['timezone' => $zone]);
    SiteTime::forget();
}

function signInAsSuperadmin(): User
{
    $superadmin = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();
    test()->actingAs($superadmin);

    return $superadmin;
}

test('with nothing set, the zone is UTC and the app itself stays on UTC', function () {
    expect(SiteSetting::current()->timezone)->toBeNull()
        ->and(SiteTime::zone())->toBe('UTC')
        ->and(config('app.timezone'))->toBe('UTC');
});

test('a Superadmin picks a zone: saved, audited, and the app\'s own zone untouched', function () {
    $superadmin = signInAsSuperadmin();

    Volt::test('pages.settings.site')
        ->set('timezone', 'Asia/Manila')
        ->call('saveTimezone')
        ->assertHasNoErrors();

    expect(SiteSetting::current()->timezone)->toBe('Asia/Manila')
        ->and(SiteTime::zone())->toBe('Asia/Manila')
        // Storage and `now()` are still UTC — only display moves.
        ->and(config('app.timezone'))->toBe('UTC')
        ->and(now()->timezone->getName())->toBe('UTC');

    $log = AuditLog::where('action', 'site_timezone_changed')->sole();

    expect($log->user_id)->toBe($superadmin->id)
        ->and($log->previous_value)->toBe(['timezone' => 'UTC'])
        ->and($log->new_value)->toBe(['timezone' => 'Asia/Manila']);
});

test('saving the zone it already has writes no audit row', function () {
    signInAsSuperadmin();
    setSiteZone('Asia/Manila');

    Volt::test('pages.settings.site')
        ->set('timezone', 'Asia/Manila')
        ->call('saveTimezone')
        ->assertHasNoErrors();

    expect(AuditLog::where('action', 'site_timezone_changed')->count())->toBe(0);
});

test('a name that is not a zone is refused, changes nothing, and its error clears on the next attempt', function () {
    signInAsSuperadmin();

    Volt::test('pages.settings.site')
        ->set('timezone', 'Mars/Olympus_Mons')
        ->call('saveTimezone')
        ->assertHasErrors('timezone')
        ->set('timezone', 'Europe/Paris')
        ->call('saveTimezone')
        ->assertHasNoErrors();

    expect(SiteSetting::current()->timezone)->toBe('Europe/Paris')
        ->and(AuditLog::where('action', 'site_timezone_changed')->count())->toBe(1);
});

test('an Admin cannot change the time zone', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('pages.settings.site')->assertForbidden();

    expect(SiteSetting::current()->timezone)->toBeNull();
});

test('the settings page lists the zones, grouped, with their offsets', function () {
    signInAsSuperadmin();

    $this->get(route('settings.site'))
        ->assertOk()
        ->assertSee('Asia/Manila (UTC+08:00)', false)
        ->assertSee('<optgroup label="Europe">', false)
        ->assertSee('<optgroup label="UTC">', false);
});

test('the audit log shows each time in the site zone, and stores it unchanged', function () {
    signInAsSuperadmin();
    AuditLog::factory()->create(['occurred_at' => '2026-10-09 20:30:00', 'action' => 'person_created']);

    $this->get(route('audit.index'))->assertOk()->assertSee('2026-10-09 20:30:00');

    setSiteZone('Asia/Manila');

    $this->get(route('audit.index'))
        ->assertOk()
        ->assertSee('2026-10-10 04:30:00')
        ->assertDontSee('2026-10-09 20:30:00')
        ->assertSee('(Asia/Manila)');

    expect(DB::table('audit_logs')->where('action', 'person_created')->value('occurred_at'))
        ->toStartWith('2026-10-09 20:30:00');
});

test('the audit log\'s date filter means a day in the site zone', function () {
    signInAsSuperadmin();
    AuditLog::factory()->create(['occurred_at' => '2026-10-09 20:30:00', 'action' => 'person_created', 'new_value' => ['marker' => 'zone-test-row']]);
    setSiteZone('Asia/Manila');

    // 04:30 on the 10th in Manila.
    Volt::test('pages.audit.index')
        ->set('dateFrom', '2026-10-10')
        ->set('dateTo', '2026-10-10')
        ->assertSee('zone-test-row');

    Volt::test('pages.audit.index')
        ->set('dateFrom', '2026-10-09')
        ->set('dateTo', '2026-10-09')
        ->assertDontSee('zone-test-row');
});

test('the same day filter in UTC still means the UTC day', function () {
    signInAsSuperadmin();
    AuditLog::factory()->create(['occurred_at' => '2026-10-09 20:30:00', 'action' => 'person_created', 'new_value' => ['marker' => 'zone-test-row']]);

    Volt::test('pages.audit.index')
        ->set('dateFrom', '2026-10-09')
        ->set('dateTo', '2026-10-09')
        ->assertSee('zone-test-row');
});

test('a day\'s bounds are converted across a daylight-saving change', function () {
    setSiteZone('America/New_York');

    // 8 March 2026: clocks go forward at 02:00, so the day is 23 hours long.
    expect(SiteTime::startOfDayUtc('2026-03-08'))->toBe('2026-03-08 05:00:00')
        ->and(SiteTime::endOfDayUtc('2026-03-08'))->toBe('2026-03-09 03:59:59');
});

test('Query A counts a lease as past term by the site zone\'s today', function () {
    $relationship = PersonUnitRelationship::factory()->create([
        'contract_end_date' => '2026-10-09',
        'ended_at' => null,
    ]);

    // UTC: today is 9 October, so a lease ending the 9th hasn't passed.
    expect(app(ReconciliationQueries::class)->leasesPastTerm()->pluck('id'))->not->toContain($relationship->id);

    // Manila: it is already the 10th.
    setSiteZone('Asia/Manila');

    expect(SiteTime::today())->toBe('2026-10-10')
        ->and(app(ReconciliationQueries::class)->leasesPastTerm()->pluck('id'))->toContain($relationship->id);
});

test('a new unit\'s default start date is today in the site zone', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('pages.units.create')->assertSet('start_date', '2026-10-09');

    setSiteZone('Asia/Manila');

    Volt::test('pages.units.create')->assertSet('start_date', '2026-10-10');
});

test('a card\'s issued time is shown in the site zone', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());
    $card = IdCard::factory()->create(['issued_at' => '2026-10-09 20:30:00']);

    $this->get(route('id-cards.show', $card))->assertOk()->assertSee('2026-10-09 20:30');

    setSiteZone('Asia/Manila');

    $this->get(route('id-cards.show', $card))->assertOk()->assertSee('2026-10-10 04:30');
});

test('a date with no time of day is never shifted by the zone', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());
    $person = Person::factory()->create(['date_of_birth' => '1990-01-01']);

    // Los Angeles is behind UTC: a birthdate wrongly treated as midnight UTC
    // would read as the day before.
    setSiteZone('America/Los_Angeles');

    $this->get(route('people.show', $person))
        ->assertOk()
        ->assertSee('1990-01-01')
        ->assertDontSee('1989-12-31');
});

test('a zone PHP no longer recognises falls back to UTC instead of breaking pages', function () {
    DB::table('site_settings')->where('id', 1)->update(['timezone' => 'Old/Retired_Zone']);
    SiteTime::forget();

    expect(SiteTime::zone())->toBe('UTC');
});

test('one request reads the setting once, however many times are shown', function () {
    setSiteZone('Asia/Manila');

    DB::enableQueryLog();
    SiteTime::forget();

    foreach (range(1, 50) as $_) {
        SiteTime::format(now());
    }

    expect(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'site_settings'))->count())->toBe(1);
});
