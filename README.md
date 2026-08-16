# Kirby Blockroll

A [Kirby](https://getkirby.com) block for publishing a blogroll: sites you follow, marked up as [h-cards](https://microformats.org/wiki/h-card) with optional [XFN](https://gmpg.org/xfn/) relationships.

Paste a URL, save the page, and empty fields (name, description, feed, avatar) are filled from the remote site. Every autofilled value stays editable. Entries can be deactivated without deleting them.

Inspired by / adapted from [pfefferle/wordpress-blockroll](https://github.com/pfefferle/wordpress-blockroll) (GPL-2.0-or-later).

## Features (v1)

- Block `blogroll` with a structure of links stored **in the block** (any number of independent blogrolls on different pages)
- URL discovery: feed (`rel=alternate`), name (h-card `p-name` → `og:title` → `<title>`), description (h-card `p-note` → meta description), photo (h-card → favicon)
- Autofill on page save (only empty fields; off by default via `autoEnrich`)
- Panel API: `POST /api/blockroll/discover` with `{ "url": "…" }`
- `active` toggle (default on)
- Frontend snippet: h-card list, optional avatars and XFN labels, sort by name / added / manual
- Frontend CSS (adapted from Upstream `style.scss`), loaded only when the block is present
- Optional local photo proxy (`proxyPhotos`): `GET /blockroll/image?url=…` caches avatars under `site/cache/blockroll-photos`
- **OPML export** for each blogroll page (`?opml`) and a site directory (`/opml`)
- **`<link rel="blogroll">`** discovery in the document head
- No frontend JavaScript

## Not in v1 (planned later)

- OPML import
- Aggregation / visitor-facing sort/paging query params

## Installation

### Manual / Git

```bash
git clone https://github.com/diplix/kirby-blockroll.git site/plugins/blockroll
```

Or download the ZIP from GitHub and extract it to `site/plugins/blockroll`.

### Composer

Until the package is on Packagist:

```bash
composer require diplix/kirby-blockroll:dev-main
```

with a VCS repository entry in your project `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/diplix/kirby-blockroll.git"
    }
  ]
}
```

After Packagist publish, `composer require diplix/kirby-blockroll` is enough.

### Enable the block

Add `blogroll` to your blocks fieldsets, for example:

```yaml
fields:
  text:
    type: blocks
    fieldsets:
      - blogroll
      # …
```

## Usage

1. Add a **Blogroll** block.
2. Add a link row, set **URL**, leave name/description empty if you want autofill.
3. Save the page — discover runs for incomplete rows (when `autoEnrich` is enabled).
4. Edit any field afterwards; subsequent saves will not overwrite filled fields.

### OPML

Each page that contains a blogroll block exposes an OPML 2.0 feed of its **active** links:

```
https://example.com/your-page?opml
```

Outlines use `type="rss"` with `htmlUrl`, optional `xmlUrl` (feed), `text`, and `description`.

In the browser, OPML documents are styled via Upstream’s `opml.xsl` (served at `/blockroll/opml.xsl` and referenced with `<?xml-stylesheet …?>`).

A **directory** OPML lists every listed page that has a blogroll (each entry points at that page’s `?opml`):

```
https://example.com/opml
```

`/?opml` redirects with **301** to `/opml`. Page and directory OPML are file-cached under `site/cache/blockroll/opml/` and invalidated when pages change. Optional: `'diplix.blockroll.opmlMaxAge' => 3600` (browser `Cache-Control`, seconds).

Discovery (same idea as Upstream): pages with a blogroll inject

```html
<link rel="blogroll" type="text/xml" href="…?opml" title="…">
```

into `<head>`. The home page also advertises every other blogroll page. The directory URL `/opml` is **not** advertised via `rel="blogroll"`.

### Panel API

Authenticated Panel users can call:

```http
POST /api/blockroll/discover
Content-Type: application/json

{ "url": "https://example.com" }
```

Response:

```json
{
  "status": "ok",
  "data": {
    "name": "…",
    "description": "…",
    "feedUrl": "…",
    "photo": "…"
  }
}
```

### Options

In `site/config/config.php`:

```php
'diplix.blockroll.discoverTimeout' => 8, // seconds for Remote::get

// Local avatar proxy (default off). When true, <img> points to /blockroll/image?url=…
'diplix.blockroll.proxyPhotos'   => true,
'diplix.blockroll.proxySize'     => 96,      // square px (2× for ~48px CSS)
'diplix.blockroll.proxyTimeout'  => 10,      // fetch timeout (seconds)
'diplix.blockroll.proxyMaxBytes' => 512000,  // reject larger responses
// 'diplix.blockroll.proxyCache' => '/absolute/path/to/cache',

// Autofill empty fields on page save (default off)
'diplix.blockroll.autoEnrich' => false,
```

With `proxyPhotos`, remote avatar URLs are rewritten to a same-origin route that fetches once, center-crops to a square, scales to `proxySize` (default **96×96** for retina), stores the file under the Kirby cache (`blockroll-photos/`), and serves it with a long cache header. Failures redirect to the original URL. Local/`data:` URLs are unchanged. Requires PHP GD; without GD the original image is cached as-is.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

Discovery, XFN and OPML helpers are adapted from [pfefferle/wordpress-blockroll](https://github.com/pfefferle/wordpress-blockroll).
