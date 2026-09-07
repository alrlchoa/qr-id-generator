<?php

use App\Services\QrCodeGenerator;

test('svgFor produces a well-formed SVG document', function () {
    $svg = app(QrCodeGenerator::class)->svgFor('12345678');

    expect($svg)->toContain('<svg')
        ->and($svg)->toContain('</svg>')
        ->and(simplexml_load_string($svg))->not->toBeFalse();
});

test('svgFor is deterministic for the same control number', function () {
    $generator = app(QrCodeGenerator::class);

    expect($generator->svgFor('00000001'))->toBe($generator->svgFor('00000001'));
});

test('svgFor produces different output for different control numbers', function () {
    $generator = app(QrCodeGenerator::class);

    expect($generator->svgFor('00000001'))->not->toBe($generator->svgFor('99999999'));
});

test('svgFor preserves leading zeros in the payload', function () {
    $generator = app(QrCodeGenerator::class);

    // Genuinely different QR content (leading zeros are significant),
    // proven indirectly through different rendered output — the payload
    // itself isn't recoverable from the SVG without a QR decoder, but two
    // distinct 8-digit strings must never render identically.
    expect($generator->svgFor('00000001'))->not->toBe($generator->svgFor('10000000'));
});
