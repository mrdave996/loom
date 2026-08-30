<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/FormHandler.php';

session_save_path(sys_get_temp_dir());
session_id('loom-form-smoke-' . bin2hex(random_bytes(5)));
session_start();
$_SERVER['REMOTE_ADDR'] = '198.51.100.42';
$secret = 'smoke-secret-' . bin2hex(random_bytes(4));
$verifier = static fn (string $token, string $configuredSecret, string $ip): bool => $token === 'valid-token' && $configuredSecret === 'turnstile-secret' && $ip === '198.51.100.42';
$config = [
	'enabled' => true,
	'min_time' => 0,
	'rate_limit' => 0,
	'ip_rate_limit' => 2,
	'ip_rate_window' => 3600,
	'turnstile' => ['enabled' => true, 'secret_key' => 'turnstile-secret'],
];
$form = new Loom\FormHandler($secret, '', null, $config, null, $verifier);
$token = $form->generateToken();
$_SESSION['csrf_time'] = microtime(true) - 10;
$base = ['_csrf' => $token, 'cf-turnstile-response' => 'valid-token', 'name' => 'Test User', 'email' => 'test@example.test', 'message' => 'A genuine test message.'];

$accepted = $form->process($base);
if (!$accepted['success']) throw new RuntimeException('Expected valid submission to pass.');

$missingTurnstile = $form->process(array_merge($base, ['cf-turnstile-response' => '']))
;
if ($missingTurnstile['success']) throw new RuntimeException('Expected missing Turnstile token to fail.');

$form->generateToken();
$_SESSION['csrf_time'] = microtime(true) - 10;
$second = $form->process(array_merge($base, ['_csrf' => $_SESSION['csrf_token']]));
if (!$second['success']) throw new RuntimeException('Expected second submission to pass.');

$form->generateToken();
$_SESSION['csrf_time'] = microtime(true) - 10;
$third = $form->process(array_merge($base, ['_csrf' => $_SESSION['csrf_token']]));
if ($third['success'] || $third['errors'][0] !== 'Please wait before submitting the form.') {
	throw new RuntimeException('Expected IP rate limit to reject the third submission.');
}

$rateFile = dirname(__DIR__) . '/private/form-rate-limits/' . hash_hmac('sha256', $_SERVER['REMOTE_ADDR'], $secret) . '.json';
if (is_file($rateFile)) unlink($rateFile);
session_destroy();
echo "loom_form_spam_smoke: PASS\n";
