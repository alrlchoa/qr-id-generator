<?php

use App\Services\OnboardingCsv;
use Illuminate\Http\UploadedFile;

function csvFile(string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent('data.csv', $content);
}

test('parses a well-formed file into row-numbered data', function () {
    $rows = app(OnboardingCsv::class)->parse(csvFile("A,B\nfoo,bar\nbaz,qux\n"), ['A', 'B']);

    expect($rows)->toHaveCount(2)
        ->and($rows[2])->toBe(['A' => 'foo', 'B' => 'bar'])
        ->and($rows[3])->toBe(['A' => 'baz', 'B' => 'qux']);
});

test('a byte-order mark is stripped before headers are matched', function () {
    $rows = app(OnboardingCsv::class)->parse(csvFile("\xEF\xBB\xBFA,B\nfoo,bar\n"), ['A', 'B']);

    expect($rows[2])->toBe(['A' => 'foo', 'B' => 'bar']);
});

test('headers match case-insensitively and in any order', function () {
    $rows = app(OnboardingCsv::class)->parse(csvFile("b,a\n1,2\n"), ['A', 'B']);

    expect($rows[2])->toBe(['A' => '2', 'B' => '1']);
});

test('a missing header refuses the whole file', function () {
    app(OnboardingCsv::class)->parse(csvFile("A\n1\n"), ['A', 'B']);
})->throws(InvalidArgumentException::class, 'B');

test('an unknown header refuses the whole file', function () {
    app(OnboardingCsv::class)->parse(csvFile("A,B,C\n1,2,3\n"), ['A', 'B']);
})->throws(InvalidArgumentException::class, 'C');

test('non-UTF-8 content is refused', function () {
    app(OnboardingCsv::class)->parse(csvFile("A,B\n\xD1,x\n"), ['A', 'B']);
})->throws(InvalidArgumentException::class);

test('fully blank lines are skipped, and empty cells become null', function () {
    $rows = app(OnboardingCsv::class)->parse(csvFile("A,B\nfoo,\n\n,\nbaz,qux\n"), ['A', 'B']);

    expect($rows)->toHaveKeys([2, 5])
        ->and($rows[2])->toBe(['A' => 'foo', 'B' => null]);
});

test('a file over the row cap is refused', function () {
    $lines = "A,B\n";

    for ($i = 0; $i < 1001; $i++) {
        $lines .= "x,y\n";
    }

    app(OnboardingCsv::class)->parse(csvFile($lines), ['A', 'B']);
})->throws(InvalidArgumentException::class, '1000');

test('buildCsv quotes only fields that need it, never a bare space', function () {
    $csv = app(OnboardingCsv::class)->buildCsv(['Name', 'Note'], [
        ['Name' => 'Juan Dela Cruz', 'Note' => 'has, a comma'],
    ]);

    expect($csv)->toContain('Juan Dela Cruz,"has, a comma"')
        ->and($csv)->not->toContain('"Juan Dela Cruz"');
});
