<?php

declare(strict_types=1);

namespace WordPress\AnthropicAiProvider\Tests\Models;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\WebSearch;
use WordPress\AnthropicAiProvider\Models\AnthropicTextGenerationModel;
use WordPress\AnthropicAiProvider\Provider\AnthropicProvider;

/**
 * Tests for how prompt caching is applied to and reported from the Anthropic API.
 *
 * @since n.e.x.t
 */
class AnthropicTextGenerationModelPromptCachingTest extends TestCase
{
    /**
     * Creates a model instance with the given config.
     *
     * @since n.e.x.t
     */
    private function createModel(ModelConfig $config): AnthropicTextGenerationModel
    {
        $model = new AnthropicTextGenerationModel(
            new ModelMetadata('claude-test', 'Claude Test', [], []),
            AnthropicProvider::metadata()
        );
        $model->setConfig($config);

        return $model;
    }

    /**
     * Prepares the request parameters through the protected method.
     *
     * @since n.e.x.t
     *
     * @return array<string, mixed>
     */
    private function prepareParams(ModelConfig $config): array
    {
        $method = new ReflectionMethod(AnthropicTextGenerationModel::class, 'prepareGenerateTextParams');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke(
            $this->createModel($config),
            [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])]
        );

        return $result;
    }

    /**
     * Builds a config with a system instruction and two tools.
     *
     * @since n.e.x.t
     */
    private function createConfig(): ModelConfig
    {
        $config = new ModelConfig();
        $config->setSystemInstruction('You are a helpful assistant.');
        $config->setFunctionDeclarations([
            new FunctionDeclaration('first', 'The first tool.'),
            new FunctionDeclaration('second', 'The second tool.'),
        ]);

        return $config;
    }

    /**
     * @since n.e.x.t
     */
    public function testRequestIsUnchangedWithoutCacheControl(): void
    {
        $params = $this->prepareParams($this->createConfig());

        $this->assertSame('You are a helpful assistant.', $params['system']);
        $this->assertArrayNotHasKey('cache_control', $params);
        foreach ($params['tools'] as $tool) {
            $this->assertArrayNotHasKey('cache_control', $tool);
        }
    }

    /**
     * @since n.e.x.t
     */
    public function testCacheControlAddsBreakpointsOnLastToolAndSystem(): void
    {
        $cacheControl = ['type' => 'ephemeral'];
        $config = $this->createConfig();
        $config->setCustomOptions(['cache_control' => $cacheControl]);

        $params = $this->prepareParams($config);

        $this->assertSame($cacheControl, $params['cache_control'], 'The top-level option must be kept.');
        $this->assertSame(
            [
                [
                    'type' => 'text',
                    'text' => 'You are a helpful assistant.',
                    'cache_control' => $cacheControl,
                ],
            ],
            $params['system']
        );
        $this->assertArrayNotHasKey('cache_control', $params['tools'][0]);
        $this->assertSame($cacheControl, $params['tools'][1]['cache_control']);
    }

    /**
     * @since n.e.x.t
     */
    public function testBreakpointsUseTheSameTtlAsTheTopLevelOption(): void
    {
        $cacheControl = ['type' => 'ephemeral', 'ttl' => '1h'];
        $config = $this->createConfig();
        $config->setCustomOptions(['cache_control' => $cacheControl]);

        $params = $this->prepareParams($config);

        $this->assertSame($cacheControl, $params['system'][0]['cache_control']);
        $this->assertSame($cacheControl, $params['tools'][1]['cache_control']);
    }

    /**
     * @since n.e.x.t
     */
    public function testBreakpointIsAddedToWebSearchWhenItIsTheLastTool(): void
    {
        $cacheControl = ['type' => 'ephemeral'];
        $config = $this->createConfig();
        $config->setWebSearch(new WebSearch());
        $config->setCustomOptions(['cache_control' => $cacheControl]);

        $params = $this->prepareParams($config);

        $this->assertSame('web_search', $params['tools'][2]['name']);
        $this->assertSame($cacheControl, $params['tools'][2]['cache_control']);
        $this->assertArrayNotHasKey('cache_control', $params['tools'][1]);
    }

    /**
     * @since n.e.x.t
     */
    public function testCacheControlWithoutSystemOrTools(): void
    {
        $cacheControl = ['type' => 'ephemeral'];
        $config = new ModelConfig();
        $config->setCustomOptions(['cache_control' => $cacheControl]);

        $params = $this->prepareParams($config);

        $this->assertSame($cacheControl, $params['cache_control']);
        $this->assertArrayNotHasKey('system', $params);
        $this->assertArrayNotHasKey('tools', $params);
    }

    /**
     * @since n.e.x.t
     */
    public function testCacheUsageBreakdownIsKeptInAdditionalData(): void
    {
        $usage = [
            'input_tokens' => 10,
            'output_tokens' => 5,
            'cache_creation_input_tokens' => 200,
            'cache_read_input_tokens' => 3000,
        ];

        $method = new ReflectionMethod(AnthropicTextGenerationModel::class, 'parseResponseDataToGenerativeAiResult');
        $method->setAccessible(true);
        $result = $method->invoke(
            $this->createModel(new ModelConfig()),
            [
                'id' => 'msg_123',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Hi']],
                'stop_reason' => 'end_turn',
                'usage' => $usage,
            ]
        );

        $this->assertSame($usage, $result->getAdditionalData()['usage']);
        $this->assertSame(3210, $result->getTokenUsage()->getPromptTokens());
    }
}
