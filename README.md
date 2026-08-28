# Kirby Blogroll Block

A [Kirby](https://getkirby.com) block for publishing a blogroll: sites you follow, marked up as [h-cards](https://microformats.org/wiki/h-card) with optional [XFN](https://gmpg.org/xfn/) relationships.

Paste a URL and click **Discover** on the row to fill empty fields (name, description, feed, avatar) from the remote site. Every filled value stays editable. Entries can be deactivated without deleting them.

Autofill on **page save** (`autoEnrich`) is **off by default**. It runs synchronous HTTP requests during Panel save and can time out on long lists — treat it as an explicit opt-in.

Inspired by / adapted from [pfefferle/wordpress-blockroll](https://github.com/pfefferle/wordpress-blockroll) (GPL-2.0-or-later).

Formerly published as `diplix/kirby-blockroll` / folder `blockroll`. The GitHub repo and Composer package were renamed to `kirby-blogroll-block`; **default public site URLs** (`/blockroll/image`, `/opml`, `/api/blockroll/discover`, …), Kirby plugin id `diplix/blockroll`, and config keys `diplix.blockroll.*` are unchanged for compatibility. Directory, well-known, and proxy/XSL paths are configurable (see Options).

## Features (v1)

- Block `blogroll` with a structure of links stored **in the block** (any number of independent blogrolls on different pages)
- URL discovery: feed (`rel=alternate`), name (h-card `p-name` → `og:title` → `<title>`), description (h-card `p-note` → meta description), photo (h-card → favicon)
- Autofill on page save (only empty fields; off by default via `autoEnrich`)
- Panel **Discover** button on each link URL (fills empty name / feed / description / photo via `POST /api/blockroll/discover`)
- Panel avatar preview via custom field type `blockroll-photo` (does **not** replace core `k-url-field-preview`)
- Panel API: `POST /api/blockroll/discover` with `{ "url": "…" }`
- `active` toggle (default on)
- Frontend snippet: h-card list, optional avatars and XFN labels, sort by name / added / **last published** / manual
- **Feed activity cache** (`FeedActivity`): SimplePie timestamps per `feedUrl`; call `FeedActivity::refreshAll()` from cron; hook `blockroll.feedActivity:after` for site cache invalidation
- Snippet override: `snippet('blocks/blogroll', ['block' => $block, 'sortBy' => 'published'])`
- Frontend CSS (adapted from Upstream `style.scss`), loaded only when the block is present (disable with `injectCss`)
- Optional local photo proxy (`proxyPhotos`): `GET /{routePrefix}/image?url=…` (route is registered only when the proxy is on) stores avatars under `site/cache/blockroll-photos` (re-fetch at most every `proxyCacheTtl`; `0` = never)
- **OPML export** for each blogroll page (`/your-page.opml`, with `?opml` as a 301 alias) and a site directory (`/opml` + `/.well-known/recommendations.opml` by default; disable or rename via `directoryPath` / `wellKnown`); per block: **Als OPML veröffentlichen** (default on) — off = show on the page only, skip discovery/directory
- **`<link rel="blogroll">`** discovery (HTML + OPML, distinguished by MIME type) and site-wide **XFN profile** link in the document head
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
2. Add a link row and set **URL**.
3. Click **Discover** to fill empty name / feed / description / photo.
4. Edit any field afterwards; Discover (and `autoEnrich`) never overwrite filled fields.

### OPML

Each page that contains a blogroll block with **Als OPML veröffentlichen** enabled (default) exposes an OPML 2.0 feed of its **active** links. The canonical URL is a content-representation path (same idea as Kirby’s `.md`):

```
https://example.com/your-page.opml
```

`https://example.com/your-page?opml` redirects with **301** to that URL. `/?opml` redirects with **301** to the directory URL (default `/opml`); if `directoryPath` is `false`, the home query alias is not registered.

Outlines use `type="rss"` with `htmlUrl`, optional `xmlUrl` (feed), `text`, and `description`.

Blocks with the toggle off are shown on the page only: no `.opml` for that page (unless another block on the same page still publishes OPML), no directory entry, no `rel="blogroll"`, no OPML download link under the list.

In the browser, OPML documents are styled via Upstream’s `opml.xsl` (served at `/{routePrefix}/opml.xsl`, default `/blockroll/opml.xsl`, and referenced with `<?xml-stylesheet …?>`).

A **directory** OPML lists every listed page that has a blogroll (each entry is an OPML 2.0 `type="include"` pointing at that page’s `.opml`):

```
https://example.com/opml
https://example.com/.well-known/recommendations.opml
```

Both URLs return the same document unless you turn one of them off (`directoryPath` / `wellKnown`). The directory `<head>` includes `dateModified` (newest blogroll page), `ownerName`, and `ownerId` (site URL). The image proxy route is registered only when `proxyPhotos` is `true`.

Page and directory OPML are file-cached under `site/cache/blockroll/opml/`. The blogroll page-id index persists and is only updated when a page with a blogroll is created, edited, deleted, or changes status/slug; after such edits the directory OPML is warmed in a deferred shutdown task. Unrelated page saves do not touch the index. Optional: `'diplix.blockroll.opmlMaxAge' => 3600` (browser `Cache-Control`, seconds).

Discovery (same idea as Upstream): pages with a blogroll inject both representations, distinguished by MIME type:

```html
<link rel="blogroll" type="text/html" href="https://example.com/your-page" title="…">
<link rel="blogroll" type="text/xml" href="https://example.com/your-page.opml" title="…">
```

into `<head>`. The home page also advertises every other blogroll page. Every HTML page also gets `<link rel="profile" href="https://gmpg.org/xfn/11">` for XFN. The directory URL `/opml` (and the well-known alias) is **not** advertised via `rel="blogroll"`.

The frontend list is marked up as [XOXO](https://microformats.org/wiki/xoxo) (`class="xoxo blogroll …"`).

#### Static caches and query strings

Kirby’s own page cache ignores `?opml` (requests with query data are not cacheable). **External** caches often do not: Staticache, nginx `fastcgi_cache`, and CDNs typically key on the path only and would serve the HTML page for `/your-page?opml`. That is why `.opml` is canonical. If you previously advertised `?opml`, keep the 301 alias; do not point new `rel="blogroll"` links at the query string.

### Panel API

Authenticated Panel users can call:

```http
POST /api/blockroll/discover
{ "url": "https://example.com" }
```

The blogroll URL field (`blockroll-url`) has a **Discover** button that calls this endpoint and fills empty sibling fields in the structure entry. Avatar URLs use field type `blockroll-photo` so the structure table can show a thumbnail without overriding Kirby’s global URL preview (which the System → Plugins list also uses).

Discover, the public image proxy, and feed-activity fetches share the same public-host SSRF checks.

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

// Plugin CSS in <head> when a blogroll is on the page (default true).
// Set false if you bundle/copy blockroll.css yourself (CSP, asset pipeline).
// 'diplix.blockroll.injectCss' => false,

// Public routes (defaults keep /opml, /.well-known/recommendations.opml, /blockroll/…)
// false or '' disables the directory; a page with that slug is then unshadowed.
// 'diplix.blockroll.directoryPath' => 'opml',
// 'diplix.blockroll.wellKnown'     => true,
// Prefix for /{prefix}/opml.xsl and, when proxyPhotos is on, /{prefix}/image
// 'diplix.blockroll.routePrefix'   => 'blockroll',

// Local avatar proxy (default off). When true, <img> points to /{routePrefix}/image?url=…
'diplix.blockroll.proxyPhotos'   => true,
// Re-fetch cached avatars at most every N seconds (default 28 days). Use 0 = never re-fetch.
// 'diplix.blockroll.proxyCacheTtl' => 2419200,
// 'diplix.blockroll.proxyCacheTtl' => 0,
'diplix.blockroll.proxySize'     => 96,      // square px (2× for ~48px CSS)
'diplix.blockroll.proxyTimeout'  => 10,      // fetch timeout (seconds)
'diplix.blockroll.proxyMaxBytes' => 512000,  // reject larger responses
// 'diplix.blockroll.proxyCache' => '/absolute/path/to/cache',

// Autofill empty fields on page save (default off). Opt-in only: each save may
// hit the network once per incomplete row (timeout × retries) and can exceed
// PHP's max_execution_time on large blogrolls.
'diplix.blockroll.autoEnrich' => false,

// Feed activity (last-published sort): SimplePie timeout when refreshing timestamps
'diplix.blockroll.activityTimeout' => 8,
```

After `FeedActivity::refreshAll()` the plugin triggers `blockroll.feedActivity:after` (`$payload`, `$pages`) so the site can invalidate its own page cache.
With `proxyPhotos`, remote avatar URLs are rewritten to a same-origin route that fetches once, center-crops to a square, scales to `proxySize` (default **96×96** for retina), stores the file under the Kirby cache (`blockroll-photos/`), and serves it locally. Files are re-fetched at most every `proxyCacheTtl` (default **4 weeks**); set `proxyCacheTtl` to **`0`** to never re-fetch. `?refresh` is ignored. If a re-fetch fails, a stale local file is preferred over redirecting. Local/`data:` URLs are unchanged. Requires PHP GD; without GD the original image is cached as-is.

**SSRF hardening:** the proxy accepts only `http`/`https`, rejects `file:`/`gopher:`/credentials-in-URL, blocks localhost and private/reserved IPs (literal and after DNS), refuses encoded IPv4 tricks, follows at most three redirects with the same checks on every hop, and pins DNS via `CURLOPT_RESOLVE` so a later lookup cannot rebind to an internal address.

### Troubleshooting: missing blogroll CSS after update

Kirby serves `blockroll.css` via a symlink under `media/plugins/diplix/blockroll/…`. After renaming or moving the plugin folder (or some updates), that symlink can still point at the old path → **403/404** and unstyled blogrolls. From **0.4.2** the plugin calls `publish()` on render so the symlink is refreshed. If styles are still missing, delete `media/plugins/diplix/blockroll/` and reload a blogroll page (or clear page cache / Staticache for those URLs).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

Discovery, XFN and OPML helpers are adapted from [pfefferle/wordpress-blockroll](https://github.com/pfefferle/wordpress-blockroll).
