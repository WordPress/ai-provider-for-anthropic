<?php

declare(strict_types=1);

namespace WordPress\AnthropicAiProvider\Tests\Provider;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AnthropicAiProvider\Provider\AnthropicProvider;

/**
 * Tests for the Anthropic provider.
 *
 * @since n.e.x.t
 */
class AnthropicProviderTest extends TestCase
{
    /**
     * Tests provider availability against the WordPress 7.0 PHP AI Client baseline.
     *
     * @since n.e.x.t
     */
    public function testProviderAvailabilitySupportsPhpAiClient131(): void
    {
        $this->assertTrue(version_compare(AiClient::VERSION, '1.3.1', '>='));

        $method = new ReflectionMethod(AnthropicProvider::class, 'createProviderAvailability');
        $method->setAccessible(true);

        $this->assertInstanceOf(
            ListModelsApiBasedProviderAvailability::class,
            $method->invoke(null)
        );
    }
}
