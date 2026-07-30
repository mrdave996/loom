<?php
/**
 * Navigation partial.
 *
 * Matches WordPress Twenty Twenty-Five header structure:
 * alignfull outer → has-global-padding constrained → alignwide flex row.
 *
 * @var array $page Parsed front matter
 */
$siteName = $page['site_name'] ?? 'My Site';
$links = $page['nav_links'] ?? [];
?>
<div class="wp-block-group alignfull is-layout-flow">
	<div class="wp-block-group has-global-padding is-layout-constrained">
		<div class="wp-block-group alignwide is-content-justification-space-between is-nowrap is-layout-flex" style="padding-top:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30)">
			<p class="wp-block-site-title"><a href="/"><?= htmlspecialchars($siteName) ?></a></p>
			<nav class="wp-block-navigation is-content-justification-right is-layout-flex" aria-label="Main">
				<ul class="wp-block-navigation__container wp-block-navigation">
					<?php foreach ($links as $link): ?>
						<li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content" href="<?= htmlspecialchars($link['url']) ?>"><?= htmlspecialchars($link['label']) ?></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>
		</div>
	</div>
</div>
