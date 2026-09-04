<?php

use App\Models\AuditLog;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\SecurityEvent;
use App\Models\Template;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\QueryException;

it('produces a valid row from every model factory', function () {
    expect(User::factory()->create())->toBeInstanceOf(User::class);
    expect(Person::factory()->create())->toBeInstanceOf(Person::class);
    expect(Unit::factory()->create())->toBeInstanceOf(Unit::class);
    expect(PersonUnitRelationship::factory()->create())->toBeInstanceOf(PersonUnitRelationship::class);
    expect(Template::factory()->create())->toBeInstanceOf(Template::class);
    expect(IdCard::factory()->create())->toBeInstanceOf(IdCard::class);
    expect(AuditLog::factory()->create())->toBeInstanceOf(AuditLog::class);
    expect(SecurityEvent::factory()->create())->toBeInstanceOf(SecurityEvent::class);
});

it('issues a card without a template (Issuance ships before Templates & rendering)', function () {
    $card = IdCard::factory()->create(['template_id' => null]);

    expect($card->template_id)->toBeNull();
});

it('computes IdCard::isValid() from status, never a stored column', function () {
    $active = IdCard::factory()->create(['status' => 'active']);
    $revoked = IdCard::factory()->revoked()->create();

    expect($active->isValid())->toBeTrue();
    expect($revoked->isValid())->toBeFalse();
});

it('treats a relationship as active iff ended_at is null', function () {
    $active = PersonUnitRelationship::factory()->create(['ended_at' => null]);
    $ended = PersonUnitRelationship::factory()->ended()->create();

    expect($active->isActive())->toBeTrue();
    expect($ended->isActive())->toBeFalse();
});

it('lets a person be linked to a new account after their old one is soft-deleted', function () {
    $person = Person::factory()->create();
    $original = User::factory()->create(['person_id' => $person->id]);
    $original->delete();

    $replacement = User::factory()->create(['person_id' => $person->id]);

    expect($replacement->person_id)->toBe($person->id);
});

it('rejects two live accounts linked to the same person', function () {
    $person = Person::factory()->create();
    User::factory()->create(['person_id' => $person->id]);

    expect(fn () => User::factory()->create(['person_id' => $person->id]))
        ->toThrow(QueryException::class);
});

dataset('check_constraint_violations', [
    'users.role' => [
        'users',
        fn () => User::factory()->make(['role' => 'not_a_role'])->toArray(),
    ],
    'people.gender' => [
        'people',
        fn () => Person::factory()->make(['gender' => 'not_a_gender'])->toArray(),
    ],
    'people.user_id_number (too short)' => [
        'people',
        fn () => Person::factory()->make(['user_id_number' => '123'])->toArray(),
    ],
    'people.user_id_number (non-digits)' => [
        'people',
        fn () => Person::factory()->make(['user_id_number' => 'abcdefgh'])->toArray(),
    ],
    'person_unit_relationships.type' => [
        'person_unit_relationships',
        fn () => PersonUnitRelationship::factory()->make(['type' => 'squatter'])->toArray(),
    ],
    'templates.id_type' => [
        'templates',
        fn () => array_merge(
            Template::factory()->make(['id_type' => 'guest'])->toArray(),
            [
                'field_positions_front' => json_encode(['photo' => ['x' => 0, 'y' => 0]]),
                'field_positions_back' => json_encode(['control_number' => ['x' => 0, 'y' => 0]]),
            ],
        ),
    ],
    'id_cards.control_number' => [
        'id_cards',
        fn () => IdCard::factory()->make(['control_number' => '123'])->toArray(),
    ],
    'id_cards.type' => [
        'id_cards',
        fn () => IdCard::factory()->make(['type' => 'guest'])->toArray(),
    ],
    'id_cards.status' => [
        'id_cards',
        fn () => IdCard::factory()->make(['status' => 'pending'])->toArray(),
    ],
    'id_cards.replacement_reason' => [
        'id_cards',
        fn () => IdCard::factory()->make(['replacement_reason' => 'because'])->toArray(),
    ],
    'security_events.event_type' => [
        'security_events',
        fn () => array_merge(
            SecurityEvent::factory()->make(['event_type' => 'mystery'])->toArray(),
            ['detail' => json_encode(['reason' => 'invalid_credentials'])],
        ),
    ],
]);

it('rejects an invalid value via its check constraint', function (string $table, Closure $attributes) {
    expect(fn () => DB::table($table)->insert($attributes()))
        ->toThrow(QueryException::class);
})->with('check_constraint_violations');
