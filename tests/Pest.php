<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// No RefreshDatabase here: these tests hold a row lock open on one database
// connection while a second, independent connection probes it, which needs
// real committed rows visible across connections (see the test file for why).
pest()->extend(TestCase::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Put the system past the first-run wizard (architecture §12).
 *
 * EnsureSystemIsBootstrapped redirects every route to the wizard while no
 * active Superadmin exists, so any test that makes an HTTP request needs
 * the system bootstrapped first — otherwise it asserts against a redirect
 * to /setup rather than the page it meant to test.
 *
 * Deliberately not a global beforeEach: tests that exercise the
 * two-Superadmin invariant count Superadmin rows, and silently seeding two
 * more would break them in a way that looks like a logic bug.
 */
function bootstrapSystem(): void
{
    App\Models\User::factory()->superadmin()->count(2)->create();
}
