<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt;

test('a Reader cannot view the onboarding page', function () {
    bootstrapSystem();

    $this->actingAs(User::factory()->reader()->create())->get('/onboarding')->assertForbidden();
});

test('an Admin cannot view the onboarding page — Superadmin only', function () {
    bootstrapSystem();

    $this->actingAs(User::factory()->admin()->create())->get('/onboarding')->assertForbidden();
});

test('a Superadmin can view the onboarding page', function () {
    bootstrapSystem();

    $this->actingAs(User::factory()->superadmin()->create())
        ->get('/onboarding')
        ->assertOk()
        ->assertSeeVolt('pages.onboarding.index');
});

test('a Superadmin can download both templates', function () {
    $this->actingAs(User::factory()->superadmin()->create());

    Volt::test('pages.onboarding.index')->call('downloadPersonsTemplate')->assertOk();
    Volt::test('pages.onboarding.index')->call('downloadUnitsTemplate')->assertOk();
});

test('an uploaded persons file previews without writing anything, then imports on confirm', function () {
    $superadmin = User::factory()->superadmin()->create();
    $this->actingAs($superadmin);

    $header = 'First Name,Last Name,Email,Phone Number,Middle Name,Suffix,Gender,Date of Birth,Place of Birth,Home Address,Landline Number,Emergency Contact Name,Emergency Contact Number,Emergency Contact Relation,Notes';
    $file = UploadedFile::fake()->createWithContent('persons.csv', "{$header}\nJuan,Dela Cruz,juan@example.com,09171234567,,,,,,,,,,,\n");

    $component = Volt::test('pages.onboarding.index')->set('personsFile', $file);

    expect(Person::count())->toBe(0);

    $component->call('stagePersonsImport')->call('importPersons');

    expect(Person::count())->toBe(1);
});

test('a persons file with an error cannot be staged for import', function () {
    $this->actingAs(User::factory()->superadmin()->create());

    $header = 'First Name,Last Name,Email,Phone Number,Middle Name,Suffix,Gender,Date of Birth,Place of Birth,Home Address,Landline Number,Emergency Contact Name,Emergency Contact Number,Emergency Contact Relation,Notes';
    $file = UploadedFile::fake()->createWithContent('persons.csv', "{$header}\n,Dela Cruz,,,,,,,,,,,,,\n");

    $component = Volt::test('pages.onboarding.index')->set('personsFile', $file);

    expect($component->get('personsPreview')['hasErrors'])->toBeTrue();
});

test('an uploaded units file imports the unit and its primary-owner relationship', function () {
    $owner = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'owner@example.com']);
    $this->actingAs(User::factory()->superadmin()->create());

    $file = UploadedFile::fake()->createWithContent(
        'units.csv',
        "Building Code,Floor,Unit Number,Primary Owner ID Number,Date First Owned\nA,5,1,{$owner->user_id_number},2026-01-01\n"
    );

    $component = Volt::test('pages.onboarding.index')->set('unitsFile', $file);

    $component->call('stageUnitsImport')->call('importUnits');

    expect(Unit::count())->toBe(1)
        ->and(Unit::first()->primaryOwnerPersonId())->toBe($owner->id);
});
