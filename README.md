# Loom

**Weave content into websites.**

A lightweight, Git-native publishing engine that renders Markdown and reusable components into fast, cacheable websites using plain PHP.

Loom is not a CMS. There's no admin panel, no database, no build step. Just content.

---

## Quick Start

```bash
git clone https://github.com/mrdave996/loom.git my-site
cd my-site
composer install
```

Create a page:

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

Import an entire WordPress site — pages, posts, media, styles, navigation, and forms — in three commands:

```bash
# 1. Clone Loom
git clone https://github.com/mrdave996/loom.git my-site && cd my-site

# 2. Install dependencies
composer install

# 3. Import your WordPress export
php loom import /path/to/export.xml
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
php loom import /path/to/backup.sql
```

After import, preview your site:

```bash
php -S localhost:8080 -t public/
```

Verify everything looks good:

```bash
php loom verify
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

## Directory Structure

```
/
├── public/                    # Document root
│   ├── index.php              # Single entry point
│   ├── .htaccess              # URL rewrites
│   └── assets/                # CSS, JS, images
├── content/                   # Your content
│   ├── pages/                 # Markdown pages
│   └── posts/                 # Blog posts
├── templates/                 # PHP templates
│   ├── layouts/               # Page layouts
│   └── partials/              # Reusable components
├── src/                       # Core runtime
├── cache/                     # Generated HTML (gitignored)
└── loom                       # CLI tool
```

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

### Templates

| Template | Use case |
|----------|----------|
| `default` | Simple page with body content |
| `pillar` | Long-form page with component slots |

### Components

Include reusable partials by listing them in `components`:

- `hero` — Hero section with title, subtitle, and CTA
- `features` — Grid of feature cards
- `pricing` — Pricing table
- `faq` — Accordion FAQ section
- `cta` — Call-to-action block

---

## CLI Commands

```bash
# Verify content integrity
php loom verify

# Import WordPress site
php loom import export.xml
php loom import export.sql
php loom import export.sql --format=sql --output=./mysite
```

---

## Deployment

Loom generates static HTML on first request. Deploy to any PHP host:

1. Upload all files (excluding `/vendor/` if you prefer)
2. Run `composer install --no-dev` on the server
3. Point your web server to `/public/`

For zero-downtime deploys, use Git:

```bash
git pull origin main
composer install --no-dev
php loom verify
```

---

## Performance

- **On-demand caching** — First request renders and caches; subsequent requests serve static HTML
- **WebP images** — All images converted to WebP during import
- **Zero database** — No queries, no connection overhead
- **Minimal runtime** — Under 600 lines of core PHP

---

## Contributing

Loom is open source. Contributions welcome.

1. Fork the repo
2. Create a feature branch
3. Make your changes
4. Run `php loom verify`
5. Submit a pull request

---

## License

MIT

---

## Credits

Built with:
- [league/commonmark](https://commonmark.thephpleague.com/) — Markdown parsing
- [intervention/image](http://image.intervention.io/) — Image processing
- [league/html-to-markdown](https://github.com/thephpleague/html-to-markdown) — HTML conversion
