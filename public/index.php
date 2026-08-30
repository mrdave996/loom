<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Loom\Router;
use Loom\ContentLoader;
use Loom\Renderer;
use Loom\CacheManager;
use Loom\SeoGenerator;
use Loom\Analytics\AnalyticsEndpoint;
use Loom\Analytics\EventValidator;
use Loom\Analytics\FileAnalyticsStore;

$rootDir = dirname(__DIR__);
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?? '/';

$configFile = $rootDir . '/config/site.php';
$siteConfig = is_file($configFile) ? include $configFile : [];

$router = new Router($rootDir . '/content');
$loader = new ContentLoader();
$renderer = new Renderer($rootDir . '/templates');
$cache = new CacheManager($rootDir . '/cache');
$seo = new SeoGenerator($rootDir . '/content', $siteConfig['domain'] ?? '');

$analyticsEnabled = ($siteConfig['analytics']['enabled'] ?? false) === true || getenv('LOOM_ANALYTICS_ENABLED') === '1';
$analyticsRoot = getenv('LOOM_ANALYTICS_DIR') ?: ($siteConfig['analytics']['directory'] ?? ($rootDir . '/private/analytics'));
if ($requestPath === '/analytics/event') {
	$analytics = new AnalyticsEndpoint(new FileAnalyticsStore($analyticsRoot, new EventValidator()), $analyticsEnabled);
	$response = $analytics->process($_SERVER['REQUEST_METHOD'] ?? 'GET', (string)file_get_contents('php://input'));
	http_response_code($response['status']);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store, private');
	echo $response['body'];
	exit;
}

// Handle SEO endpoints (sitemap.xml, robots.txt)
$seoResponse = $seo->handle($requestPath);
if ($seoResponse !== null) {
	header($seoResponse['header']);
	echo $seoResponse['body'];
	exit;
}

// Resolve request to a content file
$filePath = $router->resolve($requestUri);

if ($filePath === null) {
	// Check for redirect
	$redirect = $router->getRedirect($requestUri);
	if ($redirect !== null) {
		http_response_code(301);
		header('Location: ' . $redirect);
		exit;
	}

	http_response_code(404);
	echo '<!DOCTYPE html><html><head><title>404</title></head><body><h1>404 — Page Not Found</h1></body></html>';
	exit;
}

// Form pages contain per-session CSRF tokens and must never use the HTML cache.
$page = $loader->load($filePath);
$components = $page['front_matter']['components'] ?? [];
$hasForm = is_array($components) && (in_array('form-contact', $components, true) || in_array('form-signup', $components, true));
$isQuery = parse_url($requestUri, PHP_URL_QUERY) !== null;

// Check cache (needs source file for mtime comparison). POST requests are NEVER
// served from cache — they must always run the page render so form handlers
// (contact, signup) process the submission instead of echoing stale HTML.
$isPost = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
if (!$isPost && !$isQuery && !$hasForm && $cache->isValid($requestPath, $filePath, $rootDir . '/templates')) {
	header('Content-Type: text/html; charset=utf-8');
	echo $cache->get($requestPath);
	exit;
}

// Render through template
$html = $renderer->render($page['front_matter'], $page['body']);

// Never cache query-specific, POST, or session-bound form responses.
if (!$isPost && !$isQuery && !$hasForm) {
	$cache->set($requestPath, $html);
}

// Output
header('Content-Type: text/html; charset=utf-8');
echo $html;
