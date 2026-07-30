<?php
/**
 * Call-to-action section partial.
 * @var array $page Parsed front matter
 */
$ctaTitle = $page['cta_title'] ?? 'Ready to get started?';
$ctaText = $page['cta_text'] ?? '';
$ctaButton = $page['cta_button'] ?? 'Get Started';
$ctaUrl = $page['cta_url'] ?? '#';
?>
<section class="cta">
	<h2><?= htmlspecialchars($ctaTitle) ?></h2>
	<?php if ($ctaText): ?>
		<p><?= htmlspecialchars($ctaText) ?></p>
	<?php endif; ?>
	<a href="<?= htmlspecialchars($ctaUrl) ?>" class="btn btn-primary"><?= htmlspecialchars($ctaButton) ?></a>
</section>
