<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Loom\Router;
use Loom\ContentLoader;
use Loom\Renderer;
use Loom\CacheManager;
use Loom\SeoGenerator;

$rootDir = dirname(__DIR__);
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?? '/';

$router = new Router($rootDir . '/content');
$loader = new ContentLoader();
$renderer = new Renderer($rootDir . '/templates');
$cache = new CacheManager($rootDir . '/cache');
$seo = new SeoGenerator($rootDir . '/content');

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

// Check cache (needs source file for mtime comparison)
if ($cache->isValid($requestPath, $filePath, $rootDir . '/templates')) {
	header('Content-Type: text/html; charset=utf-8');
	echo $cache->get($requestPath);
	exit;
}

// Parse content
$page = $loader->load($filePath);

// Render through template
$html = $renderer->render($page['front_matter'], $page['body']);

// Cache the rendered output
$cache->set($requestPath, $html);

// Output
header('Content-Type: text/html; charset=utf-8');
echo $html;
