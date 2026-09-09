<?php

namespace App\Console\Commands;

use App\Models\PersonUnitRelationship;
use App\Services\RelationshipManager;
use Illuminate\Console\Command;

/**
 * One-time data fix, not a recurring job (architecture §7 — nothing in this
 * system runs unattended). `RelationshipManager::updateContractEndDate()`
 * now refuses to *set* a contract end date on an owner relationship, but
 * that guard only stops new bad data — any row an admin gave one to before
 * the guard existed needs clearing by hand, once, per environment.
 */
class ClearOwnerContractEndDates extends Command
{
    protected $signature = 'relationships:clear-owner-contract-dates';

    protected $description = 'One-time fix: clear contract_end_date on any owner relationship — only a tenant lease has one';

    public function handle(RelationshipManager $relationships): int
    {
        $affected = PersonUnitRelationship::where('type', 'owner')->whereNotNull('contract_end_date')->get();

        if ($affected->isEmpty()) {
            $this->components->info('Nothing to fix — no owner relationship carries a contract end date.');

            return self::SUCCESS;
        }

        foreach ($affected as $relationship) {
            $relationships->updateContractEndDate(null, $relationship, null, actingAs: 'console');
        }

        $this->components->info("Cleared contract_end_date on {$affected->count()} owner relationship(s).");

        return self::SUCCESS;
    }
}
