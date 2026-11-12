<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Analytics/EventValidator.php';
require_once __DIR__ . '/../src/Analytics/FileAnalyticsStore.php';
require_once __DIR__ . '/../src/Analytics/AnalyticsEndpoint.php';

$root = sys_get_temp_dir() . '/loom-shared-analytics-' . bin2hex(random_bytes(5));
$endpoint = new Loom\Analytics\AnalyticsEndpoint(new Loom\Analytics\FileAnalyticsStore($root), true);
$_SERVER['HTTP_USER_AGENT'] = 'LoomSmokeBot/1.0 (+https://loom.test)';
$response = $endpoint->process('POST', json_encode(['event_type'=>'page_view','path'=>'/','metadata'=>['source'=>'test']], JSON_THROW_ON_ERROR));
if ($response['status'] !== 202) throw new RuntimeException('Expected 202.');
unset($_SERVER['HTTP_USER_AGENT']);
$exit = $endpoint->process('POST', json_encode(['event_type'=>'page_exit','path'=>'/','metadata'=>['duration_seconds'=>12,'active_duration_seconds'=>9]], JSON_THROW_ON_ERROR));
if ($exit['status'] !== 202) throw new RuntimeException('Expected page-exit 202.');
$_SERVER['HTTP_USER_AGENT'] = str_repeat('A', 400) . ' Mozilla/5.0';
$oversized = $endpoint->process('POST', json_encode(['event_type'=>'cta_click','path'=>'/'], JSON_THROW_ON_ERROR));
if ($oversized['status'] !== 202) throw new RuntimeException('Expected oversized-UA 202 (capped, not rejected).');
unset($_SERVER['HTTP_USER_AGENT']);
$files = glob($root . '/events/*/*/*.ndjson') ?: [];
if (count($files) !== 1) throw new RuntimeException('Expected one event file.');
$lines = array_map(fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($files[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
if (($lines[0]['user_agent'] ?? null) !== 'LoomSmokeBot/1.0 (+https://loom.test)') throw new RuntimeException('Expected recorded user agent on first event.');
if (array_key_exists('user_agent', $lines[1]) && $lines[1]['user_agent'] !== '') throw new RuntimeException('Expected empty user agent when header absent.');
if (strlen($lines[2]['user_agent'] ?? '') !== 255) throw new RuntimeException('Expected oversized user agent capped at 255.');
$bad = $endpoint->process('POST', json_encode(['event_type'=>'page_view','metadata'=>['email'=>'blocked@example.test']], JSON_THROW_ON_ERROR));
if ($bad['status'] !== 400) throw new RuntimeException('Expected PII rejection.');
$store = new Loom\Analytics\FileAnalyticsStore($root . '/validate');
try { $store->append(['event_type'=>'page_view','user_agent'=>str_repeat('x', 256)]); throw new RuntimeException('Expected 256-char user agent rejection.'); } catch (InvalidArgumentException) {}
try { $store->append(['event_type'=>'page_view','user_agent'=>['bot']]); throw new RuntimeException('Expected non-string user agent rejection.'); } catch (InvalidArgumentException) {}
foreach ($files as $file) unlink($file);
foreach ([$root . '/events/' . gmdate('Y') . '/' . gmdate('m'), $root . '/events/' . gmdate('Y'), $root . '/events', $root] as $dir) if (is_dir($dir)) rmdir($dir);
echo "web_loom_analytics_smoke: PASS\n";
