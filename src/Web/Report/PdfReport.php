<?php

declare(strict_types=1);

namespace App\Web\Report;

use App\Application\Clock\LocalCalendar;
use App\Application\Report\MonthPeriod;
use App\Web\Dashboard\DashboardBuilder;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Month report as PDF. Remote resources and PHP in templates are disabled; DejaVu Sans covers Cyrillic.
 */
final readonly class PdfReport
{
    public function __construct(
        private DashboardBuilder $dashboards,
        private Environment $twig,
        private LocalCalendar $calendar,
        #[Autowire('%kernel.cache_dir%/dompdf')]
        private string $fontCacheDir,
    ) {
    }

    public function render(MonthPeriod $period): string
    {
        $html = $this->twig->render('web/report_pdf.html.twig', [
            'dashboard'   => $this->dashboards->build($period, MonthPeriod::containing($this->calendar->today())),
            'generatedAt' => $this->calendar->today(),
        ]);

        if (!is_dir($this->fontCacheDir)) {
            mkdir($this->fontCacheDir, 0775, true);
        }

        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setFontCache($this->fontCacheDir);
        $options->setTempDir($this->fontCacheDir);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
