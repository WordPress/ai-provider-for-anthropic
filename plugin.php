<?php

/**
 * Plugin Name: AI Provider for Anthropic
 * Plugin URI: https://github.com/WordPress/ai-provider-for-anthropic
 * Description: AI Provider for Anthropic for the WordPress AI Client.
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Version: 1.0.3
 * Author: WordPress AI Team
 * Author URI: https://make.wordpress.org/ai/
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: ai-provider-for-anthropic
 *
 * @package WordPress\AnthropicAiProvider
 */

declare(strict_types=1);

namespace WordPress\AnthropicAiProvider;

use WordPress\AiClient\AiClient;
use WordPress\AnthropicAiProvider\Provider\AnthropicProvider;

if (!defined('ABSPATH')) {
    return;
}

require_once __DIR__ . '/src/autoload.php';

/**
 * Registers the AI Provider for Anthropic with the AI Client.
 *
 * @since 1.0.0
 *
 * @return void
 */
function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    $registry = AiClient::defaultRegistry();

    if ($registry->hasProvider(AnthropicProvider::class)) {
        return;
    }

    $registry->registerProvider(AnthropicProvider::class);
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);
