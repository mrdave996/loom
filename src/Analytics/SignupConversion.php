<?php

declare(strict_types=1);

namespace Loom\Analytics;

use InvalidArgumentException;

/**
 * Shared contract for trusted signup-completion callbacks.
 *
 * The callback sender must sign the exact JSON body that it sends. Secrets are
 * intentionally not part of Loom site configuration or browser payloads.
 */
final class SignupConversion
{
	/**
	 * @return array{signup_token: string, session_id: string, visitor_id: string, tenant: string}
	 */
	public static function createCorrelation(string $sessionId, string $visitorId, string $tenant): array
	{
		self::identifier($sessionId, 'session ID');
		self::identifier($visitorId, 'visitor ID');
		self::tenant($tenant);

		return [
			'signup_token' => self::token(),
			'session_id' => $sessionId,
			'visitor_id' => $visitorId,
			'tenant' => $tenant,
		];
	}

	/**
	 * @param array{signup_token: string, session_id: string, visitor_id: string, tenant: string} $correlation
	 * @return array<string, mixed>
	 */
	public static function event(array $correlation, ?string $occurredAt = null): array
	{
		foreach (['signup_token', 'session_id', 'visitor_id', 'tenant'] as $key) {
			if (!isset($correlation[$key]) || !is_string($correlation[$key])) {
				throw new InvalidArgumentException('Incomplete signup correlation.');
			}
		}
		self::identifier($correlation['signup_token'], 'signup token');
		self::identifier($correlation['session_id'], 'session ID');
		self::identifier($correlation['visitor_id'], 'visitor ID');
		self::tenant($correlation['tenant']);

		$occurredAt ??= gmdate('c');
		if (strtotime($occurredAt) === false) {
			throw new InvalidArgumentException('Invalid signup timestamp.');
		}

		return [
			'event_id' => 'evt_' . bin2hex(random_bytes(16)),
			'event_type' => 'signup_completed',
			'occurred_at' => $occurredAt,
			'visitor_id' => $correlation['visitor_id'],
			'session_id' => $correlation['session_id'],
			'metadata' => [
				'tenant' => $correlation['tenant'],
				'signup_token_hash' => hash('sha256', $correlation['signup_token']),
			],
		];
	}

	public static function signature(string $body, string $timestamp, string $secret): string
	{
		if ($secret === '' || $timestamp === '') {
			throw new InvalidArgumentException('Callback signing requires a timestamp and secret.');
		}

		return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
	}

	/** @return list<string> */
	public static function headers(string $body, string $timestamp, string $secret): array
	{
		return [
			'Content-Type: application/json',
			'X-Loom-Timestamp: ' . $timestamp,
			'X-Loom-Signature: ' . self::signature($body, $timestamp, $secret),
		];
	}

	public static function verify(string $body, string $timestamp, string $signature, string $secret): bool
	{
		if ($secret === '' || !str_starts_with($signature, 'sha256=')) {
			return false;
		}

		return hash_equals(self::signature($body, $timestamp, $secret), $signature);
	}

	public static function timestampIsFresh(string $timestamp, int $now, int $maxAge = 300): bool
	{
		$parsed = strtotime($timestamp);
		return $parsed !== false && $maxAge >= 0 && abs($now - $parsed) <= $maxAge;
	}

	private static function token(): string
	{
		return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	}

	private static function identifier(string $value, string $label): void
	{
		if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/', $value)) {
			throw new InvalidArgumentException('Invalid analytics ' . $label . '.');
		}
	}

	private static function tenant(string $value): void
	{
		if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,62}$/', $value)) {
			throw new InvalidArgumentException('Invalid analytics tenant.');
		}
	}
}