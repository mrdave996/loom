<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Analytics/EventValidator.php';
require_once __DIR__ . '/../src/Analytics/FileAnalyticsStore.php';
require_once __DIR__ . '/../src/Analytics/AnalyticsEndpoint.php';

$root = sys_get_temp_dir() . '/loom-shared-analytics-' . bin2hex(random_bytes(5));
$endpoint = new Loom\Analytics\AnalyticsEndpoint(new Loom\Analytics\FileAnalyticsStore($root), true);
$response = $endpoint->process('POST', json_encode(['event_type'=>'page_view','path'=>'/','metadata'=>['source'=>'test']], JSON_THROW_ON_ERROR));
if ($response['status'] !== 202) throw new RuntimeException('Expected 202.');
$files = glob($root . '/events/*/*/*.ndjson') ?: [];
if (count($files) !== 1) throw new RuntimeException('Expected one event file.');
$bad = $endpoint->process('POST', json_encode(['event_type'=>'page_view','metadata'=>['email'=>'blocked@example.test']], JSON_THROW_ON_ERROR));
if ($bad['status'] !== 400) throw new RuntimeException('Expected PII rejection.');
foreach ($files as $file) unlink($file);
foreach ([$root . '/events/' . gmdate('Y') . '/' . gmdate('m'), $root . '/events/' . gmdate('Y'), $root . '/events', $root] as $dir) if (is_dir($dir)) rmdir($dir);
echo "web_loom_analytics_smoke: PASS\n";
