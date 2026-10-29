<?php

declare(strict_types=1);

namespace App\DomainEvents;

use App\Models\Loan;

abstract class LoanEvent
{
    public function __construct(
        public readonly Loan $loan,
    ) {}
}
