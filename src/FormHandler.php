<?php

declare(strict_types=1);

namespace Loom;

class FormHandler
{
	private string $secretKey;
	private string $toEmail;
	private string $fromEmail;

	/**
	 * @param string      $secretKey  CSRF pepper (not strictly required — session token is used).
	 * @param string      $toEmail    Recipient for contact submissions, e.g. config/site.php contact.email (site-specific).
	 * @param string|null $fromEmail  Sender address; defaults to the recipient's domain mailbox if not set.
	 */
	public function __construct(string $secretKey = 'loom-form-secret', string $toEmail = '', ?string $fromEmail = null)
	{
		$this->secretKey = $secretKey;
		$this->toEmail = $toEmail;
		$this->fromEmail = $fromEmail ?? '';

		// CSRF tokens are stored in the session, so a session must be active.
		if (session_status() === PHP_SESSION_NONE) {
			session_start();
		}
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

		// Store submission (flat-file JSON + optional email delivery).
		$stored = $this->storeSubmission($data);

		return [
			'success' => true,
			'errors' => [],
			'data' => $data,
			'emailed' => $stored && $this->toEmail !== '',
		];
	}

	/**
	 * Store form submission — writes a JSON audit copy and delivers by email.
	 *
	 * Email is sent only when a recipient is configured (site-specific via
	 * config/site.php contact.email). A bare Loom install without one still gets
	 * the flat-file record, so nothing is lost or sent to an unknown address.
	 *
	 * @return bool True if the submission was persisted AND emailed (or no email was configured).
	 */
	private function storeSubmission(array $data): bool
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

		// Deliver by email only if a site-specific recipient is configured.
		if ($this->toEmail === '') {
			return true;
		}

		return $this->sendEmail($data);
	}

	/**
	 * Send a contact submission via PHP mail() to the configured recipient.
	 */
	private function sendEmail(array $data): bool
	{
		$to = $this->toEmail;
		$subject = 'Website enquiry from ' . ($data['name'] !== '' ? $data['name'] : 'Simple 1300 Numbers visitor');

		$body = "Name: {$data['name']}\n";
		$body .= "Email: {$data['email']}\n";
		$body .= "\nMessage:\n{$data['message']}\n";

		// If no explicit sender was set, derive one from the recipient's domain so
		// the mail is not rejected as spoofed by SPF/DKIM on cPanel.
		$from = $this->fromEmail;
		if ($from === '' && strpos($to, '@') !== false) {
			$from = 'noreply@' . substr($to, strpos($to, '@') + 1);
		}

		$headers = '';
		if ($from !== '') {
			$headers .= "From: {$from}\r\n";
		}
		$headers .= "Reply-To: {$data['email']}\r\n";
		$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

		return @mail($to, $subject, $body, $headers);
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
