<?php

declare(strict_types=1);

namespace App\Application\Expense;

use App\Application\Participant\Participant;
use App\Application\Rates\RateResolver;
use App\Application\Reference\Category;
use App\Application\UserError;
use App\Domain\Parsing\ParsedExpense;
use App\Domain\Split\SplitType;
use App\Infrastructure\Persistence\CategoryRepository;
use App\Infrastructure\Persistence\ExpenseRepository;
use App\Infrastructure\Persistence\ParticipantRepository;
use LogicException;

final readonly class ExpenseRecorder
{
    public function __construct(
        private ExpenseRepository $expenses,
        private CategoryRepository $categories,
        private ParticipantRepository $participants,
        private RateResolver $rates,
        private string $defaultCategoryCode,
    ) {
    }

    public function record(
        Participant $payer,
        ParsedExpense $parsed,
        string $uuid,
        ?int $recurringId = null,
    ): RecordedExpense {
        if ($parsed->split === SplitType::OtherOnly && count($this->participants->all()) < 2) {
            throw new UserError('Трату «за другого» можно записать, когда зарегистрированы оба участника.');
        }

        $candidates = $this->categories->findByCodes($parsed->categoryCodes);
        $category   = count($candidates) === 1 ? $candidates[0] : $this->defaultCategory();

        $id = $this->expenses->insert(new ExpenseDraft(
            $uuid,
            $payer->id,
            $parsed->spentAt,
            $parsed->amount,
            $this->rates->find($parsed->amount->currency, $parsed->spentAt),
            $category->id,
            $parsed->split,
            self::noteBesides($parsed->note, $category),
            $recurringId,
        ));

        $view = $this->expenses->findView($id) ?? throw new LogicException('Inserted expense is missing.');

        return new RecordedExpense($view, count($candidates) > 1 ? $candidates : []);
    }

    /**
     * The words the user wrote stay as a short comment ("свет", "газ"), unless they only repeat the category title.
     */
    private static function noteBesides(?string $note, Category $category): ?string
    {
        if ($note === null) {
            return null;
        }

        $normalize = static fn (string $text): string => str_replace('ё', 'е', mb_strtolower(trim($text)));

        return $normalize($note) === $normalize($category->title) ? null : $note;
    }

    private function defaultCategory(): Category
    {
        return $this->categories->findByCodes([$this->defaultCategoryCode])[0]
            ?? throw new LogicException(sprintf('Default category "%s" is not seeded.', $this->defaultCategoryCode));
    }
}
