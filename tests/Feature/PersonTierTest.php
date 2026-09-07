<?php

use App\Models\Person;

test('a minimal person is neither contactable nor cardable', function () {
    $person = Person::factory()->minimal()->create();

    expect($person->isContactable())->toBeFalse()
        ->and($person->isCardable())->toBeFalse();
});

test('contactable requires both mobile_number and email', function () {
    $mobileOnly = Person::factory()->minimal()->create(['mobile_number' => '09171234567']);
    $both = Person::factory()->minimal()->create(['mobile_number' => '09171234567', 'email' => 'ada@example.com']);

    expect($mobileOnly->isContactable())->toBeFalse();
    expect($both->isContactable())->toBeTrue();
});

test('cardable is cumulative: contactable fields plus a photo, natural only', function () {
    $missingPhoto = Person::factory()->minimal()->create(['mobile_number' => '09171234567', 'email' => 'ada@example.com']);
    $cardable = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'ada@example.com', 'photo_path' => 'people-photos/x.jpg']);

    expect($missingPhoto->isCardable())->toBeFalse();
    expect($cardable->isCardable())->toBeTrue();
});

test('a company can never be cardable, even with every field present', function () {
    $company = Person::factory()->company()->create([
        'mobile_number' => '09171234567',
        'email' => 'rep@acme.example',
    ]);

    expect($company->isContactable())->toBeTrue()
        ->and($company->isCardable())->toBeFalse();
});

test('display_name resolves both kinds and is the only name-rendering path', function () {
    $natural = Person::factory()->create(['first_name' => 'Ada', 'middle_name' => null, 'last_name' => 'Lovelace', 'suffix' => null]);
    $company = Person::factory()->company()->create(['legal_name' => 'Acme Holdings Inc.']);

    expect($natural->displayName())->toBe('Ada Lovelace');
    expect($company->displayName())->toBe('Acme Holdings Inc.');
});
