<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use Anthropic\Beta\Messages\BetaBase64ImageSource;
use Anthropic\Beta\Messages\BetaBase64ImageSource\MediaType;
use Anthropic\Beta\Messages\BetaImageBlockParam;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaTextBlockParam;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\OverloadedException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Application\Purchase\AiCredentials;
use App\Application\Purchase\AiProvider;
use App\Application\Purchase\ReadingContext;
use App\Application\Purchase\ReceiptInput;
use App\Application\Purchase\ReceiptReader;
use App\Application\Purchase\RecognizedReceipt;
use App\Application\UserError;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Claude through the official Anthropic SDK: structured output against ReceiptPrompt::schema(), and the server-side
 * fallback model when Claude declines a request.
 */
final readonly class ClaudeReceiptReader implements ReceiptReader
{
    private const int MAX_TOKENS       = 16000;
    private const int MAX_RETRIES      = 2;
    private const string EFFORT        = 'medium';
    private const string FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function __construct(
        #[Autowire(service: 'ai.psr18_client')]
        private ClientInterface $transport,
        private LoggerInterface $logger,
    ) {
    }

    public function provider(): AiProvider
    {
        return AiProvider::Claude;
    }

    public function read(ReceiptInput $input, ReadingContext $context, AiCredentials $credentials): RecognizedReceipt
    {
        $client = new Client(
            apiKey: $credentials->apiKey,
            requestOptions: ['transporter' => $this->transport, 'maxRetries' => self::MAX_RETRIES],
        );

        try {
            $message = $client->beta->messages->create(
                maxTokens: self::MAX_TOKENS,
                messages: [['role' => 'user', 'content' => $this->content($input)]],
                model: $credentials->model,
                fallbacks: 'default',
                outputConfig: [
                    'effort' => self::EFFORT,
                    'format' => ['type' => 'json_schema', 'schema' => ReceiptPrompt::schema()],
                ],
                system: ReceiptPrompt::instructions($context),
                betas: [self::FALLBACK_BETA],
            );
        } catch (AuthenticationException | PermissionDeniedException) {
            throw new UserError('Ключ Claude не подошёл — проверь его в настройках ИИ.');
        } catch (NotFoundException) {
            throw new UserError('Модель %model% не найдена — проверь название в настройках ИИ.', [
                '%model%' => $credentials->model,
            ]);
        } catch (RateLimitException | OverloadedException) {
            throw new UserError('Claude сейчас перегружен — попробуй через минуту.');
        } catch (APIConnectionException) {
            throw new UserError('Нет связи с Claude — проверь интернет.');
        } catch (APIException $exception) {
            $this->logger->error('Claude receipt request failed', ['error' => $exception->getMessage()]);

            throw new UserError('Claude вернул ошибку — попробуй ещё раз.');
        }

        if ($message->stopReason === 'refusal') {
            throw new UserError('Модель отказалась читать это — попробуй другое фото.');
        }

        if ($message->stopReason === 'max_tokens') {
            throw new UserError('Чек слишком длинный — сфотографируй его частями.');
        }

        $text = '';

        foreach ($message->content as $block) {
            if ($block instanceof BetaTextBlock) {
                $text .= $block->text;
            }
        }

        return AnswerJson::decode($text);
    }

    /**
     * @return list<BetaImageBlockParam|BetaTextBlockParam>
     */
    private function content(ReceiptInput $input): array
    {
        $content = [];

        foreach ($input->images as $image) {
            $content[] = BetaImageBlockParam::with(
                source: BetaBase64ImageSource::with(
                    data: base64_encode($image->bytes),
                    mediaType: MediaType::from($image->mediaType),
                ),
            );
        }

        $content[] = BetaTextBlockParam::with(text: ReceiptPrompt::request($input));

        return $content;
    }
}
