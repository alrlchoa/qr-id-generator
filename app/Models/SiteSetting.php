<?php

namespace App\Models;

use App\Support\ColorContrast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The site's branding (Phase 16): its name, logo and navbar colour. One
 * row, id 1, inserted by its migration — `current()` is how everything
 * reads it, and SiteSettingsManager is the only thing that writes it (every
 * change audited).
 *
 * Each accessor supplies the default for an empty column, so a view never
 * has to know what "not set" looks like: the configured app name, no logo
 * (the neutral mark), a white navbar.
 *
 * Read fresh rather than cached: it's a primary-key lookup a few times per
 * page, and a cache that outlived a save would show stale branding — or,
 * in tests, leak from one test into the next.
 */
class SiteSetting extends Model
{
    public const DEFAULT_NAVBAR_COLOR = '#ffffff';

    /** Tailwind's gray-100 — the hard-coded `bg-gray-100` this column replaces. */
    public const DEFAULT_PAGE_BACKGROUND_COLOR = '#f3f4f6';

    /** What every time is shown in until a Superadmin picks a zone (Phase 22). */
    public const DEFAULT_TIMEZONE = 'UTC';

    public const MAX_NAME_LENGTH = 60;

    protected $table = 'site_settings';

    public static function current(): self
    {
        return self::query()->find(1) ?? new self;
    }

    public function siteName(): string
    {
        return $this->site_name ?: (string) config('app.name');
    }

    /**
     * `siteName()` reduced to a filesystem/URL-safe slug — the site name
     * itself is free text (rule: printable characters only, up to
     * `MAX_NAME_LENGTH`), so anything that puts it into a filename (the
     * bulk export and single-card print zips, Phase 19) reads through here
     * rather than interpolating the raw name. Never empty: `Str::slug()`
     * on an all-symbols/emoji name falls back to its own default, and
     * `siteName()` itself never returns an empty string.
     */
    public function filenameSlug(): string
    {
        return Str::slug($this->siteName()) ?: 'site';
    }

    public function navbarColor(): string
    {
        return $this->navbar_color ?: self::DEFAULT_NAVBAR_COLOR;
    }

    /** True when the navbar is dark enough that its text should be light. */
    public function navbarIsDark(): bool
    {
        return ColorContrast::prefersLightText($this->navbarColor());
    }

    public function pageBackgroundColor(): string
    {
        return $this->page_background_color ?: self::DEFAULT_PAGE_BACKGROUND_COLOR;
    }

    /**
     * The zone times are shown in. A stored name PHP no longer recognises
     * (a zone removed from a newer tz database) falls back to the default
     * rather than breaking every page that shows a time.
     */
    public function timezone(): string
    {
        $zone = (string) $this->timezone;

        return in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : self::DEFAULT_TIMEZONE;
    }

    /**
     * The logo's public URL, versioned by the last save so browsers can
     * cache it forever and still see a new one. Null when there's no logo.
     */
    public function logoUrl(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return route('branding.logo', ['v' => $this->updated_at?->timestamp]);
    }
}
