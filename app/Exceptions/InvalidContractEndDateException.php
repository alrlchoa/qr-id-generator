<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * `contract_end_date` is a lease term — it only ever makes sense for a
 * tenant. An owner relationship (primary or co-owner) never carries one,
 * and when one is supplied it must fall strictly after the relationship's
 * own `start_date`. Both checks live in `RelationshipManager`, thrown as
 * this distinct subclass so a caller can route the message to the
 * contract-end-date field specifically, rather than wherever the more
 * general `InvalidArgumentException` (the company/tenant refusal) lands.
 */
class InvalidContractEndDateException extends InvalidArgumentException
{
    //
}
