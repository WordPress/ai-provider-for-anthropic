<?php

declare(strict_types=1);

namespace WordPress\AnthropicAiProvider\Models;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Common\Exception\TokenLimitReachedException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\WebSearch;
use WordPress\AnthropicAiProvider\Authentication\AnthropicApiKeyRequestAuthentication;
use WordPress\AnthropicAiProvider\Provider\AnthropicProvider;

/**
 * Class for an Anthropic text generation model.
 *
 * @since 1.0.0
 *
 * @phpstan-type UsageData array{
 *     input_tokens?: int,
 *     output_tokens?: int,
 *     cache_creation_input_tokens?: int,
 *     cache_read_input_tokens?: int
 * }
 * @phpstan-type ResponseData array{
 *     id?: string,
 *     role?: string,
 *     content?: list<array<string, mixed>>,
 *     container?: array{id?: string},
 *     stop_reason?: string,
 *     usage?: UsageData
 * }
 */
class AnthropicTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface
{
    public const MAX_TOOL_CONTINUATIONS = 5;

    /**
     * Default maximum number of tokens for text generation.
     *
     * The Anthropic API requires `max_tokens` to always be present in the request,
     * so this value is used as a fallback when no limit is configured.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_MAX_TOKENS = 4096;
    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    public function getRequestAuthentication(): RequestAuthenticationInterface
    {
        /*
         * Since we're calling the Anthropic API here, we need to use the Anthropic specific
         * API key authentication class.
         */
        $requestAuthentication = parent::getRequestAuthentication();
        if (!$requestAuthentication instanceof ApiKeyRequestAuthentication) {
            return $requestAuthentication;
        }
        return new AnthropicApiKeyRequestAuthentication($requestAuthentication->getApiKey());
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    final public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $httpTransporter = $this->getHttpTransporter();

        $params = $this->prepareGenerateTextParams($prompt);

        $headers = ['Content-Type' => 'application/json'];

        // Add beta header for structured outputs if JSON schema output is requested.
        $config = $this->getConfig();
        if ('application/json' === $config->getOutputMimeType() && $config->getOutputSchema()) {
            $headers['anthropic-beta'] = 'structured-outputs-2025-11-13';
        }

        /*
         * When a server-side tool (such as web search) makes a turn run long, the API returns
         * it with `stop_reason: "pause_turn"` and expects the same conversation to be sent
         * again, with the paused assistant turn appended and the identical tools, so that
         * the model can continue where it left off. Without that, the caller is handed a
         * turn that looks finished but only contains the first fragment of the answer.
         *
         * The content and token usage of every leg are accumulated so the caller receives one
         * complete result. The number of continuations is bounded to avoid an endless loop.
         */
        $accumulatedContent = [];
        $accumulatedUsage = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
        ];
        $lastResponseData = null;

        /** @var list<array<string, mixed>> $messagesParam */
        $messagesParam = isset($params['messages']) && is_array($params['messages'])
            ? $params['messages']
            : [];

        for ($i = 0; $i <= self::MAX_TOOL_CONTINUATIONS; $i++) {
            $params['messages'] = $messagesParam;

            $request = new Request(
                HttpMethodEnum::POST(),
                AnthropicProvider::url('messages'),
                $headers,
                $params,
                $this->getRequestOptions()
            );

            // Add authentication credentials to the request.
            $request = $this->getRequestAuthentication()->authenticateRequest($request);

            // Send and process the request.
            $response = $httpTransporter->send($request);
            ResponseUtil::throwIfNotSuccessful($response);

            /** @var ResponseData $responseData */
            $responseData = $response->getData();
            $lastResponseData = $responseData;

            if (isset($responseData['content']) && is_array($responseData['content'])) {
                foreach ($responseData['content'] as $contentPart) {
                    $accumulatedContent[] = $contentPart;
                }
            }

            if (isset($responseData['usage']) && is_array($responseData['usage'])) {
                $usage = $responseData['usage'];
                $accumulatedUsage['input_tokens'] += ($usage['input_tokens'] ?? 0);
                $accumulatedUsage['output_tokens'] += ($usage['output_tokens'] ?? 0);
                $accumulatedUsage['cache_creation_input_tokens'] += ($usage['cache_creation_input_tokens'] ?? 0);
                $accumulatedUsage['cache_read_input_tokens'] += ($usage['cache_read_input_tokens'] ?? 0);
            }

            $stopReason = $responseData['stop_reason'] ?? null;
            if ('pause_turn' !== $stopReason || $i >= self::MAX_TOOL_CONTINUATIONS) {
                break;
            }

// Preserve state for server tools backed by a container, such as code execution.
$container = $responseData['container'] ?? null;
if (
    is_array($container) &&
    isset($container['id']) &&
    is_string($container['id'])
) {
    $params['container'] = $container['id'];
}
            // Append assistant response to messages parameter for continuation.
            $role = isset($responseData['role']) && is_string($responseData['role'])
                ? $responseData['role']
                : 'assistant';
            $content = isset($responseData['content']) && is_array($responseData['content'])
                ? $responseData['content']
                : [];
            $messagesParam[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        if (!$lastResponseData) {
            throw new RuntimeException('No response received from API.');
        }

        $lastResponseData['content'] = $this->mergeTextBlocks($accumulatedContent);
        $lastResponseData['usage'] = $accumulatedUsage;

        return $this->parseResponseDataToGenerativeAiResult($lastResponseData);
    }

    /**
     * Merges the text slices of an assistant turn into a single text block.
     *
     * A turn that uses a server-side tool (such as web search) carries its answer as several
     * text blocks: the model emits a slice of text, then a `server_tool_use` /
     * `web_search_tool_result` pair, then the next slice, and so on — within a single response
     * as well as across the legs of a turn that paused with `pause_turn`. Consumers read the
     * answer from the first content text part of the message — GenerativeAiResult::toText()
     * returns that part and stops — so leaving the slices apart would surface only the first
     * fragment and silently discard the rest of an answer that was fully generated and paid
     * for. The slices belong to a single assistant turn, so they are joined into the first
     * text block; non-text blocks and turns with a single text block pass through unchanged.
     *
     * @since n.e.x.t
     *
     * @param list<array<string, mixed>> $content The accumulated content blocks.
     * @return list<array<string, mixed>> The content blocks with the text slices joined.
     */
    protected function mergeTextBlocks(array $content): array
    {
        $merged = [];
        $textIndex = null;

        foreach ($content as $block) {
            $text = $block['text'] ?? null;

            if (($block['type'] ?? null) === 'text' && is_string($text)) {
                if ($textIndex !== null) {
                    $previous = $merged[$textIndex]['text'];
                    $merged[$textIndex]['text'] = (is_string($previous) ? $previous : '') . $text;
                    continue;
                }

                $textIndex = count($merged);
            }

            $merged[] = $block;
        }

        return $merged;
    }

    /**
     * Prepares the given prompt and the model configuration into parameters for the API request.
     *
     * @since 1.0.0
     *
     * @param list<Message> $prompt The prompt to generate text for. Either a single message or a list of messages
     *                              from a chat.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $config = $this->getConfig();

        $params = [
            'model' => $this->metadata()->getId(),
            'messages' => $this->prepareMessagesParam($prompt),
        ];

        $systemInstruction = $config->getSystemInstruction();
        if ($systemInstruction) {
            $params['system'] = $systemInstruction;
        }

        $maxTokens = $config->getMaxTokens();
        if ($maxTokens !== null) {
            $params['max_tokens'] = $maxTokens;
        } else {
            // The 'max_tokens' parameter is required in the Anthropic API, so we need a default.
            $params['max_tokens'] = self::DEFAULT_MAX_TOKENS;
        }

        $temperature = $config->getTemperature();
        if ($temperature !== null) {
            $params['temperature'] = $temperature;
        }

        $topP = $config->getTopP();
        if ($topP !== null) {
            $params['top_p'] = $topP;
        }

        $topK = $config->getTopK();
        if ($topK !== null) {
            $params['top_k'] = $topK;
        }

        $stopSequences = $config->getStopSequences();
        if (is_array($stopSequences)) {
            $params['stop_sequences'] = $stopSequences;
        }

        $outputMimeType = $config->getOutputMimeType();
        $outputSchema = $config->getOutputSchema();
        if ($outputMimeType === 'application/json' && $outputSchema) {
            $params['output_format'] = [
                'type' => 'json_schema',
                'schema' => $outputSchema,
            ];
        }

        $functionDeclarations = $config->getFunctionDeclarations();
        $webSearch = $config->getWebSearch();
        if (is_array($functionDeclarations) || $webSearch) {
            $params['tools'] = $this->prepareToolsParam($functionDeclarations, $webSearch);
        }

        /*
         * Any custom options are added to the parameters as well.
         * This allows developers to pass other options that may be more niche or not yet supported by the SDK.
         */
        $customOptions = $config->getCustomOptions();
        foreach ($customOptions as $key => $value) {
            if (isset($params[$key])) {
                throw new InvalidArgumentException(
                    sprintf(
                        'The custom option "%s" conflicts with an existing parameter.',
                        $key
                    )
                );
            }
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Prepares the messages parameter for the API request.
     *
     * @since 1.0.0
     *
     * @param list<Message> $messages The messages to prepare.
     * @return list<array<string, mixed>> The prepared messages parameter.
     */
    protected function prepareMessagesParam(array $messages): array
    {
        return array_map(
            function (Message $message): array {
                $content = array_values(array_filter(array_map(
                    [$this, 'getMessagePartData'],
                    $message->getParts()
                )));

                return [
                'role' => $this->getMessageRoleString($message->getRole()),
                'content' => array_values(array_filter(array_map(
                    [$this, 'getMessagePartData'],
                    $message->getParts()
                ))),
                ];
            },
            $messages
        );
    }

    /**
     * Removes thinking blocks that carry no signature from a message's content.
     *
     * The API rejects any `thinking` block that is sent without its signature, so a block
     * whose signature was lost (for example in conversation history that was persisted
     * before the signature was preserved) would fail the whole request. Since thinking
     * blocks only have to be echoed back for the assistant turn they belong to, dropping
     * such a block is preferable to sending one that is guaranteed to be rejected. The
     * block is kept if it is the only content left, so that a message never ends up empty.
     *
     * @since n.e.x.t
     *
     * @param list<array<string, mixed>> $content The prepared content blocks.
     * @return list<array<string, mixed>> The content blocks to send.
     */
    protected function removeUnsignedThinkingBlocks(array $content): array
    {
        $filtered = array_values(array_filter(
            $content,
            static function (array $block): bool {
                return ($block['type'] ?? null) !== 'thinking' || isset($block['signature']);
            }
        ));

        return $filtered !== [] ? $filtered : $content;
    }

    /**
     * Returns the Anthropic API specific role string for the given message role.
     *
     * @since 1.0.0
     *
     * @param MessageRoleEnum $role The message role.
     * @return string The role for the API request.
     */
    protected function getMessageRoleString(MessageRoleEnum $role): string
    {
        if ($role === MessageRoleEnum::model()) {
            return 'assistant';
        }
        return 'user';
    }

    /**
     * Returns the Anthropic API specific data for a message part.
     *
     * @since 1.0.0
     *
     * @param MessagePart $part The message part to get the data for.
     * @return ?array<string, mixed> The data for the message part, or null if not applicable.
     * @throws InvalidArgumentException If the message part type or data is unsupported.
     */
    protected function getMessagePartData(MessagePart $part): ?array
    {
        $type = $part->getType();
        if ($type->isText()) {
            if ($part->getChannel()->isThought()) {
                $thinkingData = [
                    'type' => 'thinking',
                    'thinking' => $part->getText(),
                ];
                $signature = $this->getMessagePartThoughtSignature($part);
                if ($signature !== null) {
                    $thinkingData['signature'] = $signature;
                }
                return $thinkingData;
            }
            return [
                'type' => 'text',
                'text' => $part->getText(),
            ];
        }
        if ($type->isFile()) {
            $file = $part->getFile();
            if (!$file) {
                // This should be impossible due to class internals, but still needs to be checked.
                throw new RuntimeException(
                    'The file typed message part must contain a file.'
                );
            }
            if ($file->isRemote()) {
                $fileUrl = $file->getUrl();
                if (!$fileUrl) {
                    throw new RuntimeException(
                        'The remote file must contain a URL.'
                    );
                }
                if ($file->isDocument()) {
                    return [
                        'type' => 'document',
                        'source' => [
                            'type' => 'url',
                            'url' => $fileUrl,
                        ],
                    ];
                }
                throw new InvalidArgumentException(
                    'Unsupported file type: The API only supports inline files for non-document types.'
                );
            }
            // Else, it is an inline file.
            $fileBase64Data = $file->getBase64Data();
            if (!$fileBase64Data) {
                // This should be impossible due to class internals, but still needs to be checked.
                throw new RuntimeException(
                    'The inline file must contain base64 data.'
                );
            }
            if ($file->isImage()) {
                return [
                    'type' => 'image',
                    'source' => array(
                        'type' => 'base64',
                        'media_type' => $file->getMimeType(),
                        'data' => $fileBase64Data,
                    ),
                ];
            }
            if ($file->isDocument()) {
                return [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $file->getMimeType(),
                        'data' => $fileBase64Data,
                    ],
                ];
            }
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported MIME type "%s" for inline file message part.',
                    $file->getMimeType()
                )
            );
        }
        if ($type->isFunctionCall()) {
            $functionCall = $part->getFunctionCall();
            if (!$functionCall) {
                // This should be impossible due to class internals, but still needs to be checked.
                throw new RuntimeException(
                    'The function_call typed message part must contain a function call.'
                );
            }
            // Ensure null or empty array becomes empty object for Anthropic's API which expects an object.
            // PHP json_encode([]) produces "[]" (array), but Anthropic requires "{}" (object) for tool_use.input.
            $input = $functionCall->getArgs();
            if ($input === null || (is_array($input) && count($input) === 0)) {
                $input = new \stdClass();
            }
            return [
                'type' => 'tool_use',
                'id' => $functionCall->getId(),
                'name' => $functionCall->getName(),
                'input' => $input,
            ];
        }
        if ($type->isFunctionResponse()) {
            $functionResponse = $part->getFunctionResponse();
            if (!$functionResponse) {
                // This should be impossible due to class internals, but still needs to be checked.
                throw new RuntimeException(
                    'The function_response typed message part must contain a function response.'
                );
            }
            return [
                'type' => 'tool_result',
                'tool_use_id' => $functionResponse->getId(),
                'content' => json_encode($functionResponse->getResponse()),
            ];
        }
        throw new InvalidArgumentException(
            sprintf(
                'Unsupported message part type "%s".',
                $type
            )
        );
    }

    /**
     * Returns the thought signature stored on a message part, if any.
     *
     * @since n.e.x.t
     *
     * @param MessagePart $part The message part to get the thought signature for.
     * @return string|null The thought signature, or null if there is none.
     */
    protected function getMessagePartThoughtSignature(MessagePart $part): ?string
    {
        $thoughtSignature = $part->getThoughtSignature();

        return $thoughtSignature !== null && $thoughtSignature !== '' ? $thoughtSignature : null;
    }

    /**
     * Prepares the tools parameter for the API request.
     *
     * @since 1.0.0
     *
     * @param list<FunctionDeclaration>|null $functionDeclarations The function declarations, or null if none.
     * @param WebSearch|null $webSearch The web search config, or null if none.
     * @return list<array<string, mixed>> The prepared tools parameter.
     */
    protected function prepareToolsParam(?array $functionDeclarations, ?WebSearch $webSearch): array
    {
        $tools = [];

        if (is_array($functionDeclarations)) {
            foreach ($functionDeclarations as $functionDeclaration) {
                /*
                 * Anthropic requires input_schema to always be present, even for
                 * functions with no parameters. Use an empty object schema in that case.
                 */
                $inputSchema = $functionDeclaration->getParameters();
                if ($inputSchema === null) {
                    $inputSchema = [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                    ];
                }

                $tools[] = array_filter([
                    'name' => $functionDeclaration->getName(),
                    'description' => $functionDeclaration->getDescription(),
                    'input_schema' => $inputSchema,
                ]);
            }
        }

        if ($webSearch) {
            $tools[] = array_filter([
                'type' => 'web_search_20250305',
                'name' => 'web_search',
                'max_uses' => 1,
                'allowed_domains' => $webSearch->getAllowedDomains(),
                'blocked_domains' => $webSearch->getDisallowedDomains(),
            ]);
        }

        return $tools;
    }

    /**
     * Parses the response from the API endpoint to a generative AI result.
     *
     * @since 1.0.0
     *
     * @param Response $response The response from the API endpoint.
     * @return GenerativeAiResult The parsed generative AI result.
     */
    protected function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        /** @var ResponseData $responseData */
        $responseData = $response->getData();
        return $this->parseResponseDataToGenerativeAiResult($responseData);
    }

    /**
     * Parses the response data from the API endpoint to a generative AI result.
     *
     * @since n.e.x.t
     *
     * @param ResponseData $responseData The response data from the API endpoint.
     * @return GenerativeAiResult The parsed generative AI result.
     */
    protected function parseResponseDataToGenerativeAiResult(array $responseData): GenerativeAiResult
    {
        if (!isset($responseData['content']) || !$responseData['content']) {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'content');
        }
        if (!is_array($responseData['content']) || !array_is_list($responseData['content'])) {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'content',
                'The value must be an indexed array.'
            );
        }

        $role = isset($responseData['role']) && 'user' === $responseData['role']
            ? MessageRoleEnum::user()
            : MessageRoleEnum::model();

        $parts = [];
        foreach ($responseData['content'] as $partIndex => $messagePartData) {
            try {
                $newPart = $this->parseResponseContentMessagePart($messagePartData);
                if ($newPart) {
                    $parts[] = $newPart;
                }
            } catch (InvalidArgumentException $e) {
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    "content[{$partIndex}]",
                    $e->getMessage()
                );
            }
        }

        if (!isset($responseData['stop_reason'])) {
            throw ResponseException::fromMissingData(
                $this->providerMetadata()->getName(),
                'stop_reason'
            );
        }

        switch ($responseData['stop_reason']) {
            /*
             * A paused turn is normally continued in generateTextResult() before the response
             * reaches this point, so `pause_turn` only survives when the continuation limit was
             * exhausted. The turn is treated as finished in that case; the raw stop reason is
             * kept in the result metadata below so callers can still tell the two apart.
             */
            case 'pause_turn':
            case 'end_turn':
            case 'stop_sequence':
                $finishReason = FinishReasonEnum::stop();
                break;
            case 'max_tokens':
            case 'model_context_window_exceeded':
                $maxTokens = $this->getConfig()->getMaxTokens() ?? self::DEFAULT_MAX_TOKENS;
                throw new TokenLimitReachedException(
                    sprintf(
                        'Generation stopped due to token limit (%d) with stop reason "%s".',
                        $maxTokens,
                        $responseData['stop_reason']
                    ),
                    $maxTokens
                );
            case 'refusal':
                $finishReason = FinishReasonEnum::contentFilter();
                break;
            case 'tool_use':
                $finishReason = FinishReasonEnum::toolCalls();
                break;
            default:
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    'stop_reason',
                    sprintf('Invalid stop reason "%s".', $responseData['stop_reason'])
                );
        }

        $candidates = [new Candidate(
            new Message($role, $parts),
            $finishReason
        )];

        $id = isset($responseData['id']) && is_string($responseData['id']) ? $responseData['id'] : '';

        if (isset($responseData['usage']) && is_array($responseData['usage'])) {
            $usage = $responseData['usage'];
            $inputTokens = ($usage['input_tokens'] ?? 0) +
                ($usage['cache_creation_input_tokens'] ?? 0) +
                ($usage['cache_read_input_tokens'] ?? 0);

            $tokenUsage = new TokenUsage(
                $inputTokens,
                $usage['output_tokens'] ?? 0,
                $inputTokens + ($usage['output_tokens'] ?? 0)
            );
        } else {
            $tokenUsage = new TokenUsage(0, 0, 0);
        }

        /*
         * Use any other data from the response as provider-specific response metadata. The raw
         * `stop_reason` is kept: several Anthropic stop reasons map onto the same
         * FinishReasonEnum value, so dropping it would leave callers unable to distinguish, for
         * example, a turn that ended normally from one that is still paused.
         */
        $additionalData = $responseData;
        unset(
            $additionalData['id'],
            $additionalData['role'],
            $additionalData['content'],
            $additionalData['usage']
        );

        return new GenerativeAiResult(
            $id,
            $candidates,
            $tokenUsage,
            $this->providerMetadata(),
            $this->metadata(),
            $additionalData
        );
    }

    /**
     * Parses a message part from the content in the API response.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $partData The message part data from the API response.
     * @return MessagePart|null The parsed message part, or null to ignore.
     */
    protected function parseResponseContentMessagePart(array $partData): ?MessagePart
    {
        if (!isset($partData['type'])) {
            throw new InvalidArgumentException('Part is missing a type field.');
        }

        switch ($partData['type']) {
            case 'text':
                if (!isset($partData['text']) || !is_string($partData['text'])) {
                    throw new InvalidArgumentException('Part has an invalid text shape.');
                }
                return new MessagePart($partData['text']);
            case 'thinking':
                if (!isset($partData['thinking']) || !is_string($partData['thinking'])) {
                    throw new InvalidArgumentException('Part has an invalid thinking shape.');
                }
                /*
                 * The API requires every thinking block to be echoed back unchanged on all
                 * following turns of the same conversation, including its opaque signature.
                 * Without it, the next request fails with
                 * "messages.N.content.0.thinking.signature: Field required".
                 */
                $signature = isset($partData['signature']) && is_string($partData['signature'])
                    ? $partData['signature']
                    : null;
                if ($signature !== null && $signature !== '') {
                    return new MessagePart(
                        $partData['thinking'],
                        MessagePartChannelEnum::thought(),
                        $signature
                    );
                }
                return new MessagePart($partData['thinking'], MessagePartChannelEnum::thought());
            case 'tool_use':
                if (
                    !isset($partData['id']) ||
                    !is_string($partData['id']) ||
                    !isset($partData['name']) ||
                    !is_string($partData['name']) ||
                    !isset($partData['input'])
                ) {
                    throw new InvalidArgumentException('Part has an invalid tool_use shape.');
                }
                /*
                 * Normalize empty object/array to null.
                 * Anthropic returns `input: {}` for functions with no arguments,
                 * which becomes an empty array after json_decode. Semantically,
                 * an empty object means "no arguments".
                 */
                $args = $partData['input'];
                if (is_array($args) && count($args) === 0) {
                    $args = null;
                }
                return new MessagePart(
                    new FunctionCall(
                        $partData['id'],
                        $partData['name'],
                        $args
                    )
                );
            case 'redacted_thinking':
            case 'server_tool_use':
            case 'web_search_tool_result':
                // No special handling for now. These can be ignored for now.
                return null;
        }

        throw new InvalidArgumentException('Part has an unexpected type.');
    }
}
