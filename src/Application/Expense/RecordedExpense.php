<?php

declare(strict_types=1);

namespace App\Application\Expense;

use App\Application\Reference\Category;

final readonly class RecordedExpense
{
    /**
     * @param list<Category> $categoryCandidates filled when the parser could not pick a single category
     */
    public function __construct(
        public ExpenseView $expense,
        public array $categoryCandidates,
    ) {
    }
}
