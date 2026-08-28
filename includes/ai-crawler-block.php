<?php
/**
 * Opt-in block for AI training / scraping crawlers (BLOCK_AI_CRAWLERS in config).
 * Default is off so Claude, ChatGPT, etc. can read public pages when given a link.
 */

declare(strict_types=1);

function ai_crawler_block_enabled(): bool
{
    return defined('BLOCK_AI_CRAWLERS') && BLOCK_AI_CRAWLERS;
}

/**
 * @return list<string>
 */
function ai_crawler_blocked_agents(): array
{
    return [
        'GPTBot',
        'ChatGPT-User',
        'ClaudeBot',
        'anthropic-ai',
        'Claude-Web',
        'Google-Extended',
        'Bytespider',
        'CCBot',
        'cohere-ai',
        'Diffbot',
        'FacebookBot',
        'Meta-ExternalAgent',
        'PerplexityBot',
        'Applebot-Extended',
        'Omgilibot',
        'YouBot',
        'Amazonbot',
        'ImagesiftBot',
        'Ai2Bot',
        'FriendlyCrawler',
        'img2dataset',
        'Scrapy',
        'Timpibot',
        'Webzio-Extended',
    ];
}

function ai_crawler_is_blocked_agent(string $ua): bool
{
    if ($ua === '') {
        return false;
    }

    foreach (ai_crawler_blocked_agents() as $token) {
        if (stripos($ua, $token) !== false) {
            return true;
        }
    }

    return false;
}

function ai_crawler_is_exempt_request(): bool
{
    $uri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
    $script = strtolower(basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')));

    if ($script === 'robots.php' || str_contains($uri, '/robots.txt')) {
        return true;
    }

    if (str_contains($uri, '/api/cron') || $script === 'cron.php') {
        return true;
    }

    if (str_contains($uri, 'webhook') || str_contains($uri, '/api/meta-')) {
        return true;
    }

    if (function_exists('security_has_privileged_key') && security_has_privileged_key()) {
        return true;
    }

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    if (!empty($_SESSION['user_id'])) {
        return true;
    }

    return false;
}

function ai_crawler_block_request(): void
{
    if (PHP_SAPI === 'cli' || !ai_crawler_block_enabled() || ai_crawler_is_exempt_request()) {
        return;
    }

    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (!ai_crawler_is_blocked_agent($ua)) {
        return;
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Access denied';
    exit;
}

function ai_crawler_robots_txt(): string
{
    if (!ai_crawler_block_enabled()) {
        return "User-agent: *\nAllow: /\n";
    }

    $lines = [
        'User-agent: *',
        'Disallow: /',
    ];

    foreach (ai_crawler_blocked_agents() as $agent) {
        $lines[] = '';
        $lines[] = 'User-agent: ' . $agent;
        $lines[] = 'Disallow: /';
    }

    return implode("\n", $lines) . "\n";
}
