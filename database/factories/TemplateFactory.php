<?php

namespace Database\Factories;

use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    public function definition(): array
    {
        $idType = fake()->randomElement(Template::ID_TYPES);

        return [
            'id_type' => $idType,
            'name' => fake()->words(3, true),
            'overlay_path_front' => 'templates/'.fake()->uuid().'-front.png',
            'overlay_path_back' => 'templates/'.fake()->uuid().'-back.png',
            ...Template::dimensionsFor(Template::ORIENTATION_LANDSCAPE),
            'field_positions_front' => $this->fieldPositionsFor($idType),
            'field_positions_back' => null,
            'is_active' => false,
        ];
    }

    public function portrait(): static
    {
        return $this->state(fn () => Template::dimensionsFor(Template::ORIENTATION_PORTRAIT));
    }

    public function forType(string $idType): static
    {
        return $this->state(fn () => [
            'id_type' => $idType,
            'field_positions_front' => $this->fieldPositionsFor($idType),
        ]);
    }

    /**
     * Activating via the factory bypasses `TemplateManager::activate()`'s
     * own completeness and retire-then-set checks entirely — a raw column
     * set, useful for tests that need an already-active template without
     * exercising that service. `uq_templates_active_per_id_type` still
     * refuses a second active row for the same `id_type`, so a test seeding
     * more than one active template per type needs distinct types.
     */
    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }

    /**
     * @return array<string, array{x: int, y: int, width: int, height: int}>
     */
    private function fieldPositionsFor(string $idType): array
    {
        $positions = [
            'photo' => ['x' => 40, 'y' => 40, 'width' => 200, 'height' => 200],
            'name' => ['x' => 260, 'y' => 40, 'width' => 500, 'height' => 50],
            'qr' => ['x' => 800, 'y' => 400, 'width' => 160, 'height' => 160],
            'role' => ['x' => 260, 'y' => 140, 'width' => 300, 'height' => 30],
        ];

        if ($idType !== 'employee') {
            $positions['unit_number'] = ['x' => 260, 'y' => 180, 'width' => 300, 'height' => 30];
        }

        return $positions;
    }
}
