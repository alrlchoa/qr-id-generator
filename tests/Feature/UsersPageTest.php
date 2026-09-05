<?php

use App\Enums\Role;
use App\Models\User;
use Livewire\Volt\Volt;

test('a Reader cannot view the users page', function () {
    $reader = User::factory()->reader()->create();

    $this->actingAs($reader);

    $this->get('/users')->assertForbidden();
});

test('an Admin cannot view the users page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    $this->get('/users')->assertForbidden();
});

test('a Superadmin can view the users page and create a third Superadmin', function () {
    $superadminA = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();

    $this->actingAs($superadminA);

    $this->get('/users')->assertOk()->assertSeeVolt('pages.users.index');

    Volt::test('pages.users.index')
        ->set('username', 'third.super')
        ->set('name', 'Third Super')
        ->set('role', Role::Superadmin->value)
        ->call('createAccount')
        ->assertHasNoErrors();

    $created = User::where('username', 'third.super')->firstOrFail();

    expect($created->role)->toBe(Role::Superadmin)
        ->and($created->must_change_password)->toBeTrue();
});

test('the GUI enforces the two-active-superadmin invariant, not just the service', function () {
    $superadminA = User::factory()->superadmin()->create();
    $superadminB = User::factory()->superadmin()->create();

    $this->actingAs($superadminA);

    Volt::test('pages.users.index')
        ->call('toggleActive', $superadminB->id)
        ->assertHasErrors('invariant');

    expect($superadminB->refresh()->is_active)->toBeTrue();
});

test('a superadmin cannot disable or change the role of their own row from the GUI', function () {
    $superadminA = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();

    $this->actingAs($superadminA);

    Volt::test('pages.users.index')
        ->call('toggleActive', $superadminA->id)
        ->assertHasErrors('invariant');

    expect($superadminA->refresh()->is_active)->toBeTrue();
});
