<?php

use App\Models\Person;

test('printedName renders "First Middle Last Suffix" for a natural person, not the comma display format', function () {
    $person = Person::factory()->create([
        'first_name' => 'Maria', 'middle_name' => 'Jacobs', 'last_name' => 'Santos', 'suffix' => 'Jr.',
    ]);

    expect($person->printedName())->toBe('Maria Jacobs Santos Jr.');
    expect($person->displayName())->toBe('Santos, Maria Jacobs Jr.');
});

test('printedName omits blank middle name and suffix cleanly', function () {
    $person = Person::factory()->create([
        'first_name' => 'Maria', 'middle_name' => null, 'last_name' => 'Santos', 'suffix' => null,
    ]);

    expect($person->printedName())->toBe('Maria Santos');
});
