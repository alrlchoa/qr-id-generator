<?php

namespace App\Support;

use App\Models\SiteSetting;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * The program's time zone (Phase 22) — a Superadmin's setting, and only
 * ever a way of *showing* time.
 *
 * Every timestamp column holds UTC and `config('app.timezone')` stays
 * 'UTC': those columns carry no zone, so changing the app's own setting
 * would silently re-read every row already written as local time. A stored
 * time is converted here, on its way to the screen. Date-only columns
 * (birthdate, start and contract dates) are calendar days, not moments —
 * they're never passed through this.
 *
 * `zone()` reads the setting once per application instance, so a table of
 * fifty rows costs one query, not fifty. SiteSettingsManager forgets it
 * after a change.
 */
class SiteTime
{
    private const BINDING = 'site.timezone';

    public static function zone(): string
    {
        if (! app()->bound(self::BINDING)) {
            app()->instance(self::BINDING, SiteSetting::current()->timezone());
        }

        return app(self::BINDING);
    }

    public static function forget(): void
    {
        app()->forgetInstance(self::BINDING);
    }

    /** The moment, shown in the site zone. Null stays null. */
    public static function local(?CarbonInterface $moment): ?Carbon
    {
        return $moment === null ? null : Carbon::instance($moment)->setTimezone(self::zone());
    }

    /** A stored timestamp as text in the site zone; empty for none. */
    public static function format(?CarbonInterface $moment, string $format = 'Y-m-d H:i'): string
    {
        return self::local($moment)?->format($format) ?? '';
    }

    /** Right now, in the site zone. */
    public static function now(): Carbon
    {
        return Carbon::now(self::zone());
    }

    /**
     * Today's date in the site zone, as 'Y-m-d'. The one meaning of
     * "today" anywhere in the program: the reconciliation dashboard's
     * Query A (CLAUDE.md rule 3) and every default start date use it.
     */
    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /** The first second of a site-zone day, as UTC text a query can compare to. */
    public static function startOfDayUtc(string $date): string
    {
        return Carbon::parse($date, self::zone())->startOfDay()->utc()->format('Y-m-d H:i:s');
    }

    /** The last second of a site-zone day, as UTC text a query can compare to. */
    public static function endOfDayUtc(string $date): string
    {
        return Carbon::parse($date, self::zone())->endOfDay()->utc()->format('Y-m-d H:i:s');
    }

    /**
     * Every zone PHP knows, grouped by region for a picker: label keyed by
     * name, each with its UTC offset right now ("Asia/Manila (UTC+08:00)").
     *
     * @return array<string, array<string, string>>
     */
    public static function options(): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $groups = [];

        foreach (DateTimeZone::listIdentifiers() as $id) {
            $region = str_contains($id, '/') ? strstr($id, '/', true) : 'UTC';
            $offset = (new DateTimeZone($id))->getOffset($now);
            $sign = $offset < 0 ? '-' : '+';
            $groups[$region][$id] = sprintf('%s (UTC%s%02d:%02d)', $id, $sign, intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60));
        }

        return $groups;
    }
}
