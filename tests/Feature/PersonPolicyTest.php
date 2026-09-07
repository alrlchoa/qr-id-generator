<?php

use App\Models\Person;
use App\Models\User;

test('viewAny is allowed for Superadmin and Admin, refused for Reader', function () {
    expect(User::factory()->superadmin()->make()->can('viewAny', Person::class))->toBeTrue();
    expect(User::factory()->admin()->make()->can('viewAny', Person::class))->toBeTrue();
    expect(User::factory()->reader()->make()->can('viewAny', Person::class))->toBeFalse();
});

test('create is allowed for Superadmin and Admin, refused for Reader', function () {
    expect(User::factory()->superadmin()->make()->can('create', Person::class))->toBeTrue();
    expect(User::factory()->admin()->make()->can('create', Person::class))->toBeTrue();
    expect(User::factory()->reader()->make()->can('create', Person::class))->toBeFalse();
});

test('view and update are allowed for Superadmin and Admin, refused for Reader', function () {
    $person = Person::factory()->create();

    expect(User::factory()->superadmin()->make()->can('view', $person))->toBeTrue();
    expect(User::factory()->admin()->make()->can('view', $person))->toBeTrue();
    expect(User::factory()->reader()->make()->can('view', $person))->toBeFalse();

    expect(User::factory()->superadmin()->make()->can('update', $person))->toBeTrue();
    expect(User::factory()->admin()->make()->can('update', $person))->toBeTrue();
    expect(User::factory()->reader()->make()->can('update', $person))->toBeFalse();
});

test('delete is Superadmin-only', function () {
    $person = Person::factory()->create();

    expect(User::factory()->superadmin()->make()->can('delete', $person))->toBeTrue();
    expect(User::factory()->admin()->make()->can('delete', $person))->toBeFalse();
    expect(User::factory()->reader()->make()->can('delete', $person))->toBeFalse();
});
