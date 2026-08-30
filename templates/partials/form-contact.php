<?php
/**
 * Contact form partial (auto-generated from WordPress).
 * @var array $page Parsed front matter
 */
// Recipient is site-specific (config/site.php contact.email), never hardcoded in the engine.
// The config file is optional in the bare engine — without it the form records but emails nowhere.
$configFile = __DIR__ . '/../../config/site.php';
$siteConfig = is_file($configFile) ? include $configFile : [];
$contactEmail = $siteConfig['contact']['email'] ?? '';
$analytics = null;
if (($siteConfig['analytics']['enabled'] ?? false) === true || getenv('LOOM_ANALYTICS_ENABLED') === '1') {
	$analyticsRoot = getenv('LOOM_ANALYTICS_DIR') ?: ($siteConfig['analytics']['directory'] ?? dirname(__DIR__, 2) . '/private/analytics');
	$analytics = new \Loom\Analytics\ServerEventRecorder(new \Loom\Analytics\FileAnalyticsStore($analyticsRoot), true);
}

$spamConfig = $siteConfig['contact']['spam'] ?? [];
$form = new \Loom\FormHandler('loom-form-secret', $contactEmail, null, $spamConfig, $analytics);
$turnstile = is_array($spamConfig['turnstile'] ?? null) ? $spamConfig['turnstile'] : [];
$submitted = false;
$errors = [];
$success = false;
$emailed = false;

// Handle form submission
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($_POST['_csrf'])) {
	$result = $form->process($_POST);
	$submitted = true;
	$errors = $result['errors'];
	$success = $result['success'];
	$emailed = $result['emailed'] ?? false;
}
?>

<section class="contact-form">
	<h2>Contact</h2>

	<?php if ($success): ?>
		<?php if ($emailed): ?>
			<p class="success">Thank you! Your message has been sent to <?= htmlspecialchars($contactEmail) ?>.</p>
		<?php else: ?>
			<p class="success">Thank you! Your message has been recorded.</p>
		<?php endif; ?>
	<?php else: ?>

		<?php if (!empty($errors)): ?>
			<ul class="errors">
				<?php foreach ($errors as $error): ?>
					<li><?= htmlspecialchars($error) ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<form method="post">
			<?= $form->csrfField() ?>

			<div class="form-group">
				<label for="name">Name</label>
				<input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($_POST['name'] ?? '') ?>">
			</div>

			<div class="form-group">
				<label for="email">Email</label>
				<input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? '') ?>">
			</div>

			<div class="form-group">
				<label for="message">Message</label>
				<textarea id="message" name="message" rows="5" required><?php echo htmlspecialchars($_POST['message'] ?? '') ?></textarea>
			</div>

			<?php if (($turnstile['enabled'] ?? false) === true && !empty($turnstile['site_key'])): ?>
				<div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($turnstile['site_key'], ENT_QUOTES, 'UTF-8') ?>"></div>
				<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
			<?php endif; ?>

			<button type="submit" class="btn btn--primary">Send message</button>
		</form>
		<p class="form-note">We&#8217;ll reply to your enquiry during business hours.</p>

	<?php endif; ?>
</section>
