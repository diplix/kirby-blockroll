# Roadmap

Ideas and deferred work for `kirby-blogroll-block`. Not a commitment to ship order.

## Deferred (needs site / feed integration)

- **`<source:blogroll>` in site RSS/Atom**  
  Upstream advertises each blogroll OPML in the feed channel (`xmlns:source` + `<source:blogroll>`). That lives in the site’s feed snippet (`site/snippets/feed/…`), not in this plugin alone — wire it when touching the feed next.

## Feature ideas (from [issue #1](https://github.com/diplix/kirby-blogroll-block/issues/1))

Inspired by [blogroll.social](https://blogroll.social/) and related notes:

- ~~Sort by last publication date~~ → **done in 0.4.0** (`sortBy: published` + `FeedActivity` cache; site can override per snippet)
- Optional sort via headings / sections
- Expand a row to show the last ~3 headlines (reader + blogroll combined)
- Widget to embed the blogroll anywhere
- Show only the *n* most recently updated blogs
- Mouseover shows a post excerpt
- Always show the domain (as an option)
- Show the blogroll inside a reader (Rivva-style follow)
- Status icons (active / inactive / broken / …)
- Counter (n posts in the last x days)

## Other backlog (README / earlier notes)

- OPML import
- Visitor-facing sort / paging via query params (Upstream has this; no JS)

## Recently done

- **0.4.1:** Photo proxy SSRF hardening (scheme/host/IP checks, safe redirects, DNS pin)
- **0.4.0:** Sort by last feed item (`published`); `FeedActivity` + hook `blockroll.feedActivity:after`
- Directory OPML also at `/.well-known/recommendations.opml`
- Directory `<head>`: `dateModified`, `ownerName`, `ownerId`
- XOXO markup (`class="xoxo blogroll …"`) + site-wide XFN profile link
