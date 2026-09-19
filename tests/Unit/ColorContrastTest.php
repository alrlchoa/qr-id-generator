<?php

use App\Support\ColorContrast;

// Phase 16: the rule that picks light or dark navbar text for a colour a
// Superadmin chose.

test('black on white is the WCAG maximum of 21:1', function () {
    expect(round(ColorContrast::contrastRatio('#000000', '#ffffff'), 1))->toBe(21.0)
        ->and(round(ColorContrast::contrastRatio('#ffffff', '#ffffff'), 1))->toBe(1.0);
});

test('white and pale colours keep dark text', function (string $color) {
    expect(ColorContrast::prefersLightText($color))->toBeFalse();
})->with(['#ffffff', '#f3f4f6', '#fde68a', '#facc15', '#a7f3d0']);

test('dark and saturated colours get light text', function (string $color) {
    expect(ColorContrast::prefersLightText($color))->toBeTrue();
})->with(['#000000', '#1e3a8a', '#4f46e5', '#dc2626', '#065f46']);

test('whichever text it picks is the more readable of the two', function (string $color) {
    $light = ColorContrast::contrastRatio($color, ColorContrast::LIGHT_TEXT);
    $dark = ColorContrast::contrastRatio($color, ColorContrast::DARK_TEXT);

    expect(ColorContrast::prefersLightText($color))->toBe($light > $dark);
})->with(['#808080', '#3b82f6', '#22c55e', '#f97316', '#9ca3af']);
