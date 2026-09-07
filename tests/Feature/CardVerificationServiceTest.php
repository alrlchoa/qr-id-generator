<?php

use App\Models\IdCard;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\CardVerificationService;
use App\Support\ReaderVerificationSession;

test('verifying a known control number returns the card and records the reader session', function () {
    $reader = User::factory()->reader()->create();
    $card = IdCard::factory()->create(['control_number' => '12345678', 'status' => 'active']);

    $outcome = app(CardVerificationService::class)->verify($reader, '12345678');

    expect($outcome['card']->id)->toBe($card->id);
    expect(app(ReaderVerificationSession::class)->isRecentlyVerified($card->person_id))->toBeTrue();
});

test('verifying an unknown control number logs qr_verify_miss with the attempted value', function () {
    $reader = User::factory()->reader()->create();

    $outcome = app(CardVerificationService::class)->verify($reader, '00000000');

    expect($outcome['card'])->toBeNull();

    $event = SecurityEvent::where('event_type', 'qr_verify_miss')->where('user_id', $reader->id)->first();
    expect($event)->not->toBeNull();
    expect($event->detail['attempted_value'])->toBe('00000000');
});

test('a card that is not active still resolves and still opens the photo window', function () {
    $reader = User::factory()->reader()->create();
    $card = IdCard::factory()->create(['control_number' => '87654321', 'status' => 'revoked']);

    $outcome = app(CardVerificationService::class)->verify($reader, '87654321');

    expect($outcome['card']->status)->toBe('revoked');
    expect(app(ReaderVerificationSession::class)->isRecentlyVerified($card->person_id))->toBeTrue();
});

test('manual entry strips non-digits and zero-pads before lookup', function () {
    $card = IdCard::factory()->create(['control_number' => '00012345', 'status' => 'active']);

    $outcome = app(CardVerificationService::class)->verify(null, '12345');

    expect($outcome['card']->id)->toBe($card->id);
});

test('an unresolvable console-originated verify records no user_id', function () {
    app(CardVerificationService::class)->verify(null, '99999999');

    $event = SecurityEvent::where('event_type', 'qr_verify_miss')->first();
    expect($event->user_id)->toBeNull();
});
