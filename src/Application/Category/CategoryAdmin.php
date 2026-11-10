<?php

declare(strict_types=1);

namespace App\Application\Category;

use App\Application\Reference\Category;
use App\Application\UserError;
use App\Infrastructure\Persistence\CategoryRepository;

/**
 * User-managed expense types: add, rename, change keywords, hide and show again. Nothing is ever deleted, because
 * recorded expenses keep pointing at their category.
 */
final readonly class CategoryAdmin
{
    private const int TITLE_LENGTH   = 64;
    private const int KEYWORD_LENGTH = 32;
    private const int MAX_KEYWORDS   = 40;

    public function __construct(
        private CategoryRepository $categories,
        private string $defaultCategoryCode,
    ) {
    }

    public function create(string $title, string $keywords): Category
    {
        $title = $this->title($title, null);
        $words = $this->keywords($keywords);
        $id    = $this->categories->create('user-' . bin2hex(random_bytes(6)), $title, $words);

        return $this->existing($id);
    }

    public function update(int $id, string $title, string $keywords): Category
    {
        $this->existing($id);
        $this->categories->update($id, $this->title($title, $id), $this->keywords($keywords));

        return $this->existing($id);
    }

    public function setActive(int $id, bool $active): Category
    {
        $category = $this->existing($id);

        if (!$active && $category->code === $this->defaultCategoryCode) {
            throw new UserError(
                '«%title%» нельзя скрыть: туда попадают траты без категории.',
                ['%title%' => $category->title],
            );
        }

        if ($active) {
            $this->title($category->title, $id);
        }

        $this->categories->setActive($id, $active);

        return $this->existing($id);
    }

    public function isDefault(Category $category): bool
    {
        return $category->code === $this->defaultCategoryCode;
    }

    private function existing(int $id): Category
    {
        return $this->categories->find($id) ?? throw new UserError('Такой категории нет.');
    }

    private function title(string $title, ?int $exceptId): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');

        if ($title === '' || mb_strlen($title) > self::TITLE_LENGTH) {
            throw new UserError('Название нужно от 1 до %max% символов.', ['%max%' => self::TITLE_LENGTH]);
        }

        foreach ($this->categories->findActive() as $category) {
            if ($category->id !== $exceptId && mb_strtolower($category->title) === mb_strtolower($title)) {
                throw new UserError('Категория «%title%» уже есть.', ['%title%' => $category->title]);
            }
        }

        return $title;
    }

    /**
     * "Кафе, ресторан  бар" -> ["кафе", "ресторан", "бар"]: the parser matches single words, so commas and spaces
     * both separate keywords.
     *
     * @return list<string>
     */
    private function keywords(string $text): array
    {
        $words = preg_split('/[\s,;]+/u', mb_strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_unique($words));

        if (count($words) > self::MAX_KEYWORDS) {
            throw new UserError('Ключевых слов не больше %max%.', ['%max%' => self::MAX_KEYWORDS]);
        }

        foreach ($words as $word) {
            if (mb_strlen($word) > self::KEYWORD_LENGTH || preg_match('/^[\p{L}\p{N}.\-]+$/u', $word) !== 1) {
                throw new UserError(
                    'Ключевое слово «%word%»: только буквы, цифры, точка и дефис.',
                    ['%word%' => $word],
                );
            }
        }

        return $words;
    }
}
