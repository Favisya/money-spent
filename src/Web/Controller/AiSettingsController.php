<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Application\Purchase\AiProvider;
use App\Application\Purchase\AiSettings;
use App\Application\UserError;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Host Mac only (DeviceGuard): which AI reads receipts and its API key.
 */
final class AiSettingsController extends AbstractController
{
    private const int FIELD_LIMIT = 500;

    public function __construct(
        private readonly AiSettings $settings,
    ) {
    }

    #[Route('/settings/ai', name: 'web_ai_settings', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->page(['notice' => $request->query->getString('ok') === 'saved' ? 'Сохранено.' : null]);
    }

    #[Route('/settings/ai', name: 'web_ai_settings_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $models = $request->request->all('model');
        $keys   = $request->request->all('key');
        $forget = $request->request->all('forget');

        try {
            foreach (AiProvider::cases() as $provider) {
                $this->settings->configure(
                    $provider,
                    self::field($models, $provider),
                    self::field($keys, $provider),
                    self::field($forget, $provider) === '1',
                );
            }

            $this->settings->choose(
                AiProvider::tryFrom($request->request->getString('provider')) ?? $this->settings->provider(),
            );
        } catch (UserError $exception) {
            return $this->page(['error' => $exception], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->redirectToRoute('web_ai_settings', ['ok' => 'saved']);
    }

    /**
     * @param array<mixed> $values
     */
    private static function field(array $values, AiProvider $provider): string
    {
        $value = $values[$provider->value] ?? '';

        return is_string($value) ? mb_substr($value, 0, self::FIELD_LIMIT) : '';
    }

    /**
     * @param array<string, mixed> $state
     */
    private function page(array $state, int $status = Response::HTTP_OK): Response
    {
        $providers = [];

        foreach (AiProvider::cases() as $provider) {
            $providers[] = [
                'provider' => $provider,
                'model'    => $this->settings->model($provider),
                'keyHint'  => $this->settings->keyHint($provider),
            ];
        }

        return $this->render('web/settings_ai.html.twig', [
            'providers' => $providers,
            'current'   => $this->settings->provider(),
            'notice'    => null,
            ...$state,
        ], new Response(status: $status));
    }
}
