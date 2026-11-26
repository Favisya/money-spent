<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Application\Purchase\AiSettings;
use App\Application\Purchase\ReceiptBook;
use App\Application\Purchase\ReceiptFormReader;
use App\Application\Purchase\ReceiptImage;
use App\Application\Purchase\ReceiptInput;
use App\Application\Purchase\ReceiptRecognizer;
use App\Application\Purchase\ReceiptView;
use App\Application\Reference\CurrencyCatalog;
use App\Application\UserError;
use App\Infrastructure\Persistence\ParticipantRepository;
use App\Infrastructure\Persistence\ReceiptRepository;
use App\Web\Purchase\ReceiptFormView;
use App\Web\Security\DeviceGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PurchaseController extends AbstractController
{
    private const int LIST_LIMIT        = 100;
    private const int RECOGNIZE_SECONDS = 170;
    private const string PAYER_COOKIE   = 'payer';
    private const array NOTICES         = [
        'recognized' => 'ИИ разобрал покупки — проверь позиции и сохрани.',
        'blank'      => 'Впиши позиции и сохрани.',
        'saved'      => 'Чек сохранён, трата записана.',
        'deleted'    => 'Чек и его трата удалены.',
    ];

    public function __construct(
        private readonly ReceiptRecognizer $recognizer,
        private readonly ReceiptBook $book,
        private readonly ReceiptFormReader $formReader,
        private readonly ReceiptRepository $receipts,
        private readonly ParticipantRepository $participants,
        private readonly CurrencyCatalog $currencies,
        private readonly AiSettings $ai,
    ) {
    }

    #[Route('/purchases', name: 'web_purchases', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->listPage($request, ['notice' => self::NOTICES[$request->query->getString('ok')] ?? null]);
    }

    #[Route('/purchases/recognize', name: 'web_purchase_recognize', methods: ['POST'])]
    public function recognize(Request $request): Response
    {
        set_time_limit(self::RECOGNIZE_SECONDS);
        $text = mb_substr($request->request->getString('text'), 0, ReceiptInput::TEXT_LIMIT + 1);

        try {
            $id = $this->recognizer->recognize($this->input($request, $text), $request->getLocale());
        } catch (UserError $exception) {
            return $this->listPage(
                $request,
                ['error' => $exception, 'text' => $text],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->redirectToRoute('web_purchase', ['id' => $id, 'ok' => 'recognized']);
    }

    #[Route('/purchases/blank', name: 'web_purchase_blank', methods: ['POST'])]
    public function blank(): Response
    {
        return $this->redirectToRoute('web_purchase', ['id' => $this->recognizer->blank(), 'ok' => 'blank']);
    }

    #[Route('/purchases/{id}', name: 'web_purchase', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $receipt = $this->receipt($id);
        $form    = ReceiptFormView::fromReceipt($receipt, $this->lastPayer($request));

        return $this->editPage($receipt, $form, [
            'notice' => self::NOTICES[$request->query->getString('ok')] ?? null,
        ]);
    }

    #[Route('/purchases/{id}', name: 'web_purchase_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(Request $request, int $id): Response
    {
        $receipt = $this->receipt($id);
        $posted  = $request->request->all();

        try {
            $this->book->save($receipt->id, $this->formReader->read($posted));
        } catch (UserError $exception) {
            return $this->editPage(
                $receipt,
                ReceiptFormView::fromPost($posted),
                ['error' => $exception],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->redirectToRoute('web_purchase', ['id' => $id, 'ok' => 'saved']);
    }

    #[Route('/purchases/{id}/delete', name: 'web_purchase_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id): Response
    {
        try {
            $this->book->delete($id);
        } catch (UserError) {
            throw new NotFoundHttpException();
        }

        return $this->redirectToRoute('web_purchases', ['ok' => 'deleted']);
    }

    #[Route(
        '/purchases/{id}/image/{position}',
        name: 'web_purchase_image',
        requirements: ['id' => '\d+', 'position' => '\d{1,2}'],
        methods: ['GET'],
    )]
    public function image(int $id, int $position): Response
    {
        $image = $this->receipts->image($id, $position);

        if ($image === null || !in_array($image['type'], ReceiptImage::TYPES, true)) {
            throw new NotFoundHttpException();
        }

        return new Response($image['bytes'], Response::HTTP_OK, [
            'Content-Type'            => $image['type'],
            'Content-Disposition'     => 'inline',
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control'           => 'private, max-age=86400',
        ]);
    }

    private function input(Request $request, string $text): ReceiptInput
    {
        $photos = array_values(array_filter(
            $request->files->all('photos'),
            static fn (mixed $photo): bool => $photo instanceof UploadedFile,
        ));

        if ($photos === []) {
            return ReceiptInput::text($text);
        }

        if (count($photos) > ReceiptInput::MAX_IMAGES) {
            throw new UserError('За раз — до %max% фото.', ['%max%' => ReceiptInput::MAX_IMAGES]);
        }

        return ReceiptInput::photos(array_map(
            static function (UploadedFile $photo): ReceiptImage {
                if (!$photo->isValid()) {
                    throw new UserError('Фото не загрузилось — возможно, оно слишком большое.');
                }

                return ReceiptImage::fromBytes($photo->getContent());
            },
            $photos,
        ));
    }

    private function receipt(int $id): ReceiptView
    {
        try {
            return $this->book->get($id);
        } catch (UserError) {
            throw new NotFoundHttpException();
        }
    }

    private function lastPayer(Request $request): ?int
    {
        $cookie = $request->cookies->getString(self::PAYER_COOKIE);

        return ctype_digit($cookie) ? (int) $cookie : null;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function listPage(Request $request, array $state, int $status = Response::HTTP_OK): Response
    {
        $provider = $this->ai->provider();

        return $this->render('web/purchases.html.twig', [
            'receipts'   => $this->receipts->summaries(self::LIST_LIMIT),
            'provider'   => $provider,
            'model'      => $this->ai->model($provider),
            'ready'      => $this->ai->isReady($provider),
            'isHost'     => DeviceGuard::isLocal($request),
            'text'       => '',
            'maxPhotoMb' => intdiv(ReceiptImage::MAX_BYTES, 1_048_576),
            'maxPhotos'  => ReceiptInput::MAX_IMAGES,
            'notice'     => null,
            ...$state,
        ], new Response(status: $status));
    }

    /**
     * @param array{fields: array<string, string>, rows: list<array<string, string>>} $form
     * @param array<string, mixed>                                                    $state
     */
    private function editPage(
        ReceiptView $receipt,
        array $form,
        array $state,
        int $status = Response::HTTP_OK,
    ): Response {
        return $this->render('web/purchase.html.twig', [
            'receipt'      => $receipt,
            'fields'       => $form['fields'],
            'rows'         => $form['rows'],
            'participants' => $this->participants->all(),
            'currencies'   => $this->currencies->all(),
            'both'         => ReceiptFormReader::BOTH,
            'difference'   => $receipt->content->differenceFromPrinted(),
            'notice'       => null,
            ...$state,
        ], new Response(status: $status));
    }
}
