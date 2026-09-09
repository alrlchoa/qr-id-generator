<?php

use App\Models\IdCard;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\Unit;
use App\Models\User;
use App\Services\TemplateManager;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

beforeEach(function () {
    Storage::fake('local');
    bootstrapSystem();
});

test('a Reader cannot reach the id-cards index', function () {
    $this->actingAs(User::factory()->reader()->create());

    $this->get(route('id-cards.index'))->assertForbidden();
});

test('an Admin can issue an owner card and lands on its show page', function () {
    $this->actingAs(User::factory()->admin()->create());
    $person = Person::factory()->create();
    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $unit->id, 'type' => 'owner', 'start_date' => '2026-01-01']);

    Volt::test('pages.id-cards.issue')
        ->set('person_id_number', $person->user_id_number)
        ->call('issueOwnerOrTenant')
        ->assertRedirect();

    $card = IdCard::where('person_id', $person->id)->firstOrFail();
    expect($card->type)->toBe('owner');
    expect($card->status)->toBe('active');
});

test('issuing against a person with no active relationship shows an error, not a 500', function () {
    $this->actingAs(User::factory()->admin()->create());
    $person = Person::factory()->create();

    Volt::test('pages.id-cards.issue')
        ->set('person_id_number', $person->user_id_number)
        ->call('issueOwnerOrTenant')
        ->assertHasErrors('person_id_number');

    expect(IdCard::where('person_id', $person->id)->exists())->toBeFalse();
});

test('an Admin cannot issue an employee card — the tab is hidden and the ability is refused', function () {
    $this->actingAs(User::factory()->admin()->create());
    $person = Person::factory()->create();

    Volt::test('pages.id-cards.issue')
        ->set('mode', 'employee')
        ->set('person_id_number', $person->user_id_number)
        ->call('issueEmployee')
        ->assertForbidden();
});

test('a Superadmin can issue an employee card with position and department', function () {
    $this->actingAs(User::factory()->superadmin()->create());
    $person = Person::factory()->create();

    Volt::test('pages.id-cards.issue')
        ->set('mode', 'employee')
        ->set('person_id_number', $person->user_id_number)
        ->set('position', 'Guard')
        ->set('department', 'Security')
        ->call('issueEmployee')
        ->assertRedirect();

    $card = IdCard::where('person_id', $person->id)->firstOrFail();
    expect($card->type)->toBe('employee');
    expect($card->unit_id)->toBeNull();
    expect($card->position)->toBe('Guard');
});

test('the newly issued card carries the currently active template for its type', function () {
    $this->actingAs($actor = User::factory()->superadmin()->create());
    $template = completeTemplate(app(TemplateManager::class), $actor, 'owner');
    app(TemplateManager::class)->activate($actor, $template);

    $person = Person::factory()->create();
    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $unit->id, 'type' => 'owner', 'start_date' => '2026-01-01']);

    Volt::test('pages.id-cards.issue')
        ->set('person_id_number', $person->user_id_number)
        ->call('issueOwnerOrTenant');

    $card = IdCard::where('person_id', $person->id)->firstOrFail();
    expect($card->template_id)->toBe($template->id);
});

test('marking a card lost issues a replacement and redirects to it', function () {
    $this->actingAs(User::factory()->admin()->create());
    $card = IdCard::factory()->create(['status' => 'active']);

    $component = Volt::test('pages.id-cards.show', ['idCard' => $card])
        ->call('stage', 'lost')
        ->set('reason', 'Left it on the bus')
        ->call('confirmStaged');

    $component->assertRedirect();
    expect($card->fresh()->status)->toBe('lost');

    $replacement = IdCard::where('replaces_id_card_id', $card->id)->first();
    expect($replacement)->not->toBeNull();
    expect($replacement->status)->toBe('active');
});

test('revoking a card does not issue a replacement and stays on the same page', function () {
    $this->actingAs(User::factory()->admin()->create());
    $card = IdCard::factory()->create(['status' => 'active']);

    Volt::test('pages.id-cards.show', ['idCard' => $card])
        ->call('stage', 'revoke')
        ->set('reason', 'No longer entitled')
        ->call('confirmStaged')
        ->assertNoRedirect();

    expect($card->fresh()->status)->toBe('revoked');
    expect(IdCard::where('replaces_id_card_id', $card->id)->exists())->toBeFalse();
});

test('confirming a staged action without a reason is refused', function () {
    $this->actingAs(User::factory()->admin()->create());
    $card = IdCard::factory()->create(['status' => 'active']);

    Volt::test('pages.id-cards.show', ['idCard' => $card])
        ->call('stage', 'expire')
        ->set('reason', '')
        ->call('confirmStaged')
        ->assertHasErrors('reason');

    expect($card->fresh()->status)->toBe('active');
});

test('a Reader cannot render a card front — even one they could verify', function () {
    $this->actingAs(User::factory()->reader()->create());
    $card = IdCard::factory()->create();

    $this->get(route('id-cards.render.front', $card))->assertForbidden();
});

test('an Admin can render a card front once a template is attached', function () {
    $this->actingAs($actor = User::factory()->admin()->create());
    $superadmin = User::factory()->superadmin()->create();
    $template = completeTemplate(app(TemplateManager::class), $superadmin, 'owner');
    $card = IdCard::factory()->create(['type' => 'owner', 'template_id' => $template->id]);

    $response = $this->get(route('id-cards.render.front', $card));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('image/png');
});

test('rendering a card with no template on record 404s instead of erroring', function () {
    $this->actingAs(User::factory()->admin()->create());
    $card = IdCard::factory()->create(['template_id' => null]);

    $this->get(route('id-cards.render.front', $card))->assertNotFound();
});
