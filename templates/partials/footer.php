<?php
/**
 * Footer partial.
 *
 * Matches WordPress Twenty Twenty-Five footer structure.
 *
 * @var array $page Parsed front matter
 */
$siteName = $page['site_name'] ?? 'Loom';
$footerLinks = $page['footer_links'] ?? [];
?>
<footer class="wp-block-template-part">
	<div class="wp-block-group has-global-padding is-layout-constrained" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--50)">
		<div class="wp-block-group alignwide is-layout-flow">

			<div class="wp-block-group alignfull is-content-justification-space-between is-layout-flex">
				<div class="wp-block-columns is-layout-flex">
					<div class="wp-block-column is-layout-flow" style="flex-basis:100%">
						<h2 class="wp-block-site-title"><a href="/"><?= htmlspecialchars($siteName) ?></a></h2>
					</div>
				</div>

				<?php if (!empty($footerLinks)): ?>
				<div class="wp-block-group is-content-justification-space-between is-layout-flex">
					<?php foreach ($footerLinks as $column): ?>
					<nav class="is-vertical wp-block-navigation is-layout-flex" aria-label="<?= htmlspecialchars($column['label'] ?? '') ?>">
						<ul class="wp-block-navigation__container is-vertical wp-block-navigation">
							<?php foreach ($column['links'] as $link): ?>
							<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="<?= htmlspecialchars($link['url']) ?>"><span class="wp-block-navigation-item__label"><?= htmlspecialchars($link['label']) ?></span></a></li>
							<?php endforeach; ?>
						</ul>
					</nav>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			</div>

			<div style="height:var(--wp--preset--spacing--70)" aria-hidden="true" class="wp-block-spacer"></div>

			<div class="wp-block-group alignfull is-content-justification-space-between is-layout-flex">
				<p class="has-small-font-size wp-block-paragraph"><?= htmlspecialchars($siteName) ?></p>
				<p class="has-small-font-size wp-block-paragraph">Powered by <a href="https://github.com/mrdave996/loom">Loom</a></p>
			</div>

		</div>
	</div>
</footer>
