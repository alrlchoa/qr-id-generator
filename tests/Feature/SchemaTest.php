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
use Illuminate\Support\Facades\Hash;

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

it('left-pads a single-character floor_code or unit_number with a zero', function () {
    $unit = Unit::factory()->create([
        'building_code' => null,
        'floor_code' => 'm',
        'unit_number' => '6',
    ]);

    expect($unit->floor_code)->toBe('0M');
    expect($unit->unit_number)->toBe('06');
    expect($unit->unitCode())->toBe('0M06');
});

it('omits the building code from unitCode() when null', function () {
    $unit = Unit::factory()->create(['building_code' => null, 'floor_code' => 'LG', 'unit_number' => '02']);

    expect($unit->unitCode())->toBe('LG02');
});

it('composes unitCode() as building + floor + unit when a building code is set', function () {
    $unit = Unit::factory()->create(['building_code' => 'a', 'floor_code' => '12', 'unit_number' => '23']);

    expect($unit->building_code)->toBe('A');
    expect($unit->unitCode())->toBe('A1223');
});

it('allows the same floor/unit combination in different buildings', function () {
    Unit::factory()->create(['building_code' => 'A', 'floor_code' => '12', 'unit_number' => '01']);
    $other = Unit::factory()->create(['building_code' => 'B', 'floor_code' => '12', 'unit_number' => '01']);

    expect($other->unitCode())->toBe('B1201');
});

it('rejects a duplicate floor/unit combination with no building code', function () {
    Unit::factory()->create(['building_code' => null, 'floor_code' => 'LG', 'unit_number' => '02']);

    expect(fn () => Unit::factory()->create(['building_code' => null, 'floor_code' => 'LG', 'unit_number' => '02']))
        ->toThrow(QueryException::class);
});

it('rejects two live accounts linked to the same person', function () {
    $person = Person::factory()->create();
    User::factory()->create(['person_id' => $person->id]);

    expect(fn () => User::factory()->create(['person_id' => $person->id]))
        ->toThrow(QueryException::class);
});

dataset('check_constraint_violations', [
    // Raw array, not User::factory()->make() — role is now cast to the
    // Role enum, so Eloquent itself would throw a ValueError on an invalid
    // value before it ever reached the database, defeating the point of
    // testing the DB-level backstop.
    'users.role' => [
        'users',
        fn () => [
            'username' => fake()->unique()->userName(),
            'name' => fake()->name(),
            'password' => Hash::make('password'),
            'role' => 'not_a_role',
            'must_change_password' => false,
            'is_active' => true,
        ],
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
    'people.entity_type (invalid value)' => [
        'people',
        fn () => Person::factory()->make(['entity_type' => 'nonprofit'])->toArray(),
    ],
    'people.entity_type_fields (company with a person name)' => [
        'people',
        fn () => Person::factory()->company()->make(['first_name' => 'Not Allowed'])->toArray(),
    ],
    'people.entity_type_fields (natural with no last name)' => [
        'people',
        fn () => Person::factory()->make(['last_name' => null])->toArray(),
    ],
    'person_unit_relationships.type' => [
        'person_unit_relationships',
        fn () => PersonUnitRelationship::factory()->make(['type' => 'squatter'])->toArray(),
    ],
    // Raw arrays, not Unit::factory()->make() — the model's mutators would
    // normalize (pad/uppercase) these values away before they ever reached
    // the database, defeating the point of testing the DB-level backstop.
    'units.building_code (lowercase)' => [
        'units',
        fn () => ['building_code' => 'a', 'floor_code' => '12', 'unit_number' => '01'],
    ],
    'units.floor_code (unpadded, 1 char)' => [
        'units',
        fn () => ['building_code' => null, 'floor_code' => 'M', 'unit_number' => '01'],
    ],
    'units.unit_number (non-digits)' => [
        'units',
        fn () => ['building_code' => null, 'floor_code' => '12', 'unit_number' => 'AB'],
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
