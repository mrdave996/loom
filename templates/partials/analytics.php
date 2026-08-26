<?php
$configFile = dirname(__DIR__, 2) . '/config/site.php';
$config = is_file($configFile) ? include $configFile : [];
if (($config['analytics']['enabled'] ?? false) === true || getenv('LOOM_ANALYTICS_ENABLED') === '1'): ?>
<script src="/assets/js/loom-analytics.js?v=20260826" data-endpoint="/analytics/event" data-consent-required="<?= getenv('LOOM_ANALYTICS_CONSENT_REQUIRED') !== '0' ? 'true' : 'false' ?>" defer></script>
<?php endif; ?>
