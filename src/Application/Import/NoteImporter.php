<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Application\Expense\ExpenseRecorder;
use App\Application\Expense\NoteText;
use App\Application\Parsing\ParseErrorMessages;
use App\Application\Parsing\ParserFactory;
use App\Application\Participant\Participant;
use App\Application\Transfer\TransferParser;
use App\Application\Transfer\TransferRecorder;
use App\Application\UserError;
use App\Domain\Parsing\ExpenseParseException;
use App\Domain\Parsing\ExpenseParser;
use App\Infrastructure\Persistence\CategoryRepository;
use App\Infrastructure\Persistence\ExpenseRepository;
use App\Infrastructure\Persistence\ParticipantRepository;
use App\Infrastructure\Persistence\SettlementRepository;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Turns a free-form note (one expense per line, optional "10.11" day headers) into expenses.
 * Importing the same note twice does not duplicate anything; a line whose expense was deleted is recorded again.
 */
final readonly class NoteImporter
{
    private const int MAX_CHARS    = 200_000;
    private const int MAX_LINES    = 5_000;
    private const string NAMESPACE = 'd3a1f0b6-0f43-4c1e-8a57-5b8c6e2f9a14';

    public function __construct(
        private ParserFactory $parsers,
        private ParseErrorMessages $errors,
        private CategoryRepository $categories,
        private ParticipantRepository $participants,
        private ExpenseRepository $expenses,
        private ExpenseRecorder $recorder,
        private PayerGuesser $payerGuesser,
        private TransferParser $transfers,
        private TransferRecorder $transferRecorder,
        private SettlementRepository $settlements,
        private string $defaultCategoryCode,
    ) {
    }

    public function preview(
        string $text,
        DateTimeImmutable $today,
        DateTimeImmutable $defaultDate,
        int $defaultPayerId,
        ?SignPayers $signs = null,
    ): ImportPreview {
        $lines = $this->lines($text);
        $this->payer($defaultPayerId);

        if ($signs !== null) {
            $this->payer($signs->plusPayerId);
            $this->payer($signs->minusPayerId);
        }

        $parser       = $this->parsers->create();
        $participants = $this->participants->all();
        $titles       = $this->categoryTitles();
        $currentDate  = $defaultDate->setTime(0, 0);
        $rows         = [];

        foreach ($lines as $index => $line) {
            $row = $this->previewLine(
                $parser,
                $index + 1,
                $line,
                $today,
                $currentDate,
                $participants,
                $titles,
                $signs,
                $defaultPayerId,
            );

            if ($row->kind === ImportRowKind::DateHeader && $row->date !== null) {
                $currentDate = $row->date;
            }

            $rows[] = $row->kind === ImportRowKind::Expense && $row->payerId === null
                ? new ImportRow(
                    $row->lineNumber,
                    $row->text,
                    $row->kind,
                    $row->expense,
                    $row->categoryTitle,
                    $defaultPayerId,
                    $row->date,
                )
                : $row;
        }

        return new ImportPreview($rows);
    }

    /**
     * @param list<int>       $selectedLines line numbers to import
     * @param array<int, int>    $payerByLine   line number => participant id chosen in the preview
     * @param array<int, string> $noteByLine    line number => "what for" as edited in the preview
     */
    public function import(
        ImportPreview $preview,
        array $selectedLines,
        array $payerByLine,
        array $noteByLine = [],
    ): ImportResult {
        $selected    = array_flip($selectedLines);
        $occurrences = [];
        $imported    = 0;
        $duplicates  = 0;
        $filled      = 0;

        foreach ($preview->records() as $row) {
            $key               = mb_strtolower(trim($row->text));
            $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;

            if (!isset($selected[$row->lineNumber]) || ($row->expense === null && $row->transfer === null)) {
                continue;
            }

            $uuid = Uuid::v5(
                Uuid::fromString(self::NAMESPACE),
                sprintf('import:%s:%d', sha1($key), $occurrences[$key]),
            )->toRfc4122();

            $expense = $row->expense;

            if ($expense !== null && array_key_exists($row->lineNumber, $noteByLine)) {
                $expense = $expense->withNote(NoteText::clean($noteByLine[$row->lineNumber]));
            }

            $this->expenses->purgeDeletedByUuid($uuid);
            $this->settlements->purgeDeletedByUuid($uuid);

            if ($this->expenses->existsByUuid($uuid) || $this->settlements->existsByUuid($uuid)) {
                ++$duplicates;
                $filled += $expense === null ? 0 : $this->fillMissingNote($uuid, $expense->note);

                continue;
            }

            if ($row->transfer !== null) {
                $this->transferRecorder->record($row->transfer, $uuid);
            } elseif ($expense !== null) {
                $payer = $this->payer($payerByLine[$row->lineNumber] ?? $row->payerId ?? 0);
                $this->recorder->record($payer, $expense, $uuid);
            }

            ++$imported;
        }

        return new ImportResult($imported, $duplicates, notesFilled: $filled);
    }

    /**
     * Importing the same note again adds the "what for" comment to expenses that were imported without one.
     *
     * @return int 1 when a note was written
     */
    private function fillMissingNote(string $uuid, ?string $note): int
    {
        $id   = $this->expenses->findIdByUuid($uuid);
        $view = $id === null ? null : $this->expenses->findView($id);

        if ($view === null || $view->deleted || $view->note !== null) {
            return 0;
        }

        $note = NoteText::besides($note, $view->categoryTitle);

        if ($note === null) {
            return 0;
        }

        $this->expenses->updateNote($view->id, $note);

        return 1;
    }

    /**
     * @param list<Participant>     $participants
     * @param array<string, string> $titles
     */
    private function previewLine(
        ExpenseParser $parser,
        int $lineNumber,
        string $line,
        DateTimeImmutable $today,
        DateTimeImmutable $currentDate,
        array $participants,
        array $titles,
        ?SignPayers $signs,
        int $defaultPayerId,
    ): ImportRow {
        if (trim($line) === '') {
            return new ImportRow($lineNumber, $line, ImportRowKind::Blank);
        }

        if (preg_match('/\d/', $line) !== 1) {
            return new ImportRow($lineNumber, $line, ImportRowKind::Comment);
        }

        try {
            $header = $parser->parseDateLine($line, $today);

            if ($header !== null) {
                return new ImportRow($lineNumber, $line, ImportRowKind::DateHeader, date: $header);
            }

            if ($this->transfers->isTransfer($line)) {
                return $this->transferRow(
                    $lineNumber,
                    $line,
                    $today,
                    $currentDate,
                    $participants,
                    $signs,
                    $defaultPayerId,
                );
            }

            $parsed = $parser->parse($line, $today);
        } catch (UserError $exception) {
            return new ImportRow($lineNumber, $line, ImportRowKind::Error, error: $exception);
        } catch (ExpenseParseException $exception) {
            return new ImportRow($lineNumber, $line, ImportRowKind::Error, error: $this->errors->error($exception));
        }

        $parsed = $parsed->hasExplicitDate ? $parsed : $parsed->withSpentAt($currentDate);
        $codes  = count($parsed->categoryCodes) === 1 ? $parsed->categoryCodes : [$this->defaultCategoryCode];
        $payer  = $this->payerGuesser->guess($line, $participants);

        if ($payer !== null) {
            $parsed = $parsed->withNote($this->payerGuesser->stripMention($parsed->note, $payer));
        }

        $sign    = self::leadingSign($line);
        $payerId = $payer?->id;

        if ($signs !== null && $sign !== null) {
            $parsed  = $parsed->withAmount($parsed->amount->abs());
            $payerId = $payerId ?? $signs->payerFor($sign);
        }

        $title  = $titles[$codes[0]] ?? $codes[0];
        $parsed = $parsed->withNote(NoteText::besides($parsed->note, $title));

        return new ImportRow(
            $lineNumber,
            $line,
            ImportRowKind::Expense,
            $parsed,
            $title,
            $payerId,
            $parsed->spentAt,
        );
    }

    /**
     * @param list<Participant> $participants
     */
    private function transferRow(
        int $lineNumber,
        string $line,
        DateTimeImmutable $today,
        DateTimeImmutable $currentDate,
        array $participants,
        ?SignPayers $signs,
        int $defaultPayerId,
    ): ImportRow {
        $sign     = self::leadingSign($line);
        $senderId = $signs !== null && $sign !== null ? $signs->payerFor($sign) : $defaultPayerId;
        $transfer = $this->transfers->parse(
            $line,
            $today,
            $this->payer($senderId),
            $participants,
            defaultDate: $currentDate,
        );

        return new ImportRow(
            $lineNumber,
            $line,
            ImportRowKind::Transfer,
            payerId: $transfer->from->id,
            date: $transfer->date,
            transfer: $transfer,
        );
    }

    private static function leadingSign(string $line): ?string
    {
        if (preg_match('/^\s*([+\-−])\s*\d/u', $line, $match) !== 1) {
            return null;
        }

        return $match[1] === '+' ? '+' : '-';
    }

    /**
     * @return list<string>
     */
    private function lines(string $text): array
    {
        if (mb_strlen($text) > self::MAX_CHARS) {
            throw new UserError('Слишком большой текст: максимум %max% символов за раз.', ['%max%' => self::MAX_CHARS]);
        }

        $lines = preg_split('/\R/u', $text) ?: [];

        if (count($lines) > self::MAX_LINES) {
            throw new UserError('Слишком много строк: максимум %max% за раз.', ['%max%' => self::MAX_LINES]);
        }

        return $lines;
    }

    private function payer(int $participantId): Participant
    {
        return $this->participants->find($participantId) ?? throw new UserError('Выбери, кто платил.');
    }

    /**
     * @return array<string, string> code => title
     */
    private function categoryTitles(): array
    {
        $titles = [];

        foreach ($this->categories->findActive() as $category) {
            $titles[$category->code] = $category->title;
        }

        return $titles;
    }
}
