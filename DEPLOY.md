# Deploying a Loom Site to Production

## Requirements

- PHP 8.2+ with extensions: `mbstring`, `xml`, `gd`
- Apache with `mod_rewrite` enabled
- Composer
- Git

---

## For New Sites

### 1. Create your site repo

```bash
mkdir my-site && cd my-site
git init
composer init --name=you/my-site --type=project
composer require mrdave996/loom
```

### 2. Set up the site structure

```
my-site/
├── public/
│   ├── index.php        ← bootstrap (see README.md for template)
│   ├── .htaccess        ← Apache rewrite rules
│   └── assets/
│       └── css/
│           └── style.css
├── content/
│   └── pages/
│       └── index.md
├── templates/           ← optional: override Loom's defaults
└── cache/
```

### 3. Write content

Add Markdown files to `content/pages/` with YAML front matter. See README.md for the full format.

### 4. Push to your own private repo

```bash
git add -A
git commit -m "Initial site"
git remote add origin git@github.com:you/my-site.git
git push -u origin main
```

Your site repo is private — it contains your content. The Loom framework is a public Composer dependency.

---

## Deploying to a Server

### 1. Clone your site repo

```bash
cd /var/www/my-site
git clone git@github.com:you/my-site.git .
```

### 2. Install dependencies

```bash
composer install --no-dev --optimize-autoloader
```

This pulls Loom into `vendor/mrdave996/loom/`.

### 3. Point your web root to `public/`

```apache
<VirtualHost *:443>
    ServerName mysite.com
    DocumentRoot /var/www/my-site/public

    <Directory /var/www/my-site/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 4. Set directory permissions

```bash
chmod -R 775 cache/
chown -R www-data:www-data cache/
```

The `cache/` directory must be writable by the web server.

### 5. Verify

```bash
php vendor/bin/loom verify
```

---

## Deploying Content Updates

Content lives in your site repo. Push from local, pull on the server:

```bash
# On your machine
git add content/
git commit -m "New blog post"
git push

# On the server
cd /var/www/my-site
git pull
rm -rf cache/*    # clear cache so new content renders
```

No Composer changes needed — content is just files.

---

## Deploying Framework Updates

```bash
# On your machine
composer update mrdave996/loom
git add composer.json composer.lock
git commit -m "Update Loom"
git push

# On the server
cd /var/www/my-site
git pull
composer install --no-dev --optimize-autoloader
rm -rf cache/*
```

---

## Importing from WordPress

Run from your site repo (not the Loom framework repo):

```bash
php vendor/bin/loom import /path/to/export.xml
# or for UpDraftPlus:
php vendor/bin/loom import /path/to/backup.sql
```

This generates `content/`, `templates/`, `public/assets/`, and `src/redirects.php` in your site repo.

---

## Scraping an Existing Site

1. Mirror the site with wget:
   ```bash
   bash vendor/mrdave996/loom/scrape/scrape-site.sh https://example.com 3
   ```
2. Create a config file in `scrape/sites/` (see `vendor/mrdave996/loom/scrape/sites/example.com.php`)
3. Run the scraper:
   ```bash
   php vendor/bin/loom scrape scrape/sites/example.com.php
   ```

---

## Summary

| Action | Command |
|--------|---------|
| New site | `composer require mrdave996/loom` |
| Deploy content | `git pull && rm -rf cache/*` |
| Update framework | `composer update mrdave996/loom` |
| Import WordPress | `php vendor/bin/loom import export.xml` |
| Verify site | `php vendor/bin/loom verify` |
