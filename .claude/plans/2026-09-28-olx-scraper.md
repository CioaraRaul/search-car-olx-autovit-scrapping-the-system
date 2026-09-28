# Plan: OLX scraper (Chapter 2)

## Goal
Build `scrape:olx`, the OLX counterpart to `scrape:autovit`: fetch car ads from OLX matching the
saved search criteria, run them through the same reliability hard-gate, and store the survivors in
`listings` — without duplicating anything already stored, and without getting blocked.

## What I found by actually inspecting the live site
(Per `CLAUDE.md` rule 2/lesson-reading — re-verified live today rather than trusting the two-day-old
notes in `lessons.md` and `ROADMAP.md` without checking.)

- **OLX renders plain HTML, no JSON blob.** Confirmed: `__NEXT_DATA__` does not exist on this page
  (unlike Autovit). Each ad is a `<div data-testid="l-card" data-cy="l-card" id="{numeric id}">` —
  52 of them on a fresh search page, every one with a numeric `id` attribute (verified: 0 missing/
  non-numeric across the live page). That `id` is OLX's real internal ad id — used as `external_id`.
- **Confirmed stable selectors** (all `data-testid`/`data-nx-name` attributes, not the emotion-css
  hashed class names, which are unstable build artifacts):
  | Field | Selector (scoped inside one `l-card`) | Notes |
  |---|---|---|
  | external_id | the card's own `id` attribute | numeric string |
  | title + url | `a[data-testid="card-title-link"]` | `href` is relative (`/d/oferta/...`), `aria-label` or child `h4` text is the title |
  | price | `p[data-testid="ad-price"]` → `->innerText()` | direct text only — the element also contains a child `<span>Prețul e negociabil</span>` that `->text()` would wrongly include; `innerText()` (direct-child text nodes only) avoids that |
  | photo | first `img` inside the card | `src` attribute |
  | city | `p[data-testid="location-date"]` text, split on `" - "`, first part | second part is a post date we don't need |
  | year + mileage | the element with `[data-testid="millage-card-param-icon"]` (an `<svg>`) → `->closest('span')->text()` | text is literally `"2015  230 000 km"` — regex `^(\d{4})\s+([\d\s]+?)\s*km$`; verified this exact shape on all 52 cards on the live page, all plain ASCII spaces (not `&nbsp;`) |
  All of this was read from a real, live-fetched search page (today's date), not guessed from memory.
- **No per-listing seller-type/rating is shown on the search results page.** "Firma" / "Persoană
  fizică" only appear as **filter checkbox labels** in the sidebar (`<span class="...">Firma</span>`
  inside the filters form) — not on individual cards. Combined with Chapter 1's finding that Autovit
  only exposes seller *type*, never a rating — **this resolves Chapter 6 per its own pre-written
  rule**: neither site exposes a real seller rating, so Chapter 6 is dropped. I'll delete its
  `ROADMAP.md` entry and note the reason in `CHANGELOG.md` as part of this same change (the rule in
  `ROADMAP.md` explicitly says no question is needed for this).
- **`body_type`/`engine size`/`fuel_type`/`transmission` aren't shown per-card either** — same
  limitation as Autovit. The search already filters server-side by all of these (see query params
  below), so — same as Chapter 1 — **this scraper is already the hard-filtering step** for price/
  year/mileage/engine-size/body-type; there's nothing left to filter after the fact for those
  fields. `body_type`/`transmission`/`fuel_type` stay `null` on OLX-sourced listings too.
- **OLX's `robots.txt`, re-fetched live today:** `Disallow` list is `*/ajax/`, `/adminpanel/`,
  `/api/`, `*/facebook/`, `*/rss/`, `*/account/`, `*/myaccount/`, `/adprint/`, `/payment/`,
  `/searchform/`, `/adding/confirm/`, `/m/ad/abuse/`, `*/landingbundles/`, two `bs=ad_page_chat_*`
  tracking params, `/i2/oferta/contact/*`, `/api/open/oauth/token/`, `/cont/`, `*/i2/*`. **None of
  these match our search path or query params** — unlike Autovit, OLX has no `*_price*`/`*[order]=*`
  restriction, so (confirming the `ROADMAP.md` note) price filtering via the URL is actually allowed
  here. `RobotsTxtGuard::for('https://www.olx.ro')->isAllowed($url)` is still called before every
  request, same as Autovit — this is belt-and-suspenders, not a one-time manual check.
- **Confirmed query params (matches `ROADMAP.md`, re-verified live):**
  `currency=EUR`, `search[filter_float_price:to]`, `search[filter_float_year:from]`,
  `search[filter_float_rulaj_pana:to]`, `search[filter_enum_car_body][0..n]` (values `sedan`/
  `estate-car`, not Autovit's `sedan`/`combi`), `search[filter_float_enginesize:to]` (cc, ×1000 like
  Autovit), `page=N`. With `currency=EUR` set, **every single result on the live page came back
  priced in EUR** (checked all 52) — unlike Autovit, OLX has a real site-wide currency toggle, so
  there's no mixed-currency problem to work around here; the mapper can set `currency` from the
  `price_currency` criterion directly instead of reading it per-listing.
- **Pagination has a gotcha that isn't obvious from a single request:** requesting a page number far
  past the real last page (tested `page=999`) doesn't 404 or return an empty grid — **it silently
  re-serves an earlier page's results** (verified: `page=999` returned the exact same 52 ad ids, in
  the same order, as `page=1`). So Autovit's stopping rule ("stop when a page returns fewer results
  than a full page") doesn't work here — a clamped page still looks "full". The real signal:
  **consecutive real pages share only a couple of ad ids** (2 of 52 overlapped between page 1 and
  page 2 — pinned/promoted ads repeat across pages, that's normal), while a **clamped page repeats
  the entire previous page's id set**. So the stopping rule is: after fetching a page, compare its
  ad-id set to the previous page's; if they're identical, we've been clamped back to an
  already-seen page — stop (that page's rows aren't reprocessed, since they were already saved from
  the page that first returned them). This is a genuinely new finding, not in `lessons.md` yet — see
  below.
- **The TLS-1.2-pinning note in `lessons.md` (2026-09-26) needs a correction, checked today from
  actual PHP code, not PowerShell:**
  - Raw PHP `curl_init()` with no options → OLX returns **403 from CloudFront** ("Request blocked").
  - Raw PHP curl **with `CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2` alone → still 403.** TLS
    version pinning by itself, tested directly today, does not fix it.
  - Raw PHP curl with `CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1` (**no TLS pin at all**) → 200,
    full page. Forcing HTTP/1.1 is what actually matters — CloudFront's bot check here appears to
    key on the HTTP/2 handshake curl does by default, not the TLS version.
  - **A plain Guzzle client, and Laravel's `Http` facade used exactly like `AutovitClient` does
    (`Http::withUserAgent(...)->get($url)`, zero special options) — both tested directly against
    the live OLX URL just now — already return 200.** Guzzle's own default `version` request option
    is `1.1` (confirmed via Context7/Guzzle docs), so it never sends the HTTP/2 handshake that trips
    the block in the first place. **`OlxClient` needs no special TLS/HTTP-version code at all** —
    it can be written exactly like `AutovitClient`. I'll update `lessons.md` to correct the old note
    (the original fix likely worked for an unrelated reason — probably it also happened to avoid
    HTTP/2 — but "TLS 1.2" was the wrong takeaway to write down, and would have led this scraper to
    carry unnecessary curl-option code).

## What I will do

1. **`composer require symfony/dom-crawler symfony/css-selector`** — HTML parsing + CSS-selector
   support (Context7-confirmed current API: `new Crawler($html)`, `->filter('css selector')`,
   `->closest('selector')`, `->innerText()`, `->attr('name')`, `->each(callback)`).
2. **`config/scraping.php`** — add an `'olx'` entry alongside the existing `'autovit'` one:
   `base_url` (`https://www.olx.ro`), `search_path`
   (`/auto-masini-moto-ambarcatiuni/autoturisme/`), `max_pages` (env `SCRAPER_OLX_MAX_PAGES`,
   default 25 — same daily-coverage reasoning as Autovit's cap). Reuses the existing shared
   `user_agent`/`request_delay_ms`/`robots_txt_cache_ttl` keys — no duplication.
3. **`app/Services/Scraping/OlxClient.php`** — mirrors `AutovitClient`'s shape:
   - `fetchPage(array $criteria, int $page): array` builds the URL from criteria (field-name/value
     mapping table above; `BODY_TYPE_MAP = ['sedan' => 'sedan', 'break' => 'estate-car']`), checks
     `RobotsTxtGuard`, fetches via `Http::withUserAgent(...)->get($url)` (no special options, per
     the finding above), parses the body with `Symfony\Component\DomCrawler\Crawler`, and returns
     `['listings' => array<raw node>]` where each raw node is a plain array of the *unparsed*
     strings read off one card (id, title, relative url, raw price text, photo url, raw
     location/date text, raw year/mileage text) — mirroring how `AutovitClient` hands raw
     Autovit nodes to its mapper, so all HTML/DOM-specific code stays in this one class.
   - No `pageSize`/`totalCount` in the return value (OLX's HTML doesn't expose them) — the
     page-clamp detection (below) lives in the command instead, since it needs the *previous*
     page's data to compare against, which isn't something a single `fetchPage()` call can know.
4. **`app/Services/Scraping/OlxListingMapper.php`** — mirrors `AutovitListingMapper`: turns one raw
   node into `Listing` attributes — parses `"6 350 €"` → `6350` (int), builds the absolute URL from
   the relative `href`, splits city from the location/date text, parses the year/mileage text with
   the regex above, sets `currency` from the `price_currency` criterion (passed in, since OLX's
   `currency=EUR` request param makes every result that currency — confirmed live, not assumed),
   `body_type`/`transmission`/`fuel_type` left `null` (documented limitation, same as Autovit).
5. **`app/Console/Commands/ScrapeOlx.php`** (`scrape:olx {--pages=}`) — structured identically to
   `ScrapeAutovit`: loop pages up to the configured max, map + apply the same price-comparability
   guard + the same `ReliabilityScorer` hard gate + `Listing::updateOrCreate` upsert. The one real
   difference is the stop condition: after each page, collect that page's set of `external_id`s; if
   it's **identical** to the previous page's set, OLX has clamped back to an already-processed page
   — stop *before* re-saving those rows (harmless either way since `updateOrCreate` is idempotent,
   but skipping avoids wasted work). Also stops if a page comes back with zero listings at all.
6. **Drop Chapter 6 from `ROADMAP.md`**, per its own pre-written rule (no rating/score found on
   either site's search results) — add the reason to `CHANGELOG.md` instead of leaving it dangling.
7. **Correct the `lessons.md` TLS-1.2 note** for OLX with today's actual finding (HTTP/1.1 is what
   matters, not TLS version; Laravel's `Http` facade already defaults to it, no special code needed)
   — so nobody re-adds unnecessary curl options to `OlxClient` based on the old note.
8. **Tests (Pest)**, fixtures captured from the real page fetched today, trimmed to ~3 cards each
   (same approach as the Autovit fixtures):
   - `OlxListingMapperTest` — maps one real captured card correctly, including the price/year/
     mileage text-parsing edge cases.
   - `ScrapeOlxCommandTest` — stores matching listings, price-filters correctly, doesn't duplicate
     on a second run, rejects a listing that fails the reliability check (reusing the same seeded
     rule approach `ScrapeAutovitCommandTest` uses), and — the OLX-specific case — **a fixture pair
     where the second page is an exact clamp of the first page** confirms the command stops instead
     of looping/re-saving.
   - Small selector-parsing unit coverage for the price/`innerText()` and year-mileage-regex edge
     cases directly, since those are the two genuinely new parsing tricks this chapter introduces.
9. **Branch + changelog** per rules 9–10: `feature/olx-scraper` off `development`, commit, merge
   `--no-ff`, delete branch, push `development`.

## Files
- `composer.json` / `composer.lock` — changed — adds `symfony/dom-crawler`, `symfony/css-selector`
- `config/scraping.php` — changed — adds the `'olx'` sub-array
- `app/Services/Scraping/OlxClient.php` — new
- `app/Services/Scraping/OlxListingMapper.php` — new
- `app/Console/Commands/ScrapeOlx.php` — new
- `tests/Fixtures/olx_search_page.html` — new — real, trimmed ~3-listing capture
- `tests/Fixtures/olx_search_page_price_changed.html` — new — same cards, one price changed
- `tests/Fixtures/olx_search_page_clamped.html` — new — identical id set to the first fixture, to
  test the clamp-detection stop condition
- `tests/Fixtures/olx_search_page_reliability.html` — new — one card matching a seeded bad-engine
  rule, for the reliability-rejection test
- `tests/Unit/OlxListingMapperTest.php` — new
- `tests/Feature/ScrapeOlxCommandTest.php` — new
- `ROADMAP.md` — changed — Chapter 2 entry removed (done), Chapter 6 entry removed (dropped, no
  seller rating exists on either site)
- `CHANGELOG.md` — changed
- `.claude/lessons.md` — changed — corrects the 2026-09-26 OLX TLS note

## Best practices applied
- Small focused classes: fetching/DOM-parsing (`OlxClient`), string→attribute mapping
  (`OlxListingMapper`), orchestration (`ScrapeOlx`) — same separation Chapter 1 already established,
  kept consistent rather than inventing a different shape for OLX.
- Config over hardcoding: base URL, search path, page cap all in `config/scraping.php`, not inline.
- No live-network tests — real fixtures, captured once today, drive the test suite (per
  `best-practices.md`'s scraper-testing note).
- Context7-checked today: Guzzle's default `version` request option (`1.1`) and Symfony DomCrawler's
  current API (`Crawler` constructor, `filter()`, `closest()`, `innerText()` — confirmed against the
  live 7.4 docs, not assumed from memory).

## Things to know / risks
- **Site structure will drift eventually**, same as Autovit — OLX is unofficial, `data-testid`
  attributes could change on a redesign. When it breaks, the fix is re-inspecting a live page the
  same way this plan was researched.
- **The page-clamp behavior is undocumented OLX behavior I found by testing, not a stated API
  contract** — if OLX ever changes it (e.g. starts 404ing instead of clamping), the stop condition
  might need revisiting. The zero-listings fallback check covers a 404/empty-page case either way.
- **`body_type`/`transmission`/`fuel_type` stay `null`** on OLX-sourced listings, same documented
  limitation as Autovit.
- Politeness: same shared delay/User-Agent as Autovit, same `RobotsTxtGuard` check before every
  request. Not a guarantee against future blocking, but respects what's actually published.

## How we'll verify it works
- `php artisan test` — new unit + feature tests pass alongside the existing 39.
- Manually run `php artisan scrape:olx --pages=1` against the **real** site and confirm rows land in
  `listings` with sane values (spot-check a couple against the live page).
- Run it a second time immediately after and confirm no duplicate rows.
- Manually run with a higher `--pages` value against the real site to confirm the clamp-detection
  stop condition actually fires in practice, not just in the fixture test.

## Needs from you
Nothing blocking — query params, selectors, the TLS correction, page cap (25/run), and the Chapter 6
drop are all confirmed live today, per `ROADMAP.md`'s own rule that no question is needed for any of
this.
