<?php

use App\Models\User;

/**
 * The route is registered only when app()->environment('local') — the same
 * gate the dev seeder uses (CLAUDE.md 25). Tests run under APP_ENV=testing
 * (phpunit.xml), so the route genuinely does not exist in this process,
 * and the only thing provable here is the negative: it's unreachable
 * outside local. That absence *is* the property that matters — a gallery
 * of every component with working demo state is not something a
 * production LAN deployment should ever expose. The positive case (it
 * renders under local) is verified by running it directly, outside Pest.
 */
test('the component preview route does not exist outside the local environment', function () {
    bootstrapSystem();

    $this->actingAs(User::factory()->superadmin()->create());

    $this->get('/dev/components')->assertNotFound();
});
