<?php

use App\Exceptions\UnitAtCapacityException;
use App\Models\AuditLog;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\Unit;
use App\Models\User;
use App\Services\IdCardLifecycleManager;

function lifecycle(): IdCardLifecycleManager
{
    return app(IdCardLifecycleManager::class);
}

test('marking a card lost sets its status to lost and issues a replacement with replacement_reason lost', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    $old = IdCard::factory()->create(['unit_id' => $unit->id, 'type' => 'owner', 'status' => 'active']);

    $new = lifecycle()->markLost($actor, $old, 'Reported lost at the gate.');

    expect($old->fresh()->status)->toBe('lost');
    expect($new->status)->toBe('active');
    expect($new->replaces_id_card_id)->toBe($old->id);
    expect($new->replacement_reason)->toBe('lost');
    expect($new->person_id)->toBe($old->person_id);
    expect($new->unit_id)->toBe($old->unit_id);
    expect($new->type)->toBe($old->type);

    expect(AuditLog::where('action', 'id_marked_lost')->where('subject_id', $old->id)->exists())->toBeTrue();
    expect(AuditLog::where('action', 'id_replaced')->where('subject_id', $new->id)->exists())->toBeTrue();
});

test('revoking a card sets its status to revoked and issues no replacement', function () {
    $actor = User::factory()->admin()->create();
    $card = IdCard::factory()->create(['status' => 'active']);

    $result = lifecycle()->revoke($actor, $card, 'Disciplinary action.');

    expect($result->status)->toBe('revoked');
    expect(IdCard::where('replaces_id_card_id', $card->id)->exists())->toBeFalse();
    expect(AuditLog::where('action', 'id_revoked')->where('subject_id', $card->id)->exists())->toBeTrue();
});

test('expiring a tenant card directly sets its status to expired and issues no replacement', function () {
    $actor = User::factory()->admin()->create();
    $card = IdCard::factory()->create(['status' => 'active', 'type' => 'tenant']);

    $result = lifecycle()->expire($actor, $card, 'Lease ran out.');

    expect($result->status)->toBe('expired');
    expect(IdCard::where('replaces_id_card_id', $card->id)->exists())->toBeFalse();
    expect(AuditLog::where('action', 'id_expired')->where('subject_id', $card->id)->exists())->toBeTrue();
});

test('expiring an owner or employee card is refused — only a tenant\'s lease expires', function (string $type) {
    $actor = User::factory()->admin()->create();
    $card = IdCard::factory()->create(['status' => 'active', 'type' => $type, 'unit_id' => $type === 'employee' ? null : Unit::factory()->create()->id]);

    expect(fn () => lifecycle()->expire($actor, $card, 'Attempted.'))
        ->toThrow(InvalidArgumentException::class);

    expect($card->fresh()->status)->toBe('active');
    expect(AuditLog::where('action', 'id_expired')->where('subject_id', $card->id)->exists())->toBeFalse();
})->with(['owner', 'employee']);

test('expireForClosure still expires an owner card — the §5.3 cascade is untouched by the manual-button restriction', function () {
    $actor = User::factory()->admin()->create();
    $card = IdCard::factory()->create(['status' => 'active', 'type' => 'owner']);

    lifecycle()->expireForClosure($actor, $card);

    expect($card->fresh()->status)->toBe('expired');
});

test('only an active card can transition — a second markLost is refused', function () {
    $actor = User::factory()->admin()->create();
    $card = IdCard::factory()->create(['status' => 'active']);

    lifecycle()->markLost($actor, $card, 'Lost once.');

    expect(fn () => lifecycle()->markLost($actor, $card, 'Lost again?'))
        ->toThrow(InvalidArgumentException::class);
});

test('replace() retires the old card before counting capacity, so a unit at 6/6 accepts its own occupant\'s replacement', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();

    $occupants = [];
    foreach (range(1, 6) as $i) {
        $card = IdCard::factory()->create(['unit_id' => $unit->id, 'type' => 'tenant', 'status' => 'active']);
        $occupants[] = $card;
    }

    // The unit is genuinely at 6/6 — a fresh issuance would be refused.
    expect($unit->nonPrimaryOwnerActiveCardCount())->toBe(6);

    $new = lifecycle()->replace($actor, $occupants[0], oldStatus: 'replaced', replacementReason: 'name_change');

    expect($new->status)->toBe('active');
    expect($occupants[0]->fresh()->status)->toBe('replaced');
    expect($unit->nonPrimaryOwnerActiveCardCount())->toBe(6);
});

test('replacing an employee card touches no unit and needs no capacity check', function () {
    $actor = User::factory()->superadmin()->create();
    $old = IdCard::factory()->employee()->create(['status' => 'active']);

    $new = lifecycle()->replace($actor, $old, oldStatus: 'replaced', replacementReason: 'employment_change');

    expect($new->unit_id)->toBeNull();
    expect($new->type)->toBe('employee');
});

test('a replacement that would push a unit past six occupants is refused', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    $primaryOwner = Person::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['person_id' => $primaryOwner->id, 'unit_id' => $unit->id]);

    // Six occupant cards belonging to people other than the primary owner.
    foreach (range(1, 6) as $i) {
        IdCard::factory()->create(['unit_id' => $unit->id, 'type' => 'tenant', 'status' => 'active']);
    }

    // A 7th person's card is added directly (bypassing IssuanceManager, to
    // set up an already-over-capacity fixture) so replacing it exercises
    // the refusal path rather than the ordinary net-zero swap above.
    $overCap = IdCard::factory()->create(['unit_id' => $unit->id, 'type' => 'tenant', 'status' => 'lost']);
    $overCap->forceFill(['status' => 'active'])->save();

    expect($unit->nonPrimaryOwnerActiveCardCount())->toBe(7);

    expect(fn () => lifecycle()->replace($actor, $overCap, oldStatus: 'replaced', replacementReason: 'name_change'))
        ->toThrow(UnitAtCapacityException::class);
});
