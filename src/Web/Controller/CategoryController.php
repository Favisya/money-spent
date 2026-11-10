<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Application\Category\CategoryAdmin;
use App\Application\UserError;
use App\Infrastructure\Persistence\CategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CategoryController extends AbstractController
{
    private const int TEXT_LIMIT = 2000;
    private const array NOTICES  = [
        'added'  => 'Категория добавлена.',
        'saved'  => 'Сохранено.',
        'hidden' => 'Категория скрыта: старые траты остались в ней, новые туда не попадут.',
        'shown'  => 'Категория снова в списке.',
    ];

    public function __construct(
        private readonly CategoryAdmin $admin,
        private readonly CategoryRepository $categories,
    ) {
    }

    #[Route('/categories', name: 'web_categories', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->page([
            'notice'    => self::NOTICES[$request->query->getString('ok')] ?? null,
            'highlight' => $request->query->getInt('id'),
        ]);
    }

    #[Route('/categories', name: 'web_category_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        [$title, $keywords] = $this->input($request);

        try {
            $category = $this->admin->create($title, $keywords);
        } catch (UserError $exception) {
            return $this->page([
                'addError'    => $exception->getMessage(),
                'addTitle'    => $title,
                'addKeywords' => $keywords,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->redirectToRoute('web_categories', ['ok' => 'added', 'id' => $category->id]);
    }

    #[Route('/categories/{id}', name: 'web_category_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(Request $request, int $id): Response
    {
        [$title, $keywords] = $this->input($request);

        try {
            $this->admin->update($id, $title, $keywords);
        } catch (UserError $exception) {
            return $this->page([
                'editError' => [$id => $exception->getMessage()],
                'editTitle' => [$id => $title],
                'editWords' => [$id => $keywords],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->redirectToRoute('web_categories', ['ok' => 'saved', 'id' => $id]);
    }

    #[Route(
        '/categories/{id}/visibility',
        name: 'web_category_visibility',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function visibility(Request $request, int $id): Response
    {
        $active = $request->request->getBoolean('active');

        try {
            $this->admin->setActive($id, $active);
        } catch (UserError $exception) {
            return $this->page(['editError' => [$id => $exception->getMessage()]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->redirectToRoute('web_categories', ['ok' => $active ? 'shown' : 'hidden', 'id' => $id]);
    }

    /**
     * @return array{string, string}
     */
    private function input(Request $request): array
    {
        return [
            mb_substr($request->request->getString('title'), 0, self::TEXT_LIMIT),
            mb_substr($request->request->getString('keywords'), 0, self::TEXT_LIMIT),
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function page(array $state, int $status = Response::HTTP_OK): Response
    {
        $all = $this->categories->findAll();

        return $this->render('web/categories.html.twig', [
            'active' => array_values(array_filter($all, static fn ($category): bool => $category->active)),
            'hidden' => array_values(array_filter($all, static fn ($category): bool => !$category->active)),
            'usage'  => $this->categories->usage(),
            'admin'  => $this->admin,
            ...$state,
        ], new Response(status: $status));
    }
}
