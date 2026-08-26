# Loom

**Weave content into websites.**

A lightweight, Git-native publishing engine that renders Markdown and reusable components into fast, cacheable websites using plain PHP.

Loom is not a CMS. There's no admin panel, no database, no build step. Just content.

---

## Quick Start

Create a new site that uses Loom as its engine:

```bash
mkdir my-site && cd my-site
git init
composer init --name=you/my-site --type=project
composer require mrdave996/loom
```

Create the site structure:

```
my-site/
├── public/
│   ├── index.php          ← bootstrap (see below)
│   ├── .htaccess
│   └── assets/
│       └── css/
│           └── style.css
├── content/
│   └── pages/
│       └── index.md       ← your first page
├── templates/             ← optional overrides
└── cache/
```

**public/index.php** — the only file you write:

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

$rootDir = dirname(__DIR__);
$loomDir = __DIR__ . '/../vendor/mrdave996/loom';

// Merge: site templates override Loom defaults
$templatesDir = is_dir($rootDir . '/templates')
    ? $rootDir . '/templates'
    : $loomDir . '/templates';

// Boot the engine
$router     = new Loom\Router($rootDir);
$content    = new Loom\ContentLoader();
$renderer   = new Loom\Renderer($rootDir, $templatesDir);
$cache      = new Loom\CacheManager($rootDir);
$seo        = new Loom\SeoGenerator($rootDir);

// Handle sitemap/robots
if ($seo->handle()) exit;

// Route
$pageFile = $router->resolve($_SERVER['REQUEST_URI']);
if (!$pageFile) { http_response_code(404); /* handle 404 */ exit; }

// Cache check
$cached = $cache->get($pageFile);
if ($cached) { echo $cached; exit; }

// Render
$page = $content->load($pageFile);
$html = $renderer->render($page);
$cache->set($pageFile, $html);
echo $html;
```

Create your first page (`content/pages/index.md`):

```yaml
---
title: "My First Page"
description: "Hello from Loom."
template: default
---

## Hello World

Your content goes here.
```

Preview locally:

```bash
php -S localhost:8080 -t public/
```

Open `http://localhost:8080` in your browser.

---

## Migrate from WordPress

Import an entire WordPress site — pages, posts, media, styles, navigation, and forms:

```bash
# In your site repo (not the Loom repo)
composer require mrdave996/loom
php vendor/bin/loom import /path/to/export.xml
```

That's it. Loom will:
- Convert all pages and posts to Markdown with YAML front matter
- Download and convert images to WebP for fast loading
- Extract theme CSS and build a Loom-compatible stylesheet
- Generate navigation from your WordPress menus
- Convert contact forms to Loom's built-in FormHandler
- Create 301 redirect rules for any changed URLs

**Using UpDraftPlus?** Point the import at your SQL dump:

```bash
php vendor/bin/loom import /path/to/backup.sql
```

After import, preview your site:

```bash
php -S localhost:8080 -t public/
```

Verify everything looks good:

```bash
php vendor/bin/loom verify
```

---

## How It Works

```
[ Request ]
    │
    ▼
[ Router ] → resolve URI to markdown file
    │
    ▼
[ Cache Check ] → serve cached HTML if valid
    │
    ▼ (cache miss)
[ ContentLoader ] → parse YAML front matter + Markdown
    │
    ▼
[ Renderer ] → load PHP template + inject partials
    │
    ▼
[ CacheManager ] → save rendered HTML
    │
    ▼
[ Output ]
```

The entire core runtime is under 600 lines of PHP across 6 files. Small enough for an AI to hold in a single context window.

---

## Site Structure

Your site repo (not the Loom framework repo):

```
my-site/
├── composer.json            # requires mrdave996/loom
├── public/
│   ├── index.php            # bootstrap (thin — loads engine from vendor)
│   ├── .htaccess            # URL rewrites
│   └── assets/
│       └── css/style.css    # your site's styles
├── content/
│   ├── pages/               # Markdown pages
│   └── posts/               # blog posts (optional)
├── templates/               # optional: override Loom's defaults
│   ├── layouts/             # page layouts
│   └── partials/            # reusable components
└── cache/                   # generated HTML (gitignored)
```

The framework lives in `vendor/mrdave996/loom/` — you never edit it directly.

---

## Templates

Loom ships with default templates and partials. Override any of them by placing a file with the same name in your site's `templates/` directory.

### Layouts

| Template | Use case |
|----------|----------|
| `default` | Simple page with body content |
| `pillar` | Long-form page with component slots |
| `blog` | Blog listing from `content/posts/` |
| `raw` | Minimal — no nav, no footer, just content |

### Components

Include reusable partials by listing them in your page's front matter `components` array:

- `hero` — Hero section with title, subtitle, and CTA
- `features` — Grid of feature cards
- `pricing` — Pricing table
- `faq` — Accordion FAQ section
- `cta` — Call-to-action block

---

## Content Format

Every page is a Markdown file with YAML front matter:

```yaml
---
title: "Page Title"
description: "SEO description."
template: pillar
components:
  - hero
  - features
  - faq
  - cta
hero_title: "Welcome"
hero_subtitle: "Build something great."
features:
  - title: "Fast"
    description: "Single-digit millisecond TTFB."
  - title: "Simple"
    description: "Zero framework, zero database."
---

## Your content here

Markdown body rendered as HTML.
```

---

## CLI Commands

Run from your site repo root:

```bash
# Verify content integrity
php vendor/bin/loom verify

# Import WordPress site
php vendor/bin/loom import export.xml
php vendor/bin/loom import export.sql

# Scrape a mirrored site
php vendor/bin/loom scrape scrape/sites/example.com.php
```

---

## Updating Loom

```bash
composer update mrdave996/loom
```

Your content and templates are in your own repo — updating the framework never touches them.

---

## Performance

- **On-demand caching** — First request renders and caches; subsequent requests serve static HTML
- **WebP images** — All images converted to WebP during import
- **Zero database** — No queries, no connection overhead
- **Minimal runtime** — Under 600 lines of core PHP

## First-party analytics

Analytics is optional and remains flat-file, privacy-aware, and database-free. Enable it with
`LOOM_ANALYTICS_ENABLED=1` (and leave `LOOM_ANALYTICS_CONSENT_REQUIRED=1` unless the site has
an explicitly reviewed first-party measurement policy). The shared tracker records anonymous
session journeys, persisted UTM/referrer attribution, bounded search-referrer keywords when a
search engine supplies `q`, `query`, or `text`, CTA/form events, and one bounded `page_exit` event
per page view. Page-exit metadata contains elapsed and visible/active seconds; reports must show
coverage and must not infer time from gaps between arbitrary events.

Known AI assistant referrers such as ChatGPT, Claude, Perplexity, Gemini, Copilot, Grok, DeepSeek,
You.com, Phind, and Poe can be classified by the separate reporting application when a human click
arrives with a usable referrer. Copied or referrer-stripped AI links remain direct/unattributed;
tagged UTM links are the reliable fallback. Do not put names, email addresses, message bodies, or
raw IP addresses in analytics metadata.

---

## License

MIT

---

## Credits

Built with:
- [league/commonmark](https://commonmark.thephpleague.com/) — Markdown parsing
- [intervention/image](http://image.intervention.io/) — Image processing
- [league/html-to-markdown](https://github.com/thephpleague/html-to-markdown) — HTML conversion
