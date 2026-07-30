<?php
/**
 * Hero section partial.
 * @var array $page Parsed front matter
 */
$heroTitle = $page['hero_title'] ?? $page['title'] ?? '';
$heroSubtitle = $page['hero_subtitle'] ?? $page['description'] ?? '';
$heroCta = $page['hero_cta'] ?? null;
$heroCtaUrl = $page['hero_cta_url'] ?? '#';
?>
<section class="hero">
	<h1><?= htmlspecialchars($heroTitle) ?></h1>
	<?php if ($heroSubtitle): ?>
		<p class="hero-subtitle"><?= htmlspecialchars($heroSubtitle) ?></p>
	<?php endif; ?>
	<?php if ($heroCta): ?>
		<a href="<?= htmlspecialchars($heroCtaUrl) ?>" class="btn btn-primary"><?= htmlspecialchars($heroCta) ?></a>
	<?php endif; ?>
</section>
