# Lessons learned

Read before starting work. Add a new entry whenever something fails or the user corrects the approach.
Format: **What happened** → **Lesson** → **How to apply**.

## Scraping

### OLX's block is about HTTP/2, not TLS version — correcting the 2026-09-26 note (2026-09-28)
- **What happened:** The original note (below, struck through) attributed OLX's CloudFront 403 to
  TLS 1.3. Re-tested directly from PHP while researching the OLX scraper (Chapter 2): raw PHP curl
  with **only** `CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2` set (no HTTP version change) still
  got a 403 "Request blocked" from CloudFront. Raw PHP curl with **only**
  `CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1` set (no TLS pin at all) got 200. A plain Guzzle
  client and Laravel's `Http` facade — used with zero special options, exactly like `AutovitClient`
  — both got 200 immediately, because Guzzle's own default `version` request option is already
  `1.1` (confirmed via Context7/Guzzle docs), so it never sends the HTTP/2 handshake that trips the
  block.
- **Lesson:** The block keys on curl's default HTTP/2 handshake, not the TLS version. The original
  fix (forcing TLS 1.2) probably worked by coincidence — forcing a TLS version on some curl builds
  also affects ALPN/protocol negotiation — not because TLS 1.3 itself was the trigger.
- **How to apply:** Laravel's `Http` facade needs **no special TLS or HTTP-version options** to
  reach OLX — `Http::withUserAgent(...)->get($url)` alone works (verified live, `OlxClient`
  ships this way). Don't add `CURLOPT_SSLVERSION`/TLS-pinning code on the assumption it's required;
  it isn't, and it doesn't even fix the block on its own. If OLX starts blocking again, check the
  HTTP version being negotiated before reaching for TLS options.
- ~~Original note: OLX rejects TLS 1.3 connections, and forcing TLS 1.2 returns 200~~ — see above,
  this was the wrong takeaway from a real observation (something in that test did fix it, just not
  the reason written down).

### OLX silently clamps out-of-range pagination instead of erroring (2026-09-28)
- **What happened:** While researching the OLX scraper, requesting `?page=999` on a search that
  only had a handful of real pages returned HTTP 200 with a full page of results — not a 404, not
  an empty results grid. Comparing ad ids showed it was byte-for-byte the same listings, same
  order, as `?page=1`.
- **Lesson:** OLX doesn't signal "past the last page" the way Autovit does (returning fewer results
  than a full page). It just re-serves an earlier page. A naive "stop when the page looks empty or
  short" loop would never stop — it would keep re-fetching and re-saving the same clamped page for
  every remaining page number up to the configured cap.
- **How to apply:** When paginating OLX, track the previous page's set of ad ids and compare it to
  the current page's. If they're identical, you've been clamped back — stop. A couple of ids
  repeating between genuinely consecutive pages is normal (pinned/promoted ads), so only an
  *identical full set* is the stop signal, not any overlap. `ScrapeOlx` implements this.

### Autovit data lives in `__NEXT_DATA__` (2026-09-26)
- **What happened:** The search page embeds its data as JSON, with the Apollo/GraphQL state nested as JSON strings inside it.
- **Lesson:** Parse `__NEXT_DATA__`, then decode the inner strings that contain `advertSearch` (listings) or `OpenForInputFilterState` (filters). Don't parse the HTML.
- **How to apply:** Build the Autovit scraper on that JSON. Filters are URL params such as `search[filter_float_price:to]=10000`.

## Testing

### `Cache` TTL expiry respects `Carbon::setTestNow()`/`travel()` on the array store (2026-09-29)
- **What happened:** Building `ScraperCircuitBreaker` (Chapter 9), which stores an "open until"
  cache key with a TTL (cooldown period) and needs a test proving `isOpen()` flips back to `false`
  once the cooldown elapses. Checked Laravel's `ArrayStore::get()` source before assuming
  `travel()` would work: it compares the stored expiry against `Carbon::now()`, not PHP's real
  `time()`.
- **Lesson:** The `array` cache store (what `phpunit.xml` sets `CACHE_STORE` to for tests) honors
  Carbon's test clock for TTL expiry. `$this->travel(61)->minutes()` after a `Cache::put($key,
  $value, now()->addMinutes(60))` correctly makes that key expired, no manual timestamp
  bookkeeping needed.
- **How to apply:** For any cache-TTL-based state (circuit breakers, rate limits, cooldowns), write
  the test against real `Cache::put()`/`Cache::has()` with `now()->addMinutes(...)` and move time
  with `travel()`/`Carbon::setTestNow()` rather than mocking the cache or hand-rolling a fake clock
  — it just works, because the array test store checks `Carbon::now()`, not the wall clock.

### A second `Http::fake()` call doesn't override an already-registered URL pattern (2026-09-28, widened)
- **What happened:** A test that ran a command twice (to check "second run updates instead of duplicating") called `Http::fake([...])` again between the two runs, with a changed fixture body for the same URL pattern. The second run still got the *first* fixture's data — confirmed via a throwaway debug test (`dump()`'d both bodies: `first`/`first`, not `first`/`second`).
- **Widened while building Chapter 3 (`PriceHistoryRecorderTest`):** it's not just "already hit" URLs. A `beforeEach()` that registers `Http::fake(['url' => success])` for a pattern, followed by a test body that calls `Http::fake(['url' => failure])` for the *same* pattern — with **zero** requests made in between — still served the first (success) response, not the second. So the rule is simpler and stricter than originally written: once a pattern has been registered by any `Http::fake()` call, a later `Http::fake()` call for that same pattern in the same test doesn't take effect at all, hit or not.
- **Lesson:** Register each URL pattern's fake response exactly once per test. Don't rely on a shared `beforeEach()` fake for a pattern and then try to override it for one specific test — that test won't get the override.
- **How to apply:** For multiple sequential responses from the same URL, use `Http::sequence()->push($body1)->push($body2)` once, upfront. For a test that needs different behavior (e.g. a simulated outage) than the shared `beforeEach()`, don't put that URL's fake in `beforeEach()` at all — register it only inside the tests that need the success case, and let the outage test register its own, once.

### `laravel new --pest` didn't scaffold `tests/Pest.php` (2026-09-28)
- **What happened:** New Pest functional tests (`test('...', fn () => ...)`) failed with `Call to undefined method Tests\Feature\...::artisan()` and `Target class [config] does not exist` — the Laravel app was never being booted for them.
- **Lesson:** `tests/Pest.php` (the file that runs `uses(Tests\TestCase::class)->in('Feature')` to bind functional tests to Laravel's TestCase) never got created by the installer, even with `--pest`. The two example tests it did generate are PHPUnit-class-style, which don't need that binding — so the gap wasn't obvious until the first functional-style test file was added.
- **How to apply:** After scaffolding a fresh Laravel+Pest app, check `tests/Pest.php` exists before writing any `test()`/`it()`-style test. If missing, create it with `uses(Tests\TestCase::class)->in('Feature');`.

### `tests/Pest.php` also needs to cover `Unit`, not just `Feature` (2026-09-28)
- **What happened:** A functional-style test in `tests/Unit/` (`RobotsTxtGuardTest.php`) failed with `A facade root has not been set` — it used `Cache`/`Http` facades, which need the app booted, but `tests/Pest.php` only bound `Tests\TestCase` to `Feature`.
- **Lesson:** "Unit" is a directory name, not a guarantee the test has no framework dependency. Any functional Pest test that touches a facade, `config()`, or the container needs the same TestCase binding as a Feature test.
- **How to apply:** Bind both: `uses(Tests\TestCase::class)->in('Feature', 'Unit');`. Existing plain PHPUnit-class-style tests (like the installer's `Unit/ExampleTest.php`) are unaffected since they declare their own base class explicitly.

## Tooling (Windows)

### PHP is not on the Git Bash PATH (2026-09-26)
- **Lesson:** Run `php`, `composer` and `artisan` through PowerShell, not Bash.

### The scratchpad path in Git Bash (2026-09-26)
- **What happened:** `cd` to a `C:/Users/...` path failed in Git Bash, so temporary files were written into the project folder.
- **Lesson:** In Bash, use `$TEMP/claude/...`, and chain commands so a failed `cd` stops them (`cd X && ...`).

### Python prints fail on Romanian characters (2026-09-26)
- **Lesson:** Set `PYTHONIOENCODING=utf-8` when printing scraped text on Windows.

### Fresh Laravel install on Herd (Windows) ships a broken APP_URL (2026-09-26)
- **What happened:** Right after `laravel new`, any `artisan` command (`package:discover`, later probably anything booting the framework) failed with `Invalid URI: Host is malformed` from `Request.php`.
- **Lesson:** The generated `.env`'s `APP_URL` was `http://localhost:8000:8000` — a duplicated port, not a header/proxy/network issue. Herd's Windows installer environment appears to inject a port that Laravel's own `.env.example` template then appends to again.
- **How to apply:** After every `laravel new` on this machine, check `.env`'s `APP_URL` before running any `artisan` command. Fix to a single port (`http://localhost:8000`) if doubled.
