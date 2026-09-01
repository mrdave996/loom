<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Analytics/SignupConversion.php';

use Loom\Analytics\SignupConversion;

function expectSignupConversion(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$correlation = SignupConversion::createCorrelation(
	'ses_1234567890abcdef',
	'vis_1234567890abcdef',
	'tenant_demo'
);

expectSignupConversion(preg_match('/^[A-Za-z0-9_-]{32,80}$/', $correlation['signup_token']) === 1, 'token should be bounded and opaque');
expectSignupConversion($correlation['session_id'] === 'ses_1234567890abcdef', 'session ID should be preserved');
expectSignupConversion($correlation['visitor_id'] === 'vis_1234567890abcdef', 'visitor ID should be preserved');
expectSignupConversion($correlation['tenant'] === 'tenant_demo', 'tenant should be preserved');

$event = SignupConversion::event($correlation, '2026-09-01T12:00:00+00:00');
expectSignupConversion($event['event_type'] === 'signup_completed', 'event should be a completed signup');
expectSignupConversion($event['session_id'] === $correlation['session_id'], 'event should carry the session ID');
expectSignupConversion($event['metadata']['tenant'] === 'tenant_demo', 'event should carry the tenant without PII');

$body = json_encode($event, JSON_THROW_ON_ERROR);
$timestamp = '2026-09-01T12:00:01+00:00';
$signature = SignupConversion::signature($body, $timestamp, 'test-callback-secret');
expectSignupConversion(SignupConversion::verify($body, $timestamp, $signature, 'test-callback-secret'), 'valid signature should verify');
expectSignupConversion(!SignupConversion::verify($body, $timestamp, $signature . 'x', 'test-callback-secret'), 'tampered signature should fail');
expectSignupConversion(!SignupConversion::verify($body, $timestamp, $signature, 'wrong-secret'), 'wrong secret should fail');

echo "signup_conversion_test: PASS\n";