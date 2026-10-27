<?php

declare(strict_types=1);

namespace WordPress\AnthropicAiProvider\Tests\Models;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ServerException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AnthropicAiProvider\Models\AnthropicTextGenerationModel;
use WordPress\AnthropicAiProvider\Provider\AnthropicProvider;

/**
 * Tests that the model surfaces HTTP error responses correctly.
 *
 * @since n.e.x.t
 */
class AnthropicTextGenerationModelErrorHandlingTest extends TestCase
{
    /**
     * Creates a model instance wired with a test API key.
     *
     * @since n.e.x.t
     */
    private function createModel(): AnthropicTextGenerationModel
    {
        $model = new AnthropicTextGenerationModel(
            new ModelMetadata('claude-test', 'Claude Test', [], []),
            AnthropicProvider::metadata()
        );
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication('test-key'));
        return $model;
    }

    /**
     * Creates a mock transporter that returns the given response for every send() call.
     *
     * @since n.e.x.t
     */
    private function createTransporterReturning(Response $response): HttpTransporterInterface
    {
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter->method('send')->willReturn($response);

        /** @var HttpTransporterInterface $transporter */
        return $transporter;
    }

    /**
     * Builds the Anthropic-style JSON error body for the given error type and message.
     *
     * @since n.e.x.t
     *
     * @param string $type    The error type slug (e.g. 'overloaded_error').
     * @param string $message The human-readable error message.
     * @return string JSON-encoded body.
     */
    private function buildErrorBody(string $type, string $message): string
    {
        return (string) json_encode([
            'type'  => 'error',
            'error' => [
                'type'    => $type,
                'message' => $message,
            ],
        ]);
    }

    /**
     * A minimal prompt to trigger the HTTP layer without complex message setup.
     *
     * @since n.e.x.t
     *
     * @return list<UserMessage>
     */
    private function minimalPrompt(): array
    {
        return [new UserMessage([new MessagePart('Hello')])];
    }

    /**
     * When Anthropic returns HTTP 529, a ServerException is thrown with code 529.
     *
     * @since n.e.x.t
     */
    public function testOverloadedResponseThrowsServerExceptionWithCode529(): void
    {
        $model = $this->createModel();
        $model->setHttpTransporter(
            $this->createTransporterReturning(
                new Response(
                    529,
                    ['content-type' => ['application/json']],
                    $this->buildErrorBody('overloaded_error', 'Overloaded')
                )
            )
        );

        $this->expectException(ServerException::class);
        $this->expectExceptionCode(529);

        $model->generateTextResult($this->minimalPrompt());
    }

    /**
     * The exception message for a 529 response includes a human-readable "Overloaded" label.
     *
     * @since n.e.x.t
     */
    public function testOverloadedResponseExceptionMessageContainsOverloaded(): void
    {
        $model = $this->createModel();
        $model->setHttpTransporter(
            $this->createTransporterReturning(
                new Response(
                    529,
                    ['content-type' => ['application/json']],
                    $this->buildErrorBody('overloaded_error', 'Overloaded')
                )
            )
        );
        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('Overloaded (529)');

        $model->generateTextResult($this->minimalPrompt());
        try {
            $model->generateTextResult($this->minimalPrompt());
            $this->fail('Expected ServerException was not thrown.');
        } catch (ServerException $e) {
            $this->assertStringContainsStringIgnoringCase('overloaded', $e->getMessage());
        }
    }

    /**
     * Other 5xx errors (e.g. 503 Service Unavailable) also surface as ServerException.
     *
     * @since n.e.x.t
     */
    public function testServiceUnavailableResponseThrowsServerExceptionWithCode503(): void
    {
        $model = $this->createModel();
        $model->setHttpTransporter(
            $this->createTransporterReturning(
                new Response(503, [], null)
            )
        );

        $this->expectException(ServerException::class);
        $this->expectExceptionCode(503);

        $model->generateTextResult($this->minimalPrompt());
    }
}
