<?php

declare(strict_types=1);

namespace WordPress\AnthropicAiProvider\Tests\Models;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AnthropicAiProvider\Models\AnthropicTextGenerationModel;

/**
 * @covers \WordPress\AnthropicAiProvider\Models\AnthropicTextGenerationModel
 */
class AnthropicTextGenerationModelTest extends TestCase
{
    /**
     * Tests that server tool usage from a single response reaches the result.
     *
     * @since n.e.x.t
     */
    public function testServerToolUseIsKeptInAdditionalData(): void
    {
        $result = $this->generateWithResponses([
            $this->responseData('end_turn', 1),
        ]);

        $additionalData = $result->getAdditionalData();
        $this->assertSame(['web_search_requests' => 1], $additionalData['server_tool_use'] ?? null);
        $this->assertArrayNotHasKey('usage', $additionalData);
        $this->assertSame(10, $result->getTokenUsage()->getPromptTokens());
        $this->assertSame(5, $result->getTokenUsage()->getCompletionTokens());
    }

    /**
     * Tests that server tool usage is summed across the legs of a paused turn.
     *
     * @since n.e.x.t
     */
    public function testServerToolUseIsAccumulatedAcrossPausedTurns(): void
    {
        $result = $this->generateWithResponses([
            $this->responseData('pause_turn', 1),
            $this->responseData('end_turn', 1),
        ]);

        $this->assertSame(
            ['web_search_requests' => 2],
            $result->getAdditionalData()['server_tool_use'] ?? null
        );
        $this->assertSame(20, $result->getTokenUsage()->getPromptTokens());
        $this->assertSame(10, $result->getTokenUsage()->getCompletionTokens());
    }

    /**
     * Tests that no server tool usage is reported when the API returns none.
     *
     * @since n.e.x.t
     */
    public function testServerToolUseIsAbsentWhenNotReported(): void
    {
        $result = $this->generateWithResponses([
            $this->responseData('end_turn', null),
        ]);

        $this->assertArrayNotHasKey('server_tool_use', $result->getAdditionalData());
    }

    /**
     * Tests that server tool usage from an earlier leg survives a final leg that reports none.
     *
     * @since n.e.x.t
     */
    public function testServerToolUseSurvivesFinalLegWithoutIt(): void
    {
        $result = $this->generateWithResponses([
            $this->responseData('pause_turn', 1),
            $this->responseData('end_turn', null),
        ]);

        $this->assertSame(
            ['web_search_requests' => 1],
            $result->getAdditionalData()['server_tool_use'] ?? null
        );
    }

    /**
     * Tests that each server tool counter is accumulated independently.
     *
     * @since n.e.x.t
     */
    public function testServerToolUseCountersAreAccumulatedIndependently(): void
    {
        $first = $this->responseData('pause_turn', null);
        $first['usage']['server_tool_use'] = ['web_search_requests' => 2, 'web_fetch_requests' => 1];
        $second = $this->responseData('end_turn', null);
        $second['usage']['server_tool_use'] = ['web_search_requests' => 1, 'web_fetch_requests' => 3];

        $result = $this->generateWithResponses([$first, $second]);

        $this->assertSame(
            ['web_search_requests' => 3, 'web_fetch_requests' => 4],
            $result->getAdditionalData()['server_tool_use'] ?? null
        );
    }

    /**
     * Runs a text generation against canned API responses.
     *
     * @param list<array<string, mixed>> $responses Response bodies, returned in order.
     * @return GenerativeAiResult The generation result.
     */
    private function generateWithResponses(array $responses): GenerativeAiResult
    {
        $model = new AnthropicTextGenerationModel(
            new ModelMetadata('claude-test', 'Claude Test', [], []),
            new ProviderMetadata('anthropic', 'Anthropic', ProviderTypeEnum::cloud())
        );
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication('test-key'));
        $model->setHttpTransporter(
            new class ($responses) implements HttpTransporterInterface {
                /** @var list<array<string, mixed>> */
                private array $responses;

                /**
                 * @param list<array<string, mixed>> $responses Response bodies, returned in order.
                 */
                public function __construct(array $responses)
                {
                    $this->responses = $responses;
                }

                public function send(Request $request, ?RequestOptions $options = null): Response
                {
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
     * @param string   $stopReason        The stop reason.
     * @param int|null $webSearchRequests The web search request count, or null to omit server tool usage.
     * @return array<string, mixed> The response body.
     */
    private function responseData(string $stopReason, ?int $webSearchRequests): array
    {
        $usage = [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ];
        if ($webSearchRequests !== null) {
            $usage['server_tool_use'] = ['web_search_requests' => $webSearchRequests];
        }

        return [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'stop_reason' => $stopReason,
            'usage' => $usage,
        ];
    }
}
