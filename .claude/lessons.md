# Lessons learned

Read before starting work. Add a new entry whenever something fails or the user corrects the approach.
Format: **What happened** → **Lesson** → **How to apply**.

## Scraping

### OLX blocks TLS 1.3 connections (2026-09-26)
- **What happened:** Every request to olx.ro (page and `/api/v1/offers/`) returned CloudFront 403, from curl and from PHP. PowerShell's `Invoke-WebRequest` worked.
- **Lesson:** It was not the headers or user-agent. OLX rejects TLS 1.3 connections, and forcing TLS 1.2 returns 200 even without a user-agent.
- **How to apply:** In PHP/Guzzle, cap TLS at 1.2 for OLX requests (`CURLOPT_SSLVERSION => CURL_SSLVERSION_MAX_TLSv1_2` combined with `CURL_SSLVERSION_TLSv1_2`). If OLX starts returning 403 again, test TLS/HTTP versions before assuming the block is header-based.

### Autovit data lives in `__NEXT_DATA__` (2026-09-26)
- **What happened:** The search page embeds its data as JSON, with the Apollo/GraphQL state nested as JSON strings inside it.
- **Lesson:** Parse `__NEXT_DATA__`, then decode the inner strings that contain `advertSearch` (listings) or `OpenForInputFilterState` (filters). Don't parse the HTML.
- **How to apply:** Build the Autovit scraper on that JSON. Filters are URL params such as `search[filter_float_price:to]=10000`.

## Tooling (Windows)

### PHP is not on the Git Bash PATH (2026-09-26)
- **Lesson:** Run `php`, `composer` and `artisan` through PowerShell, not Bash.

### The scratchpad path in Git Bash (2026-09-26)
- **What happened:** `cd` to a `C:/Users/...` path failed in Git Bash, so temporary files were written into the project folder.
- **Lesson:** In Bash, use `$TEMP/claude/...`, and chain commands so a failed `cd` stops them (`cd X && ...`).

### Python prints fail on Romanian characters (2026-09-26)
- **Lesson:** Set `PYTHONIOENCODING=utf-8` when printing scraped text on Windows.
