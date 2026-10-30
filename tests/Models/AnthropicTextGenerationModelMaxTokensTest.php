<?php

declare(strict_types=1);

namespace WordPress\AnthropicAiProvider\Tests\Models;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WordPress\AiClient\Common\Exception\TokenLimitReachedException;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AnthropicAiProvider\Metadata\AnthropicModelMetadata;
use WordPress\AnthropicAiProvider\Models\AnthropicTextGenerationModel;
use WordPress\AnthropicAiProvider\Provider\AnthropicProvider;

/**
 * Tests for the `max_tokens` value sent to the Anthropic API.
 *
 * @since n.e.x.t
 */
class AnthropicTextGenerationModelMaxTokensTest extends TestCase
{
    /**
     * Creates a model instance.
     *
     * @since n.e.x.t
     *
     * @param ModelMetadata $metadata  The model metadata.
     * @param int|null      $maxTokens The configured token limit, or null for none.
     */
    private function createModel(ModelMetadata $metadata, ?int $maxTokens = null): AnthropicTextGenerationModel
    {
        $model = new AnthropicTextGenerationModel($metadata, AnthropicProvider::metadata());

        if ($maxTokens !== null) {
            $config = new ModelConfig();
            $config->setMaxTokens($maxTokens);
            $model->setConfig($config);
        }

        return $model;
    }

    /**
     * Creates Anthropic model metadata with the given output token limit.
     *
     * @since n.e.x.t
     *
     * @param string   $modelId         The model ID.
     * @param int|null $maxOutputTokens The output token limit, or null if unknown.
     */
    private function anthropicMetadata(string $modelId, ?int $maxOutputTokens): AnthropicModelMetadata
    {
        return new AnthropicModelMetadata($modelId, $modelId, [], [], $maxOutputTokens);
    }

    /**
     * Returns the `max_tokens` parameter prepared for the given model.
     *
     * @since n.e.x.t
     */
    private function preparedMaxTokens(AnthropicTextGenerationModel $model): int
    {
        $method = new ReflectionMethod(AnthropicTextGenerationModel::class, 'prepareGenerateTextParams');
        $method->setAccessible(true);

        /** @var array<string, mixed> $params */
        $params = $method->invoke($model, [new UserMessage([new MessagePart('Hello')])]);

        $this->assertArrayHasKey('max_tokens', $params);
        $this->assertIsInt($params['max_tokens']);

        return $params['max_tokens'];
    }

    /**
     * @since n.e.x.t
     *
     * @return array<string, array{0: ModelMetadata, 1: int}>
     */
    public function dataDefaultMaxTokens(): array
    {
        return [
            'model limit above the recommended default' => [
                $this->anthropicMetadata('claude-opus-5-5', 128000),
                AnthropicTextGenerationModel::RECOMMENDED_MAX_TOKENS,
            ],
            'model limit equal to 64K' => [
                $this->anthropicMetadata('claude-sonnet-4-5', 64000),
                AnthropicTextGenerationModel::RECOMMENDED_MAX_TOKENS,
            ],
            'model limit below the recommended default' => [
                $this->anthropicMetadata('claude-3-5-haiku-20241022', 8192),
                8192,
            ],
            'Claude 3 model with limit from metadata' => [
                $this->anthropicMetadata('claude-3-haiku-20240307', 4096),
                4096,
            ],
            'Claude 3 model without metadata limit' => [
                new ModelMetadata('claude-3-haiku-20240307', 'Claude Haiku 3', [], []),
                AnthropicTextGenerationModel::DEFAULT_MAX_TOKENS,
            ],
            'Claude 3.7 model without metadata limit' => [
                $this->anthropicMetadata('claude-3-7-sonnet-20250219', null),
                AnthropicTextGenerationModel::DEFAULT_MAX_TOKENS,
            ],
            'unknown model without metadata limit' => [
                $this->anthropicMetadata('claude-test', null),
                AnthropicTextGenerationModel::DEFAULT_MAX_TOKENS,
            ],
            'non-Claude model without metadata limit' => [
                new ModelMetadata('custom-model', 'Custom Model', [], []),
                AnthropicTextGenerationModel::DEFAULT_MAX_TOKENS,
            ],
            'Claude 5 model with plain metadata' => [
                new ModelMetadata('claude-sonnet-5', 'Claude Sonnet 5', [], []),
                AnthropicTextGenerationModel::RECOMMENDED_MAX_TOKENS,
            ],
            'Claude 4 model with dated ID and plain metadata' => [
                new ModelMetadata('claude-opus-4-20250514', 'Claude Opus 4', [], []),
                AnthropicTextGenerationModel::RECOMMENDED_MAX_TOKENS,
            ],
        ];
    }

    /**
     * @dataProvider dataDefaultMaxTokens
     *
     * @since n.e.x.t
     *
     * @param ModelMetadata $metadata The model metadata.
     * @param int           $expected The expected `max_tokens` value.
     */
    public function testDefaultMaxTokensWhenNoLimitIsConfigured(ModelMetadata $metadata, int $expected): void
    {
        $this->assertSame($expected, $this->preparedMaxTokens($this->createModel($metadata)));
    }

    /**
     * @since n.e.x.t
     *
     * @return array<string, array{0: ModelMetadata, 1: int}>
     */
    public function dataExplicitMaxTokens(): array
    {
        return [
            'above the recommended default' => [$this->anthropicMetadata('claude-opus-5-5', 128000), 50000],
            'below the recommended default' => [$this->anthropicMetadata('claude-opus-5-5', 128000), 100],
            'above the model limit' => [$this->anthropicMetadata('claude-3-5-haiku-20241022', 8192), 20000],
            'Claude 3 model with plain metadata' => [
                new ModelMetadata('claude-3-haiku-20240307', 'Claude Haiku 3', [], []),
                2048,
            ],
        ];
    }

    /**
     * @dataProvider dataExplicitMaxTokens
     *
     * @since n.e.x.t
     *
     * @param ModelMetadata $metadata  The model metadata.
     * @param int           $maxTokens The configured token limit.
     */
    public function testExplicitMaxTokensIsSentUnchanged(ModelMetadata $metadata, int $maxTokens): void
    {
        $this->assertSame($maxTokens, $this->preparedMaxTokens($this->createModel($metadata, $maxTokens)));
    }

    /**
     * @since n.e.x.t
     *
     * @return array<string, array{0: AnthropicTextGenerationModel, 1: int}>
     */
    public function dataTokenLimitExceptionReportsSentValue(): array
    {
        return [
            'default from metadata' => [
                $this->createModel($this->anthropicMetadata('claude-opus-5-5', 128000)),
                16000,
            ],
            'default for Claude 3' => [
                $this->createModel(new ModelMetadata('claude-3-haiku-20240307', 'Claude Haiku 3', [], [])),
                4096,
            ],
            'explicit limit' => [
                $this->createModel($this->anthropicMetadata('claude-opus-5-5', 128000), 50000),
                50000,
            ],
        ];
    }

    /**
     * @dataProvider dataTokenLimitExceptionReportsSentValue
     *
     * @since n.e.x.t
     *
     * @param AnthropicTextGenerationModel $model    The model.
     * @param int                          $expected The `max_tokens` value that was sent.
     */
    public function testTokenLimitExceptionReportsSentValue(AnthropicTextGenerationModel $model, int $expected): void
    {
        $sent = $this->preparedMaxTokens($model);
        $this->assertSame($expected, $sent);

        $method = new ReflectionMethod(AnthropicTextGenerationModel::class, 'parseResponseDataToGenerativeAiResult');
        $method->setAccessible(true);

        try {
            $method->invoke(
                $model,
                [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Truncated']],
                    'stop_reason' => 'max_tokens',
                ]
            );
            $this->fail('Expected a TokenLimitReachedException.');
        } catch (TokenLimitReachedException $e) {
            $this->assertSame($sent, $e->getMaxTokens());
            $this->assertSame(
                sprintf('Generation stopped due to token limit (%d) with stop reason "max_tokens".', $sent),
                $e->getMessage()
            );
        }
    }
}
