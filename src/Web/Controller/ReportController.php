<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Application\Clock\LocalCalendar;
use App\Application\Export\CsvExporter;
use App\Application\Report\MonthPeriod;
use App\Application\UserError;
use App\Web\Report\PdfReport;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ReportController
{
    public function __construct(
        private PdfReport $pdf,
        private CsvExporter $csv,
        private LocalCalendar $calendar,
    ) {
    }

    #[Route('/report/{month}.pdf', name: 'web_report_pdf', requirements: ['month' => '\d{4}-\d{2}'], methods: ['GET'])]
    public function pdf(string $month): Response
    {
        $period = $this->period($month);

        return $this->download(
            $this->pdf->render($period),
            sprintf('otchet-%s.pdf', $period->key()),
            'application/pdf',
        );
    }

    #[Route('/export/{month}.csv', name: 'web_export_csv', requirements: ['month' => '\d{4}-\d{2}'], methods: ['GET'])]
    public function csv(string $month): Response
    {
        $period  = $this->period($month);
        $content = $this->csv->export($period) ?? '';

        return $this->download($content, sprintf('expenses-%s.csv', $period->key()), 'text/csv; charset=UTF-8');
    }

    private function period(string $month): MonthPeriod
    {
        try {
            return MonthPeriod::fromArgument($month, $this->calendar->today());
        } catch (UserError) {
            throw new NotFoundHttpException();
        }
    }

    private function download(string $content, string $filename, string $type): Response
    {
        return new Response($content, Response::HTTP_OK, [
            'Content-Type'           => $type,
            'Content-Disposition'    => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
