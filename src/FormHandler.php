<?php

declare(strict_types=1);

namespace Loom;

class FormHandler
{
	private string $secretKey;
	private string $toEmail;
	private string $fromEmail;

	/** @var array{enabled: bool, honeypot_name: string, min_time: float, rate_limit: int, rate_window: int, log_spam: bool} */
	private array $spam;

	/**
	 * @param string      $secretKey  CSRF pepper (not strictly required — session token is used).
	 * @param string      $toEmail    Recipient for contact submissions, e.g. config/site.php contact.email (site-specific).
	 * @param string|null $fromEmail  Sender address; defaults to the recipient's domain mailbox if not set.
	 * @param array       $spamConfig Anti-spam overrides. Keys (all optional):
	 *                                - enabled (bool)        : Master switch, default true.
	 *                                - honeypot_name (string): Field name for the honeypot, default '_url'.
	 *                                - min_time (float)      : Minimum seconds between render and submit, default 3.0.
	 *                                - rate_limit (int)      : Max submissions per window (0 = no limit), default 5.
	 *                                - rate_window (int)     : Rate-limit window in seconds, default 3600.
	 *                                - log_spam (bool)       : Write rejected submissions to files/ for analysis, default true.
	 */
	public function __construct(string $secretKey = 'loom-form-secret', string $toEmail = '', ?string $fromEmail = null, array $spamConfig = [])
	{
		$this->secretKey = $secretKey;
		$this->toEmail = $toEmail;
		$this->fromEmail = $fromEmail ?? '';

		$this->spam = array_merge([
			'enabled'        => true,
			'honeypot_name'  => '_url',
			'min_time'       => 3.0,
			'rate_limit'     => 5,
			'rate_window'    => 3600,
			'log_spam'       => true,
		], $spamConfig);

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
	 * @return array{success: bool, errors: string[], data: array, emailed?: bool}
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

		// ── Anti-spam checks ──────────────────────────────────────────────

		$spamAction = $this->checkSpam($postData);

		if ($spamAction === 'reject') {
			return [
				'success' => false,
				'errors' => ['Please wait before submitting the form.'],
				'data' => [],
			];
		}

		if ($spamAction === 'silent_drop') {
			// Honeypot was filled — pretend success so the bot doesn't learn.
			return [
				'success' => true,
				'errors' => [],
				'data' => [],
				'emailed' => false,
			];
		}

		// ── Field extraction ──────────────────────────────────────────────

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
	 * Render a hidden CSRF field (and optional honeypot) for inclusion in forms.
	 */
	public function csrfField(): string
	{
		$token = $this->generateToken();

		// Store the render timestamp for time-based spam checks.
		$_SESSION['csrf_time'] = microtime(true);

		$fields = '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token) . '">';

		if ($this->spam['enabled'] && $this->spam['honeypot_name'] !== '') {
			$fields .= "\n" . $this->honeypotField();
		}

		return $fields;
	}

	// ── Anti-spam helpers ───────────────────────────────────────────────

	/**
	 * Render a honeypot field — invisible to humans, visible to bots.
	 *
	 * Uses CSS positioning (not type="hidden") so automated form-fillers
	 * treat it as a real field and fill it in. A filled honeypot means
	 * the submission is almost certainly spam.
	 */
	private function honeypotField(): string
	{
		$name = htmlspecialchars($this->spam['honeypot_name'], ENT_QUOTES, 'UTF-8');
		return '<div style="position:absolute;left:-9999px;width:0;height:0;overflow:hidden" aria-hidden="true">'
			. '<label for="' . $name . '">Leave this empty</label>'
			. '<input type="text" id="' . $name . '" name="' . $name . '" tabindex="-1" autocomplete="off" value="">'
			. '</div>';
	}

	/**
	 * Run anti-spam checks and return the action to take.
	 *
	 * @return string 'pass' | 'reject' | 'silent_drop'
	 */
	private function checkSpam(array $postData): string
	{
		if (!$this->spam['enabled']) {
			return 'pass';
		}

		// 1. Honeypot: if the invisible field is filled, it's a bot.
		$hpName = $this->spam['honeypot_name'];
		if ($hpName !== '' && !empty($postData[$hpName])) {
			$this->logSpamSubmission($postData, 'honeypot');
			return 'silent_drop';
		}

		// 2. Time-based: if the form was submitted too fast, it's a bot.
		if ($this->spam['min_time'] > 0) {
			$renderTime = (float)($_SESSION['csrf_time'] ?? 0.0);
			$elapsed = microtime(true) - $renderTime;
			if ($elapsed < $this->spam['min_time']) {
				$this->logSpamSubmission($postData, 'too_fast');
				return 'reject';
			}
		}

		// 3. Session rate limiting: track submissions per session window.
		if ($this->spam['rate_limit'] > 0) {
			$now = time();
			$windowStart = (int)($_SESSION['spam_window_start'] ?? $now);
			$count = (int)($_SESSION['spam_count'] ?? 0);

			// Reset window if expired.
			if ($now - $windowStart > $this->spam['rate_window']) {
				$windowStart = $now;
				$count = 0;
			}

			$count++;
			$_SESSION['spam_window_start'] = $windowStart;
			$_SESSION['spam_count'] = $count;

			if ($count > $this->spam['rate_limit']) {
				$this->logSpamSubmission($postData, 'rate_limited');
				return 'reject';
			}
		}

		return 'pass';
	}

	/**
	 * Optionally log a rejected submission for analysis.
	 */
	private function logSpamSubmission(array $postData, string $reason): void
	{
		if (!$this->spam['log_spam']) {
			return;
		}

		$logDir = dirname(__DIR__) . '/content/submissions';
		if (!is_dir($logDir)) {
			mkdir($logDir, 0755, true);
		}

		$timestamp = date('Y-m-d_H-i-s');
		$filename = $logDir . '/spam_' . $timestamp . '_' . bin2hex(random_bytes(4)) . '.json';

		$entry = [
			'timestamp' => date('c'),
			'reason' => $reason,
			'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
			'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255),
			'fields' => [
				'name' => substr($postData['name'] ?? '', 0, 500),
				'email' => substr($postData['email'] ?? '', 0, 500),
				'message' => substr($postData['message'] ?? '', 0, 2000),
			],
		];

		file_put_contents($filename, json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}
}
