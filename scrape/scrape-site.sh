#!/usr/bin/env bash
#
# scrape-site.sh — Mirror a website using wget for Loom scraping.
#
# Usage:
#   bash scrape/scrape-site.sh <url> [options]
#
# Options:
#   --depth=N       Maximum crawl depth (default: 5)
#   --rate-limit=N  Wait N seconds between requests (default: 0.5)
#   --user-agent=UA Custom user agent string
#
# The mirrored site is saved to scrape/site/<hostname>/

set -euo pipefail

# ── Defaults ──────────────────────────────────────────────
DEPTH=5
RATE_LIMIT=0.5
USER_AGENT="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"

# ── Parse arguments ───────────────────────────────────────
URL=""
for arg in "$@"; do
	case "$arg" in
		--depth=*)    DEPTH="${arg#*=}" ;;
		--rate-limit=*) RATE_LIMIT="${arg#*=}" ;;
		--user-agent=*) USER_AGENT="${arg#*=}" ;;
		-*)
			echo "Unknown option: $arg" >&2
			exit 1
			;;
		*)
			if [ -z "$URL" ]; then
				URL="$arg"
			else
				echo "Unexpected argument: $arg" >&2
				exit 1
			fi
			;;
	esac
done

if [ -z "$URL" ]; then
	echo "Usage: bash scrape/scrape-site.sh <url> [--depth=N] [--rate-limit=N]"
	echo ""
	echo "Examples:"
	echo "  bash scrape/scrape-site.sh https://www.example.com"
	echo "  bash scrape/scrape-site.sh https://www.example.com --depth=3"
	echo "  bash scrape/scrape-site.sh https://www.example.com --rate-limit=1"
	exit 1
fi

# ── Extract hostname ──────────────────────────────────────
HOSTNAME=$(echo "$URL" | sed -E 's|^https?://||' | sed -E 's|/.*||' | sed -E 's|:.*||')

# ── Output directory ──────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUTPUT_DIR="${SCRIPT_DIR}/site/${HOSTNAME}"

echo "Loom Site Mirror"
echo "$(printf '─%.0s' {1..50})"
echo ""
echo "  URL:      $URL"
echo "  Host:     $HOSTNAME"
echo "  Output:   $OUTPUT_DIR"
echo "  Depth:    $DEPTH"
echo "  Rate:     ${RATE_LIMIT}s"
echo ""

# ── Create output directory ───────────────────────────────
mkdir -p "$OUTPUT_DIR"

# ── Run wget mirror ───────────────────────────────────────
echo "Downloading..."
echo ""

wget \
	--mirror \
	--convert-links \
	--adjust-extension \
	--page-requisites \
	--no-parent \
	--level="$DEPTH" \
	--wait="$RATE_LIMIT" \
	--user-agent="$USER_AGENT" \
	--directory-prefix="$OUTPUT_DIR" \
	--no-host-directories \
	--reject="*.zip,*.tar,*.gz,*.pdf,*.doc,*.docx,*.xls,*.xlsx,*.ppt,*.pptx,*.mp3,*.mp4,*.avi,*.mov,*.wmv" \
	--exclude-directories="/wp-admin,/wp-includes,/wp-json,/feed,/xmlrpc.php" \
	--timeout=30 \
	--tries=3 \
	--retry-connrefused \
	--no-check-certificate \
	--quiet \
	--show-progress \
	"$URL" 2>&1 || {
		# wget returns non-zero for some non-fatal issues (like 404 on a linked page)
		echo ""
		echo "⚠ wget finished with warnings (some resources may have failed)"
	}

# ── Summary ───────────────────────────────────────────────
echo ""
HTML_COUNT=$(find "$OUTPUT_DIR" -name "*.html" -o -name "*.htm" 2>/dev/null | wc -l | tr -d ' ')
CSS_COUNT=$(find "$OUTPUT_DIR" -name "*.css" 2>/dev/null | wc -l | tr -d ' ')
IMG_COUNT=$(find "$OUTPUT_DIR" \( -name "*.jpg" -o -name "*.jpeg" -o -name "*.png" -o -name "*.gif" -o -name "*.webp" -o -name "*.svg" -o -name "*.ico" -o -name "*.avif" \) 2>/dev/null | wc -l | tr -d ' ')

echo "$(printf '─%.0s' {1..50})"
echo "✓ Mirror complete!"
echo "  HTML files: $HTML_COUNT"
echo "  CSS files:  $CSS_COUNT"
echo "  Images:     $IMG_COUNT"
echo "  Directory:  $OUTPUT_DIR"
echo ""
echo "Next step: php loom scrape scrape/sites/<config>.php"
