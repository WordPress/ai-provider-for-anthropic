<?php

/**
 * PHPUnit bootstrap file for the AI Provider for Anthropic package.
 *
 * @since n.e.x.t
 *
 * @package WordPress\AnthropicAiProvider
 */

declare(strict_types=1);

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
$sdkAutoload = getenv('PHP_AI_CLIENT_AUTOLOAD');
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
} elseif (is_string($sdkAutoload) && $sdkAutoload !== '' && file_exists($sdkAutoload)) {
    require_once $sdkAutoload;
}

require_once dirname(__DIR__) . '/src/autoload.php';
