<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Analytics/EventValidator.php';
require_once __DIR__ . '/../src/Analytics/FileAnalyticsStore.php';
require_once __DIR__ . '/../src/Analytics/EventFlattener.php';

// Legacy event-count checkpoints ("1\n") must be recovered as byte offsets:
// a value that does not sit on a line boundary resets to 0 so the day
// re-flattens (idempotent via _event_ids dedup) and the checkpoint is
// rewritten in byte-offset format. Regression for the simple1300numbers
// checkpoint_regression_test that failed against the vendored engine
// (2026-11-05): the probe existed only in the consumer repo's src/ copy.

$root = sys_get_temp_dir() . '/loom-checkpoint-' . bin2hex(random_bytes(5));
$store = new Loom\Analytics\FileAnalyticsStore($root, new Loom\Analytics\EventValidator());
$event = [
    'occurred_at' => '2026-08-25T10:00:00+00:00',
    'visitor_id' => 'vis_1234567890abcdef',
    'session_id' => 'ses_1234567890abcdef',
    'path' => '/pricing/',
    'url' => 'https://example.com/pricing/',
];
$store->append($event + ['event_id' => 'evt_1234567890abcdef', 'event_type' => 'page_view']);
$store->append($event + ['event_id' => 'evt_2234567890abcdef', 'event_type' => 'conversion']);
$checkpoint = $root . '/checkpoints/events-2026-08-25.offset';
mkdir(dirname($checkpoint), 0750, true);
file_put_contents($checkpoint, "1\n"); // legacy event-count checkpoint, not a byte offset
$result = (new Loom\Analytics\EventFlattener($root))->flatten(new DateTimeImmutable('2026-08-25T12:00:00+00:00'));
$properties = json_decode((string)file_get_contents($root . '/properties/2026/08/25.json'), true);
if ($result['events_processed'] !== 2 || $properties['events_total'] !== 2 || (int)file_get_contents($checkpoint) !== filesize($root . '/events/2026/08/25.ndjson')) {
    throw new RuntimeException('mid-line legacy checkpoint was not recovered as a byte offset');
}

// A checkpoint already on a line boundary must NOT reset (resume in place).
$ndjson = $root . '/events/2026/08/25.ndjson';
$firstLineEnd = (int) strpos((string) file_get_contents($ndjson), "\n") + 1;
$store->append($event + ['event_id' => 'evt_3234567890abcdef', 'event_type' => 'page_view']);
file_put_contents($checkpoint, (string) $firstLineEnd . "\n");
$result = (new Loom\Analytics\EventFlattener($root))->flatten(new DateTimeImmutable('2026-08-25T13:00:00+00:00'));
$properties = json_decode((string)file_get_contents($root . '/properties/2026/08/25.json'), true);
if ($result['events_processed'] !== 1 || $properties['events_total'] !== 3 || (int)file_get_contents($checkpoint) !== filesize($ndjson)) {
    // line 1-2 already deduped from the first flatten; only evt_3 is new
    throw new RuntimeException('aligned checkpoint did not resume from the boundary');
}

echo "checkpoint_regression: PASS\n";
