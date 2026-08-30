<?php

declare(strict_types=1);

namespace Loom\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class EventFlattener
{
    private string $rootDir;

    public function __construct(string $rootDir)
    {
        $this->rootDir = rtrim($rootDir, '/');
    }

    /**
     * Process only new complete lines for one UTC day.
     *
     * @return array{events_processed: int, bytes_processed: int}
     */
    public function flatten(DateTimeImmutable $date): array
    {
        $date = $date->setTimezone(new DateTimeZone('UTC'));
        $dateKey = $date->format('Y-m-d');
        $rawPath = sprintf('%s/events/%s/%s/%s.ndjson', $this->rootDir, $date->format('Y'), $date->format('m'), $date->format('d'));
        $propertiesPath = sprintf('%s/properties/%s/%s/%s.json', $this->rootDir, $date->format('Y'), $date->format('m'), $date->format('d'));
        $checkpointPath = $this->rootDir . '/checkpoints/events-' . $dateKey . '.offset';

        if (!is_file($rawPath)) {
            return ['events_processed' => 0, 'bytes_processed' => 0];
        }

        $size = filesize($rawPath);
        $offset = is_file($checkpointPath) ? max(0, (int)file_get_contents($checkpointPath)) : 0;
        if ($size === false) {
            throw new RuntimeException('Unable to read analytics event file size.');
        }
        if ($offset > $size) {
            $offset = 0;
        }

        $handle = fopen($rawPath, 'rb');
        if ($handle === false || fseek($handle, $offset) !== 0) {
            throw new RuntimeException('Unable to open analytics event file for flattening.');
        }

        $properties = $this->loadProperties($propertiesPath, $dateKey);
        $processed = 0;
        $processedBytes = $offset;

        try {
            while (($line = fgets($handle)) !== false) {
                $lineEnd = ftell($handle);
                if ($lineEnd === false) {
                    throw new RuntimeException('Unable to read analytics file offset.');
                }
                if (!str_ends_with($line, "\n")) {
                    break;
                }

                $event = json_decode(trim($line), true);
                if (!is_array($event)) {
                    $processedBytes = $lineEnd;
                    continue;
                }

                $eventId = (string)($event['event_id'] ?? '');
                if ($eventId !== '' && isset($properties['_event_ids'][$eventId])) {
                    $processedBytes = $lineEnd;
                    continue;
                }

                $this->applyEvent($properties, $event);
                if ($eventId !== '') {
                    $properties['_event_ids'][$eventId] = true;
                }
                $processed++;
                $processedBytes = $lineEnd;
            }
        } finally {
            fclose($handle);
        }

        if ($processed > 0 || !is_file($propertiesPath)) {
            $properties['updated_at'] = gmdate('c');
            $this->atomicWriteJson($propertiesPath, $properties);
        }

        if ($processedBytes !== $offset) {
            $this->atomicWriteText($checkpointPath, (string)$processedBytes . "\n");
        }

        return ['events_processed' => $processed, 'bytes_processed' => $processedBytes - $offset];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadProperties(string $path, string $date): array
    {
        if (is_file($path)) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded)) {
                $decoded['_event_ids'] ??= [];
                $decoded['_visitor_ids'] ??= [];
                $decoded['_session_ids'] ??= [];
                return $decoded;
            }
        }

        return [
            'version' => 1,
            'date' => $date,
            'updated_at' => gmdate('c'),
            'events_total' => 0,
            'unique_visitors' => 0,
            'unique_sessions' => 0,
            'event_counts' => [],
            'paths' => [],
            'sources' => [],
            'keywords' => [],
            'keyword_landing_pages' => [],
            'conversions' => 0,
            '_event_ids' => [],
            '_visitor_ids' => [],
            '_session_ids' => [],
        ];
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $event
     */
    private function applyEvent(array &$properties, array $event): void
    {
        $properties['events_total']++;
        $eventType = (string)($event['event_type'] ?? 'unknown');
        $properties['event_counts'][$eventType] = ($properties['event_counts'][$eventType] ?? 0) + 1;

        foreach ([['visitor_id', '_visitor_ids', 'unique_visitors'], ['session_id', '_session_ids', 'unique_sessions']] as [$eventKey, $setKey, $countKey]) {
            $identifier = (string)($event[$eventKey] ?? '');
            if ($identifier !== '' && !isset($properties[$setKey][$identifier])) {
                $properties[$setKey][$identifier] = true;
                $properties[$countKey]++;
            }
        }

        $path = (string)($event['path'] ?? '');
        if ($path !== '') {
            $properties['paths'][$path] = ($properties['paths'][$path] ?? 0) + 1;
        }

        $sourceKey = $this->classifySource($event);
        $properties['sources'][$sourceKey] = ($properties['sources'][$sourceKey] ?? 0) + 1;

        $keyword = trim((string)($event['search_keyword'] ?? ''));
        if ($keyword === '') {
            $keyword = trim((string)($event['utm_term'] ?? ''));
        }
        if ($keyword !== '') {
            $properties['keywords'][$keyword] = ($properties['keywords'][$keyword] ?? 0) + 1;
            if ($path !== '') {
                $properties['keyword_landing_pages'][$keyword] ??= [];
                $properties['keyword_landing_pages'][$keyword][$path] =
                    ($properties['keyword_landing_pages'][$keyword][$path] ?? 0) + 1;
            }
        }
        if ($eventType === 'conversion') {
            $properties['conversions']++;
        }
    }

    /** Classify persisted attribution and external referrers for legacy reports. */
    private function classifySource(array $event): string
    {
        $attribution = is_array($event['attribution'] ?? null) ? $event['attribution'] : [];
        $source = trim((string)($attribution['utm_source'] ?? $event['utm_source'] ?? ''));
        $medium = trim((string)($attribution['utm_medium'] ?? $event['utm_medium'] ?? ''));
        if ($source !== '') {
            return $source . ' / ' . ($medium !== '' ? $medium : 'none');
        }

        $referrer = trim((string)($event['referrer'] ?? $event['original_referrer'] ?? ''));
        if ($referrer === '') return 'direct / none';
        $host = strtolower((string)(parse_url($referrer, PHP_URL_HOST) ?? ''));
        $siteHost = strtolower((string)(parse_url((string)($event['url'] ?? ''), PHP_URL_HOST) ?? ''));
        if ($host === '' || ($siteHost !== '' && ($host === $siteHost || str_ends_with($host, '.' . $siteHost)))) {
            return 'direct / none';
        }
        if (preg_match('/(^|\.)((google|bing|yahoo|duckduckgo)\.)/', $host)) return $host . ' / organic';
        if (preg_match('/(^|\.)((facebook|instagram|linkedin|youtube|tiktok|x|twitter)\.)/', $host)) return $host . ' / social';
        return $host . ' / referral';
    }

    /**
     * @param array<string, mixed> $value
     */
    private function atomicWriteJson(string $path, array $value): void
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->atomicWriteText($path, $json . "\n");
    }

    private function atomicWriteText(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create analytics output directory.');
        }

        $temporary = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to atomically write analytics output.');
        }

        @chmod($path, 0640);
    }
}
