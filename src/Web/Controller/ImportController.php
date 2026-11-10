<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Application\Clock\LocalCalendar;
use App\Application\Import\ImportResult;
use App\Application\Import\NoteImporter;
use App\Application\Import\SignPayers;
use App\Application\Rates\PendingRateBackfill;
use App\Application\UserError;
use App\Infrastructure\Persistence\ParticipantRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ImportController extends AbstractController
{
    private const int MAX_FILE_BYTES = 1_048_576;

    public function __construct(
        private readonly NoteImporter $importer,
        private readonly ParticipantRepository $participants,
        private readonly LocalCalendar $calendar,
        private readonly PendingRateBackfill $rates,
    ) {
    }

    #[Route('/import', name: 'web_import', methods: ['GET'])]
    public function form(Request $request): Response
    {
        return $this->render('web/import.html.twig', [
            'participants' => $this->participants->all(),
            ...$this->signState($request),
            'payerId' => $request->cookies->getInt('payer'),
            'date'    => $this->calendar->today()->format('Y-m-d'),
            'text'    => '',
        ]);
    }

    #[Route('/import', name: 'web_import_preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        try {
            $text    = $this->text($request);
            $date    = $this->date($request);
            $payerId = $request->request->getInt('payer');
            $preview = $this->importer->preview(
                $text,
                $this->calendar->today(),
                $date,
                $payerId,
                $this->signs($request),
            );
        } catch (UserError $exception) {
            return $this->render('web/import.html.twig', [
                'participants' => $this->participants->all(),
                ...$this->signState($request),
                'payerId' => $request->request->getInt('payer'),
                'date'    => $request->request->getString('date'),
                'text'    => $request->request->getString('text'),
                'error'   => $exception->getMessage(),
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('web/import.html.twig', [
            'participants' => $this->participants->all(),
            ...$this->signState($request),
            'payerId' => $payerId,
            'date'    => $date->format('Y-m-d'),
            'text'    => $text,
            'preview' => $preview,
        ]);
    }

    #[Route('/import/confirm', name: 'web_import_confirm', methods: ['POST'])]
    public function confirm(Request $request): Response
    {
        try {
            $preview = $this->importer->preview(
                $request->request->getString('text'),
                $this->calendar->today(),
                $this->date($request),
                $request->request->getInt('payer'),
                $this->signs($request),
            );
            $result = $this->importer->import(
                $preview,
                array_map('intval', array_values($request->request->all('lines'))),
                array_map('intval', $request->request->all('line_payer')),
            );
            $result = new ImportResult($result->imported, $result->duplicates, $this->rates->run());
        } catch (UserError $exception) {
            return $this->render('web/import.html.twig', [
                'participants' => $this->participants->all(),
                ...$this->signState($request),
                'payerId' => $request->request->getInt('payer'),
                'date'    => $request->request->getString('date'),
                'text'    => $request->request->getString('text'),
                'error'   => $exception->getMessage(),
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('web/import.html.twig', [
            'participants' => $this->participants->all(),
            ...$this->signState($request),
            'payerId' => $request->request->getInt('payer'),
            'date'    => $this->calendar->today()->format('Y-m-d'),
            'text'    => '',
            'result'  => $result,
        ]);
    }

    private function signs(Request $request): ?SignPayers
    {
        if ($request->request->getString('sign_mode') !== 'payer') {
            return null;
        }

        return new SignPayers($request->request->getInt('plus_payer'), $request->request->getInt('minus_payer'));
    }

    /**
     * @return array{signMode: string, plusPayer: int, minusPayer: int}
     */
    private function signState(Request $request): array
    {
        $participants = $this->participants->all();

        return [
            'signMode'   => $request->request->getString('sign_mode', 'payer') === 'refund' ? 'refund' : 'payer',
            'plusPayer'  => $request->request->getInt('plus_payer', $participants[0]->id ?? 0),
            'minusPayer' => $request->request->getInt('minus_payer', $participants[1]->id ?? 0),
        ];
    }

    private function text(Request $request): string
    {
        $text = $request->request->getString('text');
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return $text;
        }

        if (!$file->isValid() || $file->getSize() > self::MAX_FILE_BYTES) {
            throw new UserError('Файл не загрузился или больше 1 МБ.');
        }

        $content = (string) file_get_contents($file->getPathname());

        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new UserError('Файл должен быть текстом в UTF-8 (.txt, .md, .csv).');
        }

        return trim($text . "\n" . preg_replace('/^\xEF\xBB\xBF/', '', $content));
    }

    private function date(Request $request): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $request->request->getString('date'),
            $this->calendar->zone(),
        );

        if ($date === false || $date > $this->calendar->today()) {
            throw new UserError('Дата по умолчанию должна быть не позже сегодняшней.');
        }

        return $date;
    }
}
