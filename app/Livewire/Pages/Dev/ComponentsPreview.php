<?php

namespace App\Livewire\Pages\Dev;

use App\Livewire\Concerns\HasSortableColumns;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Phase 5's own "done when": the component library renders in a Livewire
 * component-preview route. Every component from docs/design/wireframes.md
 * appears here, wired up enough to actually demonstrate it working — the
 * data table's sort is real (HasSortableColumns, not a static screenshot),
 * the toast fires from a real dispatch, the confirm dialog really opens.
 *
 * A deliberate exception to this codebase's Volt single-file-component
 * convention: it's a full class specifically so HasSortableColumns has a
 * consumer PHPStan's `paths` (app/ only) can actually see. Every other
 * consumer of this trait will be a Volt SFC embedded in a `.blade.php`
 * file — invisible to static analysis by construction, since PHPStan
 * cannot parse Blade — which makes the trait read as "used zero times"
 * with no real example anywhere in `app/` to prove otherwise. This page
 * is that example, not a style change for the rest of the codebase.
 *
 * Sample data uses this project's own vocabulary (unit codes, control
 * numbers, card statuses) rather than placeholder names — the point is to
 * preview how these components look holding what this app actually shows,
 * not generic filler.
 */
#[Layout('layouts.app')]
class ComponentsPreview extends Component
{
    use HasSortableColumns;

    /** @var array<int, array{control_number: string, unit: string, type: string, status: string}> */
    private array $sampleCards = [
        ['control_number' => '00451234', 'unit' => 'A0106', 'type' => 'owner', 'status' => 'active'],
        ['control_number' => '00998877', 'unit' => 'A0106', 'type' => 'tenant', 'status' => 'expired'],
        ['control_number' => '00112233', 'unit' => 'B0203', 'type' => 'owner', 'status' => 'lost'],
        ['control_number' => '00556677', 'unit' => 'B0203', 'type' => 'tenant', 'status' => 'revoked'],
        ['control_number' => '00223344', 'unit' => 'C0501', 'type' => 'employee', 'status' => 'replaced'],
    ];

    protected function sortableColumns(): array
    {
        return [
            'control_number' => 'control_number',
            'unit' => 'unit',
            'type' => 'type',
            'status' => 'status',
        ];
    }

    /** @return array<int, array{control_number: string, unit: string, type: string, status: string}> */
    public function sampleRows(): array
    {
        $rows = $this->sampleCards;

        if ($this->sortColumn !== '') {
            usort($rows, fn ($a, $b) => $this->sortDirection === 'desc'
                ? $b[$this->sortColumn] <=> $a[$this->sortColumn]
                : $a[$this->sortColumn] <=> $b[$this->sortColumn]);
        }

        return $rows;
    }

    public function fireToast(): void
    {
        $this->dispatch('preview-toast-fired');
    }

    public function render()
    {
        return view('livewire.pages.dev.components');
    }
}
