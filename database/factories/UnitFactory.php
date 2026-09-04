<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    /**
     * Mirrors the condo's real numbering conventions (e.g. "M06", "LG02",
     * "A1223", "C2321") — the only fixed rule is that the code always ends
     * in the 2-digit unit number.
     */
    public function definition(): array
    {
        $prefix = fake()->randomElement(['M', 'LG', 'A', 'B', 'C']);
        $digits = in_array($prefix, ['M', 'LG'], true) ? 2 : 4;

        // Uniqueness on the digits alone is enough to make the full code
        // unique, since it's the same faker instance across every call.
        $numericPart = fake()->unique()->numerify(str_repeat('#', $digits));

        return [
            'unit_code' => $prefix.$numericPart,
        ];
    }
}
