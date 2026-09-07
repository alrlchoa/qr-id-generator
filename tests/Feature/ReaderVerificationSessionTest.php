<?php

use App\Support\ReaderVerificationSession;

test('a person is not recently verified until recorded', function () {
    expect(app(ReaderVerificationSession::class)->isRecentlyVerified(1))->toBeFalse();
});

test('recording a person makes them recently verified', function () {
    $session = app(ReaderVerificationSession::class);

    $session->record(42);

    expect($session->isRecentlyVerified(42))->toBeTrue();
});

test('recording one person never marks a different person as verified', function () {
    $session = app(ReaderVerificationSession::class);

    $session->record(1);

    expect($session->isRecentlyVerified(2))->toBeFalse();
});

test('the window expires after 60 seconds', function () {
    $session = app(ReaderVerificationSession::class);

    $session->record(7);
    expect($session->isRecentlyVerified(7))->toBeTrue();

    $this->travel(61)->seconds();

    expect($session->isRecentlyVerified(7))->toBeFalse();
});

test('at exactly 60 seconds the window still holds', function () {
    $session = app(ReaderVerificationSession::class);

    $session->record(7);

    $this->travel(60)->seconds();

    expect($session->isRecentlyVerified(7))->toBeTrue();
});
