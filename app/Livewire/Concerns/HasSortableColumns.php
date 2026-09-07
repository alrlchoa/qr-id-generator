<?php

namespace App\Livewire\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Sortable-column state for a screen using `<x-data-table>`, with the sort
 * column resolved through an allowlist rather than straight from the
 * request — the Phase 5 plan's own trap, and the reason Phase 13's audit
 * item ("no query takes a column name from user input") should be a
 * formality rather than a hunt: `orderBy()` interpolates the column name
 * it's given, it does not bind it, so a column name is exactly the kind of
 * value that must never reach it unchecked.
 *
 * A component using this defines `sortableColumns(): array` — the allowed
 * `column => 'table.column'` map — and calls `$this->applySort($query)`
 * when building its query. `sortBy()` is the only way `$sortColumn`
 * changes; a value outside the map is silently ignored rather than
 * applied, so a crafted `wire:click` payload can't smuggle one through.
 */
trait HasSortableColumns
{
    public string $sortColumn = '';

    public string $sortDirection = 'asc';

    /**
     * @return array<string, string> user-facing column key => qualified SQL column
     */
    abstract protected function sortableColumns(): array;

    public function sortBy(string $column): void
    {
        if (! array_key_exists($column, $this->sortableColumns())) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }
    }

    protected function applySort(Builder $query): Builder
    {
        $columns = $this->sortableColumns();
        $column = $columns[$this->sortColumn] ?? null;

        if ($column === null) {
            return $query;
        }

        return $query->orderBy($column, $this->sortDirection === 'desc' ? 'desc' : 'asc');
    }
}
