# Kirby Blogroll Block

A [Kirby](https://getkirby.com) block for publishing a blogroll: sites you follow, marked up as [h-cards](https://microformats.org/wiki/h-card) with optional [XFN](https://gmpg.org/xfn/) relationships.

Paste a URL, save the page, and empty fields (name, description, feed, avatar) are filled from the remote site. Every autofilled value stays editable. Entries can be deactivated without deleting them.

Inspired by / adapted from [pfefferle/wordpress-blockroll](https://github.com/pfefferle/wordpress-blockroll) (GPL-2.0-or-later).

Formerly published as `diplix/kirby-blockroll` / folder `blockroll`. The GitHub repo and Composer package were renamed to `kirby-blogroll-block`; **public site URLs** (`/blockroll/image`, `/api/blockroll/discover`, …), Kirby plugin id `diplix/blockroll`, and config keys `diplix.blockroll.*` are unchanged for compatibility.

## Features (v1)

- Block `blogroll` with a structure of links stored **in the block** (any number of independent blogrolls on different pages)
- URL discovery: feed (`rel=alternate`), name (h-card `p-name` → `og:title` → `<title>`), description (h-card `p-note` → meta description), photo (h-card → favicon)
- Autofill on page save (only empty fields; off by default via `autoEnrich`)
- Panel **Discover** button on each link URL (fills empty name / feed / description / photo via `POST /api/blockroll/discover`)
- Panel API: `POST /api/blockroll/discover` with `{ "url": "…" }`
- `active` toggle (default on)
- Frontend snippet: h-card list, optional avatars and XFN labels, sort by name / added / **last published** / manual
- **Feed activity cache** (`FeedActivity`): SimplePie timestamps per `feedUrl`; call `FeedActivity::refreshAll()` from cron; hook `blockroll.feedActivity:after` for site cache invalidation
- Snippet override: `snippet('blocks/blogroll', ['block' => $block, 'sortBy' => 'published'])`
- Frontend CSS (adapted from Upstream `style.scss`), loaded only when the block is present
- Optional local photo proxy (`proxyPhotos`): `GET /blockroll/image?url=…` stores avatars under `site/cache/blockroll-photos` (re-fetch at most every `proxyCacheTtl`; `0` = never)
- **OPML export** for each blogroll page (`?opml`) and a site directory (`/opml` + `/.well-known/recommendations.opml`)
- **`<link rel="blogroll">`** discovery and site-wide **XFN profile** link in the document head
- XOXO list markup (`xoxo blogroll`)
- No frontend JavaScript

## Not in v1 (see [ROADMAP.md](ROADMAP.md))

- OPML import
- Aggregation / visitor-facing sort/paging query params
- `<source:blogroll>` in the site RSS/Atom feed (site feed snippet, not plugin-only)
- Richer blogroll.social-style UX (headlines, widget, status icons, …)

## Installation

### Manual / Git

```bash
git clone https://github.com/diplix/kirby-blogroll-block.git site/plugins/kirby-blogroll-block
```

Or download the ZIP from GitHub and extract it to `site/plugins/kirby-blogroll-block`.

### Composer

Until the package is on Packagist:

```bash
composer require diplix/kirby-blogroll-block:dev-main
```

with a VCS repository entry in your project `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/diplix/kirby-blogroll-block.git"
    }
  ]
}
```

After Packagist publish, `composer require diplix/kirby-blogroll-block` is enough.

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

A **directory** OPML lists every listed page that has a blogroll (each entry is an OPML 2.0 `type="include"` pointing at that page’s `?opml`):

```
https://example.com/opml
https://example.com/.well-known/recommendations.opml
```

Both URLs return the same document. The directory `<head>` includes `dateModified` (newest blogroll page), `ownerName`, and `ownerId` (site URL).

`/?opml` redirects with **301** to `/opml`. Page and directory OPML are file-cached under `site/cache/blockroll/opml/`. The blogroll page-id index persists and is only updated when a page with a blogroll is created, edited, deleted, or changes status/slug; after such edits the directory OPML is warmed in a deferred shutdown task. Unrelated page saves do not touch the index. Optional: `'diplix.blockroll.opmlMaxAge' => 3600` (browser `Cache-Control`, seconds).

Discovery (same idea as Upstream): pages with a blogroll inject

```html
<link rel="blogroll" type="text/xml" href="…?opml" title="…">
```

into `<head>`. The home page also advertises every other blogroll page. Every HTML page also gets `<link rel="profile" href="https://gmpg.org/xfn/11">` for XFN. The directory URL `/opml` (and the well-known alias) is **not** advertised via `rel="blogroll"`.

The frontend list is marked up as [XOXO](https://microformats.org/wiki/xoxo) (`class="xoxo blogroll …"`).

### Panel API

Authenticated Panel users can call:

```http
POST /api/blockroll/discover
{ "url": "https://example.com" }
```

The blogroll URL field (`blockroll-url`) has a **Discover** button that calls this endpoint and fills empty sibling fields in the structure entry.

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

In `site/config/config.php` (plugin id remains `diplix/blockroll`):

```php
'diplix.blockroll.discoverTimeout' => 8, // seconds for Remote::get

// Local avatar proxy (default off). When true, <img> points to /blockroll/image?url=…
'diplix.blockroll.proxyPhotos'   => true,
// Re-fetch cached avatars at most every N seconds (default 28 days). Use 0 = never re-fetch.
// 'diplix.blockroll.proxyCacheTtl' => 2419200,
// 'diplix.blockroll.proxyCacheTtl' => 0,
'diplix.blockroll.proxySize'     => 96,      // square px (2× for ~48px CSS)
'diplix.blockroll.proxyTimeout'  => 10,      // fetch timeout (seconds)
'diplix.blockroll.proxyMaxBytes' => 512000,  // reject larger responses
// 'diplix.blockroll.proxyCache' => '/absolute/path/to/cache',

// Autofill empty fields on page save (default off)
'diplix.blockroll.autoEnrich' => false,

// Feed activity (last-published sort): SimplePie timeout when refreshing timestamps
'diplix.blockroll.activityTimeout' => 8,
```

After `FeedActivity::refreshAll()` the plugin triggers `blockroll.feedActivity:after` (`$payload`, `$pages`) so the site can invalidate its own page cache.
With `proxyPhotos`, remote avatar URLs are rewritten to a same-origin route that fetches once, center-crops to a square, scales to `proxySize` (default **96×96** for retina), stores the file under the Kirby cache (`blockroll-photos/`), and serves it locally. Files are re-fetched at most every `proxyCacheTtl` (default **4 weeks**); set `proxyCacheTtl` to **`0`** to never re-fetch. `?refresh` is ignored. If a re-fetch fails, a stale local file is preferred over redirecting. Local/`data:` URLs are unchanged. Requires PHP GD; without GD the original image is cached as-is.

**SSRF hardening:** the proxy accepts only `http`/`https`, rejects `file:`/`gopher:`/credentials-in-URL, blocks localhost and private/reserved IPs (literal and after DNS), refuses encoded IPv4 tricks, follows at most three redirects with the same checks on every hop, and pins DNS via `CURLOPT_RESOLVE` so a later lookup cannot rebind to an internal address.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

Discovery, XFN and OPML helpers are adapted from [pfefferle/wordpress-blockroll](https://github.com/pfefferle/wordpress-blockroll).
