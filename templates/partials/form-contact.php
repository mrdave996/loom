<?php
/**
 * Contact form partial (auto-generated from WordPress).
 * @var array $page Parsed front matter
 */
$form = new \Loom\FormHandler();
$submitted = false;
$errors = [];
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['_csrf'])) {
	$result = $form->process($_POST);
	$submitted = true;
	$errors = $result['errors'];
	$success = $result['success'];
}
?>

<section class="contact-form">
	<h2>Contact</h2>

	<?php if ($success): ?>
		<p class="success">Thank you! Your message has been sent.</p>
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

			<button type="submit" class="btn btn-primary">Send</button>
		</form>

	<?php endif; ?>
</section>
