<?php

declare(strict_types=1);

namespace Loom;

class FormHandler
{
	private string $secretKey;

	public function __construct(string $secretKey = 'loom-form-secret')
	{
		$this->secretKey = $secretKey;
	}

	/**
	 * Generate a CSRF token for a form.
	 */
	public function generateToken(): string
	{
		$token = bin2hex(random_bytes(32));
		$_SESSION['csrf_token'] = $token;
		return $token;
	}

	/**
	 * Validate a submitted CSRF token.
	 */
	public function validateToken(string $token): bool
	{
		if (empty($_SESSION['csrf_token'])) {
			return false;
		}

		return hash_equals($_SESSION['csrf_token'], $token);
	}

	/**
	 * Process a form submission.
	 *
	 * @return array{success: bool, errors: string[], data: array}
	 */
	public function process(array $postData): array
	{
		$errors = [];

		// Validate CSRF
		$token = $postData['_csrf'] ?? '';
		if (!$this->validateToken($token)) {
			return [
				'success' => false,
				'errors' => ['Invalid form submission. Please try again.'],
				'data' => [],
			];
		}

		// Extract and sanitize fields
		$data = [
			'name' => trim($postData['name'] ?? ''),
			'email' => trim($postData['email'] ?? ''),
			'message' => trim($postData['message'] ?? ''),
		];

		// Validate required fields
		if ($data['name'] === '') {
			$errors[] = 'Name is required.';
		}

		if ($data['email'] === '') {
			$errors[] = 'Email is required.';
		} elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
			$errors[] = 'Please enter a valid email address.';
		}

		if ($data['message'] === '') {
			$errors[] = 'Message is required.';
		}

		if (!empty($errors)) {
			return [
				'success' => false,
				'errors' => $errors,
				'data' => $data,
			];
		}

		// Store submission (flat-file, no database)
		$this->storeSubmission($data);

		return [
			'success' => true,
			'errors' => [],
			'data' => $data,
		];
	}

	/**
	 * Store form submission to a flat file.
	 */
	private function storeSubmission(array $data): void
	{
		$submissionsDir = dirname(__DIR__) . '/content/submissions';

		if (!is_dir($submissionsDir)) {
			mkdir($submissionsDir, 0755, true);
		}

		$timestamp = date('Y-m-d_H-i-s');
		$filename = $submissionsDir . '/' . $timestamp . '.json';

		$submission = [
			'timestamp' => date('c'),
			'name' => $data['name'],
			'email' => $data['email'],
			'message' => $data['message'],
		];

		file_put_contents($filename, json_encode($submission, JSON_PRETTY_PRINT));
	}

	/**
	 * Render a hidden CSRF field for inclusion in forms.
	 */
	public function csrfField(): string
	{
		$token = $this->generateToken();
		return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token) . '">';
	}
}
