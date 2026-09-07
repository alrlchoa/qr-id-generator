<?php

use App\Models\AuditLog;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

test('changing a name field with no active cards saves immediately, no confirmation needed', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create(['first_name' => 'Original']);

    Volt::test('pages.people.show', ['person' => $person])
        ->set('first_name', 'Updated')
        ->call('save')
        ->assertHasNoErrors();

    expect($person->fresh()->first_name)->toBe('Updated');
});

test('changing a non-printed field never triggers reissue, even with an active card', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create();
    IdCard::factory()->create(['person_id' => $person->id, 'status' => 'active']);

    Volt::test('pages.people.show', ['person' => $person])
        ->set('notes', 'A plain correction.')
        ->call('save')
        ->assertHasNoErrors();

    expect($person->fresh()->notes)->toBe('A plain correction.');
    expect(IdCard::where('person_id', $person->id)->count())->toBe(1);
});

test('changing a name field while an active card exists stages a reissue instead of saving', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create(['first_name' => 'Original']);
    $card = IdCard::factory()->create(['person_id' => $person->id, 'status' => 'active']);

    $component = Volt::test('pages.people.show', ['person' => $person])
        ->set('first_name', 'Updated')
        ->call('save')
        ->assertHasNoErrors();

    // Nothing committed yet — the confirmation gate has no partial save.
    expect($person->fresh()->first_name)->toBe('Original');
    expect($card->fresh()->status)->toBe('active');
    expect($component->get('confirmingReissue'))->toBeTrue();
    expect($component->get('reissuePreviewCards'))->toHaveCount(1);
});

test('confirming the reissue commits the data change and replaces every active card, atomically', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $unit = Unit::factory()->create();
    $person = Person::factory()->create(['first_name' => 'Original']);
    $card1 = IdCard::factory()->create(['person_id' => $person->id, 'unit_id' => $unit->id, 'type' => 'owner', 'status' => 'active']);
    $card2 = IdCard::factory()->employee()->create(['person_id' => $person->id, 'status' => 'active']);

    Volt::test('pages.people.show', ['person' => $person])
        ->set('first_name', 'Updated')
        ->call('save')
        ->call('confirmReissue')
        ->assertHasNoErrors();

    expect($person->fresh()->first_name)->toBe('Updated');
    expect($card1->fresh()->status)->toBe('replaced');
    expect($card2->fresh()->status)->toBe('replaced');

    $newCards = IdCard::where('person_id', $person->id)->where('status', 'active')->get();
    expect($newCards)->toHaveCount(2);
    expect($newCards->pluck('replacement_reason')->unique()->all())->toBe(['name_change']);

    expect(AuditLog::where('action', 'person_data_updated')->where('subject_id', $person->id)->exists())->toBeTrue();
    expect(AuditLog::where('action', 'id_replaced')->count())->toBe(2);
});

test('changing the photo while an active card exists stages a reissue with reason photo_change', function () {
    Storage::fake('local');
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create();
    IdCard::factory()->create(['person_id' => $person->id, 'status' => 'active']);

    Volt::test('pages.people.show', ['person' => $person])
        ->set('photo', UploadedFile::fake()->image('photo.jpg', 400, 400))
        ->call('save')
        ->call('confirmReissue')
        ->assertHasNoErrors();

    $newCard = IdCard::where('person_id', $person->id)->where('status', 'active')->first();
    expect($newCard->replacement_reason)->toBe('photo_change');
});

test('resetForm discards a staged reissue confirmation along with everything else', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create(['first_name' => 'Original']);
    IdCard::factory()->create(['person_id' => $person->id, 'status' => 'active']);

    Volt::test('pages.people.show', ['person' => $person])
        ->set('first_name', 'Updated')
        ->call('save')
        ->call('resetForm')
        ->assertSet('first_name', 'Original')
        ->assertSet('confirmingReissue', false)
        ->assertSet('reissuePreviewCards', []);
});
