<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\Purchase\AiCredentials;
use App\Application\Purchase\AiProvider;
use App\Application\Purchase\ReadingContext;
use App\Application\Purchase\ReceiptInput;
use App\Application\Purchase\ReceiptReader;
use App\Application\Purchase\RecognizedReceipt;
use App\Application\UserError;
use OpenAI;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Exceptions\UnserializableResponse;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * GPT through openai-php/client: the Responses API with a strict JSON schema answer.
 */
final readonly class OpenAiReceiptReader implements ReceiptReader
{
    private const int MAX_OUTPUT_TOKENS = 16000;
    private const array AUTH_STATUSES   = [401, 403];
    private const int NOT_FOUND         = 404;

    public function __construct(
        #[Autowire(service: 'ai.psr18_client')]
        private ClientInterface $transport,
        private LoggerInterface $logger,
    ) {
    }

    public function provider(): AiProvider
    {
        return AiProvider::OpenAi;
    }

    public function read(ReceiptInput $input, ReadingContext $context, AiCredentials $credentials): RecognizedReceipt
    {
        $client = OpenAI::factory()
            ->withApiKey($credentials->apiKey)
            ->withHttpClient($this->transport)
            ->make();

        try {
            $response = $client->responses()->create([
                'model'        => $credentials->model,
                'instructions' => ReceiptPrompt::instructions($context),
                'input'        => [['role' => 'user', 'content' => $this->content($input)]],
                'text'         => [
                    'format' => [
                        'type'   => 'json_schema',
                        'name'   => 'receipt',
                        'schema' => ReceiptPrompt::schema(),
                        'strict' => true,
                    ],
                ],
                'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
            ]);
        } catch (ErrorException $exception) {
            throw $this->explain($exception, $credentials);
        } catch (RateLimitException) {
            throw new UserError('GPT сейчас перегружен или кончился лимит — попробуй позже.');
        } catch (TransporterException) {
            throw new UserError('Нет связи с OpenAI — проверь интернет.');
        } catch (UnserializableResponse $exception) {
            $this->logger->error('OpenAI receipt answer is unreadable', ['error' => $exception->getMessage()]);

            throw new UserError('GPT вернул ошибку — попробуй ещё раз.');
        }

        if ($response->status !== 'completed') {
            throw new UserError('Чек слишком длинный — сфотографируй его частями.');
        }

        return AnswerJson::decode((string) $response->outputText);
    }

    private function explain(ErrorException $exception, AiCredentials $credentials): UserError
    {
        $status = $exception->getStatusCode();

        if (in_array($status, self::AUTH_STATUSES, true)) {
            return new UserError('Ключ OpenAI не подошёл — проверь его в настройках ИИ.');
        }

        if ($status === self::NOT_FOUND) {
            return new UserError('Модель %model% не найдена — проверь название в настройках ИИ.', [
                '%model%' => $credentials->model,
            ]);
        }

        $this->logger->error('OpenAI receipt request failed', [
            'status' => $status,
            'error'  => $exception->getErrorMessage(),
        ]);

        return new UserError('GPT вернул ошибку — попробуй ещё раз.');
    }

    /**
     * @return list<array<string, string>>
     */
    private function content(ReceiptInput $input): array
    {
        $content = [['type' => 'input_text', 'text' => ReceiptPrompt::request($input)]];

        if ($input->image !== null) {
            $content[] = [
                'type'      => 'input_image',
                'image_url' => sprintf(
                    'data:%s;base64,%s',
                    $input->image->mediaType,
                    base64_encode($input->image->bytes),
                ),
            ];
        }

        return $content;
    }
}
