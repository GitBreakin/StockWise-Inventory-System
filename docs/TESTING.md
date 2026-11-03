# Test checklist and actual results

Tests were run on **2026-11-03** while building the app.

**Test environment (what was really used):**

- PHP 8.3.6 CLI with `pdo_pgsql`, PHP built-in web server (single- and multi-worker).
- A **local PostgreSQL 16** database loaded with `schema.sql`, `seed.sql` and, for UI tests,
  `demo_history.sql`.
- **Supabase INVENTORYSYSTEM (PostgreSQL 17)**: schema applied and a constraint smoke test run
  inside a transaction that was rolled back (nothing saved).
- Google Chrome (headless) driven by Playwright for UI tests, at 1366×900 (desktop) and 390×844 (phone).
- `curl` for API tests.

## Required tests (from the project brief)

| # | Test | Result | How it was tested |
|---|---|---|---|
| 1 | PHP starts without syntax errors | ✅ Pass | `php -l` on all PHP files: 0 errors; all pages load (HTTP 200) |
| 2 | PostgreSQL connection succeeds | ✅ Pass (local) · ⚠️ **Not tested with Supabase from PHP** | `scripts/check_connection.php` against local PG. PHP→Supabase needs the DB password, which only the group has. Run `php scripts/check_connection.php` after filling `.env`. |
| 3 | Login and logout work | ✅ Pass | Wrong password rejected; correct login redirects; logout then visiting a page redirects to login |
| 4 | Unauthorized users cannot access protected endpoints | ✅ Pass | No session → 401; staff calling admin actions (create category, adjustment, void) → 403; staff opening `users.php` → redirected |
| 5 | Product CRUD works | ✅ Pass | Create, view, edit, archive, restore, delete (API + UI) |
| 6 | Duplicate SKUs are rejected | ✅ Pass | API → 422 with field error; UI shows it under the SKU field; DB unique index verified on Supabase |
| 7 | Category relationships remain valid | ✅ Pass | Deleting a used category → 409; DB foreign key verified on Supabase; case-insensitive duplicate name rejected |
| 8 | Stock-in and stock-out update quantities correctly | ✅ Pass | e.g. 4 → +20 → 24 → −3 → 21 → adjust to 19; history shows each step |
| 9 | Negative stock is prevented | ✅ Pass | Stock-out of 999 → 409; DB `CHECK (quantity >= 0)` verified on Supabase |
| 10 | Sales totals calculated server-side | ✅ Pass | Sent a fake `unit_price: 0.01` and `total_amount: 1`; server ignored them and charged real prices (subtotal 200.00 − 10.00 = 190.00) |
| 11 | Failed sales roll back all changes | ✅ Pass | (a) 2nd item over stock → nothing saved; (b) **forced database error on the 2nd sale line** (temporary trigger) → stock, sales, items and movements all unchanged |
| 12 | JSON responses have valid syntax | ✅ Pass | All responses parsed with `jq`; malformed JSON request → 400 |
| 13 | AJAX updates the UI without reloads | ✅ Pass | UI test: create product, stock in/out, sale, void, filters all update in place |
| 14 | Invalid requests return appropriate errors | ✅ Pass | 400 / 401 / 403 / 404 / 409 / 419 (no CSRF token) / 422 all observed |
| 15 | Reports match the underlying database | ✅ Pass | Dashboard and sales report totals equal the sum of the sale records; demo-data integrity check: 0 quantity mismatches, 0 audit-chain breaks, 0 sale-total mismatches |
| 16 | Low-stock and slow-moving rules work as documented | ✅ Pass | Alert opens at ≤ reorder level, resolves after restock, never duplicates; slow-moving output checked against the rules in BUSINESS_RULES.md |
| 17 | CSV export produces valid output | ✅ Pass | Correct `Content-Type`/filename, UTF-8 BOM, header row, quoted fields; downloaded via the UI |
| 18 | Mobile layouts remain usable | ✅ Pass (emulated) | 390 px wide: no horizontal scrolling on dashboard, products, POS; menu opens as a drawer. Not tested on a physical phone. |
| 19 | Credentials not exposed in frontend assets | ✅ Pass | No DB settings in any page or JS file; `GET /.env` → 404 through `router.php` |
| 20 | No required feature is a static mockup | ✅ Pass | Every page loads its data from the API; no hard-coded totals or sample arrays in JS |

## Extra tests

| Test | Result |
|---|---|
| Two cashiers buy the **last unit at the same time** (multi-worker server, parallel requests) | ✅ One sale succeeds, the other gets 409; stock ends at 0, never −1 |
| Voiding a sale returns the items to stock; voiding twice → 409 | ✅ |
| Archived product cannot be sold | ✅ 409 |
| Discount larger than subtotal / cash tendered below total | ✅ 422 |
| Login lockout after 5 wrong passwords (60 s) | ✅ 6th attempt blocked, even with the correct password. Note: tracked per browser session, so clearing cookies resets it — a production system would track attempts in the database. |
| Session expires after 30 min idle | Implemented; **not time-tested** |
| Full browser test (`tests/e2e_test.py`) | ✅ **39/39 checks**, passed 4 runs in a row after fixing one bug (see below) |
| Supabase constraint smoke test (negative stock, duplicate SKU, duplicate category, delete used category, wrong movement math, duplicate open alert) | ✅ 6/6 blocked by the database; transaction rolled back, tables still empty |

## Bugs found by testing and fixed

1. `.env` could be downloaded from the PHP dev server (`GET /.env` → 200). **Fixed:** `router.php`
   for `php -S` and `.htaccess` files for Apache now block hidden files, SQL files and server folders.
2. Opening a dialog auto-focused the first field 140 ms later, stealing focus if the user had already
   started typing in another field. **Fixed:** focus only moves if nothing in the dialog has focus.
3. Dates from PostgreSQL (`2026-11-02 17:16:00+08`) were not understood by JavaScript. **Fixed:** `App.parseDate()`.
4. Slow-moving analysis originally counted days since the product was added, so a new system with no
   sales could label everything "slow". **Fixed:** tracking starts when sales recording starts.

## Not tested (needs the group's environment)

- PHP connecting to **Supabase** with the real password (run `php scripts/check_connection.php`).
- **XAMPP Apache** on Windows and the `.htaccess` rules (Apache was not available in the build
  environment). After setup, open `http://localhost/SOLARSYSTEM/.env` — it must show *Forbidden* or *Not Found*.
- A physical phone (only Chrome's phone emulation was used).

## How to re-run the browser test (optional)

Requires Python 3 and Playwright (`pip install playwright` then `playwright install chromium`).
Use a **throw-away database** with demo data and a test admin account:

```powershell
php -S localhost:8000 router.php
$env:E2E_BASE="http://localhost:8000"
$env:E2E_EMAIL="your-test-admin@example.com"
$env:E2E_PASSWORD="your-test-password"
python tests/e2e_test.py
```
