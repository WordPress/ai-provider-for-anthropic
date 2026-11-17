<?php

declare(strict_types=1);

namespace WordPress\AnthropicAiProvider\Tests\Models;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\WebSearch;
use WordPress\AnthropicAiProvider\Models\AnthropicTextGenerationModel;
use WordPress\AnthropicAiProvider\Provider\AnthropicProvider;

/**
 * Tests for using Agent Skills, which run on the code execution tool.
 *
 * @covers \WordPress\AnthropicAiProvider\Models\AnthropicTextGenerationModel
 */
class AnthropicTextGenerationModelSkillsTest extends TestCase
{
    private const CODE_EXECUTION_TOOL = ['type' => 'code_execution_20250825', 'name' => 'code_execution'];

    private const SKILLS_CONTAINER = [
        'skills' => [
            ['type' => 'anthropic', 'skill_id' => 'xlsx', 'version' => 'latest'],
        ],
    ];

    /**
     * Request bodies sent by the model under test.
     *
     * @var list<array<string, mixed>>
     */
    private array $sentRequests = [];

    /**
     * Tests that code execution result blocks are ignored and the text still comes through.
     *
     * @since n.e.x.t
     *
     * @dataProvider provideCodeExecutionResultTypes
     *
     * @param string $type The result block type.
     */
    public function testCodeExecutionResultBlocksAreIgnored(string $type): void
    {
        $result = $this->generate(new ModelConfig(), [
            $this->responseData('end_turn', [
                ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'bash_code_execution', 'input' => []],
                ['type' => $type, 'tool_use_id' => 'srvtoolu_1', 'content' => []],
                ['type' => 'text', 'text' => 'Done'],
            ]),
        ]);

        $this->assertSame('Done', $result->toText());
    }

    /**
     * @return array<string, array{string}>
     */
    public function provideCodeExecutionResultTypes(): array
    {
        return [
            'code_execution_tool_result' => ['code_execution_tool_result'],
            'bash_code_execution_tool_result' => ['bash_code_execution_tool_result'],
            'text_editor_code_execution_tool_result' => ['text_editor_code_execution_tool_result'],
        ];
    }

    /**
     * Tests that custom option tools are used as-is when there are no other tools.
     *
     * @since n.e.x.t
     */
    public function testCustomToolsAreSentWithoutOtherTools(): void
    {
        $config = new ModelConfig();
        $config->setCustomOptions([
            'container' => self::SKILLS_CONTAINER,
            'tools' => [self::CODE_EXECUTION_TOOL],
        ]);

        $this->generate($config, [$this->responseData('end_turn')]);

        $this->assertSame([self::CODE_EXECUTION_TOOL], $this->sentRequests[0]['tools']);
        $this->assertSame(self::SKILLS_CONTAINER, $this->sentRequests[0]['container']);
    }

    /**
     * Tests that custom option tools are added after function declarations and web search.
     *
     * @since n.e.x.t
     */
    public function testCustomToolsAreAppendedToOtherTools(): void
    {
        $config = new ModelConfig();
        $config->setFunctionDeclarations([new FunctionDeclaration('get_weather', 'Gets the weather.')]);
        $config->setWebSearch(new WebSearch());
        $config->setCustomOptions(['tools' => [self::CODE_EXECUTION_TOOL]]);

        $this->generate($config, [$this->responseData('end_turn')]);

        $tools = $this->sentRequests[0]['tools'];
        $this->assertCount(3, $tools);
        $this->assertSame('get_weather', $tools[0]['name']);
        $this->assertSame('web_search', $tools[1]['name']);
        $this->assertSame(self::CODE_EXECUTION_TOOL, $tools[2]);
    }

    /**
     * Tests that other custom options still may not override generated parameters.
     *
     * @since n.e.x.t
     */
    public function testOtherConflictingCustomOptionsStillThrow(): void
    {
        $config = new ModelConfig();
        $config->setCustomOptions(['model' => 'other-model']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The custom option "model" conflicts with an existing parameter.');

        $this->generate($config, [$this->responseData('end_turn')]);
    }

    /**
     * Tests that continuing a paused turn reuses the container and keeps its skills.
     *
     * @since n.e.x.t
     */
    public function testPausedTurnKeepsSkillsWhenReusingContainer(): void
    {
        $config = new ModelConfig();
        $config->setCustomOptions([
            'container' => self::SKILLS_CONTAINER,
            'tools' => [self::CODE_EXECUTION_TOOL],
        ]);

        $paused = $this->responseData('pause_turn');
        $paused['container'] = ['id' => 'container_123', 'expires_at' => '2026-11-17T00:00:00Z'];

        $this->generate($config, [$paused, $this->responseData('end_turn')]);

        $this->assertCount(2, $this->sentRequests);
        $this->assertSame(
            ['skills' => self::SKILLS_CONTAINER['skills'], 'id' => 'container_123'],
            $this->sentRequests[1]['container']
        );
    }

    /**
     * Tests that a paused turn without a container option sends the container ID alone.
     *
     * @since n.e.x.t
     */
    public function testPausedTurnSendsContainerIdWhenNoContainerOption(): void
    {
        $paused = $this->responseData('pause_turn');
        $paused['container'] = ['id' => 'container_123'];

        $this->generate(new ModelConfig(), [$paused, $this->responseData('end_turn')]);

        $this->assertArrayNotHasKey('container', $this->sentRequests[0]);
        $this->assertSame('container_123', $this->sentRequests[1]['container']);
    }

    /**
     * Runs a text generation against canned API responses, recording the request bodies.
     *
     * @param ModelConfig                $config    The model config.
     * @param list<array<string, mixed>> $responses Response bodies, returned in order.
     * @return GenerativeAiResult The generation result.
     */
    private function generate(ModelConfig $config, array $responses): GenerativeAiResult
    {
        $model = new AnthropicTextGenerationModel(
            new ModelMetadata('claude-test', 'Claude Test', [], []),
            AnthropicProvider::metadata()
        );
        $model->setConfig($config);
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication('test-key'));

        $sentRequests = &$this->sentRequests;
        $model->setHttpTransporter(
            new class ($responses, $sentRequests) implements HttpTransporterInterface {
                /** @var list<array<string, mixed>> */
                private array $responses;

                /** @var list<array<string, mixed>> */
                private array $sentRequests;

                /**
                 * @param list<array<string, mixed>> $responses    Response bodies, returned in order.
                 * @param list<array<string, mixed>> $sentRequests Receives the request bodies.
                 */
                public function __construct(array $responses, array &$sentRequests)
                {
                    $this->responses = $responses;
                    $this->sentRequests = &$sentRequests;
                }

                public function send(Request $request, ?RequestOptions $options = null): Response
                {
                    $this->sentRequests[] = $request->getData() ?? [];
                    $body = array_shift($this->responses);
                    if ($body === null) {
                        throw new \RuntimeException('Unexpected extra request.');
                    }
                    return new Response(200, [], (string) json_encode($body));
                }
            }
        );

        return $model->generateTextResult([new UserMessage([new MessagePart('Hello')])]);
    }

    /**
     * Builds a Messages API response body.
     *
     * @param string                          $stopReason The stop reason.
     * @param list<array<string, mixed>>|null $content    The content blocks, or null for a single text block.
     * @return array<string, mixed> The response body.
     */
    private function responseData(string $stopReason, ?array $content = null): array
    {
        return [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => $content ?? [['type' => 'text', 'text' => 'Hi']],
            'stop_reason' => $stopReason,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }
}
