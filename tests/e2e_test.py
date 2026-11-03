"""
End-to-end browser test (Playwright + Chrome) for StockWise Inventory.

Run ONLY against a throw-away database loaded with schema.sql, seed.sql and
demo_history.sql — the test creates products, sales, a staff user, etc.

    $env:E2E_BASE="http://localhost:8000"
    $env:E2E_EMAIL="test-admin@example.com"
    $env:E2E_PASSWORD="..."
    python tests/e2e_test.py
"""
import os
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://localhost:8000")
ADMIN_EMAIL = os.environ["E2E_EMAIL"]
ADMIN_PASSWORD = os.environ["E2E_PASSWORD"]
CHROME = os.environ.get("E2E_CHROME")  # optional path to Chrome
SHOTS = os.environ.get("E2E_SHOTS", "e2e-screenshots")
os.makedirs(SHOTS, exist_ok=True)
results = []
console_errors = []

def check(name, cond, detail=""):
    results.append((name, bool(cond), detail))
    print(("PASS " if cond else "FAIL ") + name + (f" — {detail}" if detail and not cond else ""))

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=CHROME) if CHROME else p.chromium.launch()
    ctx = browser.new_context(viewport={"width": 1366, "height": 900}, accept_downloads=True)
    page = ctx.new_page()
    page.on("console", lambda m: m.type == "error" and console_errors.append(f"{page.url}: {m.text}"))
    page.on("pageerror", lambda e: console_errors.append(f"{page.url}: {e}"))

    # --- Login: wrong password, then right ---
    page.goto(f"{BASE}/login.php")
    page.fill("#email", ADMIN_EMAIL); page.fill("#password", "wrong-password-1")
    page.click("#login-form [type=submit]")
    page.wait_for_selector("#login-error:not([hidden])")
    check("login rejects wrong password", "Incorrect" in page.inner_text("#login-error"))
    page.fill("#password", ADMIN_PASSWORD)
    page.click("#login-form [type=submit]")
    page.wait_for_url("**/dashboard.php")
    check("login redirects to dashboard", page.url.endswith("dashboard.php"))

    # --- Dashboard ---
    page.wait_for_selector("#stats .stat-value:not(:has(.spinner))")
    page.wait_for_timeout(600)
    stats = page.inner_text("#stats")
    check("dashboard stats loaded", "₱" in stats and "PRODUCTS" in stats.upper(), stats[:120])
    check("dashboard chart has 14 bars", page.locator("#sales-chart .bar").count() == 14)
    check("dashboard slow movers shown", page.locator("#slow-moving .insight").count() > 0)
    page.click("#period-filter [data-period='30d']")
    page.wait_for_function("document.querySelector('#period-label').innerText.includes('30 days')")
    check("dashboard period switch via AJAX", "30 days" in page.inner_text("#period-label"))
    page.screenshot(path=f"{SHOTS}/01-dashboard.png", full_page=True)

    # --- Products: search, sort, create, validation, edit ---
    page.goto(f"{BASE}/products.php")
    page.wait_for_selector("#product-table tbody tr:not(.loading-row)")
    check("products list loaded", page.locator("#product-table tbody tr").count() == 10)
    page.fill("#f-search", "sardines")
    page.wait_for_function("document.querySelectorAll('#product-table tbody tr').length === 1 && !document.querySelector('#product-table .loading-row')")
    check("live search filters list", "Sardines" in page.inner_text("#product-table tbody"))
    page.fill("#f-search", "")
    page.wait_for_timeout(700)
    page.click("th[data-sort=quantity]"); page.wait_for_timeout(500); page.click("th[data-sort=quantity]"); page.wait_for_timeout(700)
    first = page.locator("#product-table tbody tr").first.inner_text()
    check("sorting by stock desc works", "PRC-002" in first or "BEV-003" in first, first[:60])

    page.click("#add-product")
    page.wait_for_selector("#product-modal:not([hidden])")
    page.click("#product-form [type=submit]")
    check("client validation shows errors", page.locator("#product-form .field-error").count() >= 3)
    page.fill("#p-sku", "snk-050"); page.select_option("#p-category", label="Snacks")
    page.fill("#p-name", "Peanuts 100g"); page.fill("#p-purchase", "15"); page.fill("#p-selling", "25")
    page.fill("#p-quantity", "40"); page.fill("#p-reorder", "10")
    page.click("#product-form [type=submit]")
    page.wait_for_selector(".toast-success")
    check("product created via AJAX", "created" in page.inner_text("#toast-stack").lower())
    page.wait_for_timeout(500)
    # duplicate SKU (server-side check)
    page.click("#add-product"); page.wait_for_selector("#product-modal:not([hidden])")
    page.fill("#p-sku", "SNK-050"); page.select_option("#p-category", label="Snacks")
    page.fill("#p-name", "Dup"); page.fill("#p-purchase", "1"); page.fill("#p-selling", "2"); page.fill("#p-reorder", "1")
    page.click("#product-form [type=submit]")
    page.wait_for_selector("#product-form .field-error")
    check("duplicate SKU error from server shown on field", "already used" in page.inner_text("#product-form"))
    page.click("#product-modal [data-modal-close] >> nth=0")
    page.wait_for_timeout(300)
    page.fill("#f-search", "peanuts"); page.wait_for_timeout(800)
    page.click("#product-table [data-action=view]")
    page.wait_for_selector("#view-body dl")
    check("product details modal shows opening stock", "Opening stock" in page.inner_text("#view-body"))
    page.keyboard.press("Escape"); page.wait_for_timeout(300)
    page.screenshot(path=f"{SHOTS}/02-products.png", full_page=True)

    # --- Inventory: stock in, stock out over limit, adjustment ---
    page.goto(f"{BASE}/inventory.php")
    page.wait_for_selector("#low-stock-table tbody tr:not(.loading-row)")
    check("low-stock table populated", page.locator("#low-stock-table [data-restock]").count() >= 1)
    page.locator("#low-stock-table [data-restock]").first.click()
    sugg = page.input_value("#s-quantity")
    check("restock prefills suggested qty", sugg not in ("", "0"), sugg)
    page.click("#stock-form [type=submit]")
    page.wait_for_selector(".toast-success")
    check("stock in recorded", "Added" in page.inner_text("#toast-stack"))
    page.click(".tab[data-mode=stock_out]")
    page.fill("#product-search", "peanuts"); page.wait_for_selector("#product-results .product-pick")
    page.click("#product-results .product-pick")
    page.fill("#s-quantity", "999"); page.select_option("#s-reason-out", "damaged")
    page.click("#stock-form [type=submit]")
    check("stock out over stock blocked client-side", "Only 40" in page.inner_text("#stock-form"))
    page.fill("#s-quantity", "2")
    page.click("#stock-form [type=submit]")
    page.wait_for_function("document.querySelector('#toast-stack').innerText.includes('Removed')")
    check("stock out recorded", True)
    page.click(".tab[data-mode=adjustment]")
    page.fill("#s-counted", "35")
    check("adjustment preview", "-3" in page.inner_text("#adjust-preview"))
    page.select_option("#s-reason-adj", "physical_count"); page.fill("#s-notes", "Shelf count")
    page.click("#stock-form [type=submit]")
    page.wait_for_function("document.querySelector('#toast-stack').innerText.includes('adjusted')")
    page.wait_for_timeout(800)
    hist = page.inner_text("#movement-table tbody")
    check("movement history refreshed", "Peanuts" in hist and "Adjustment" in hist)
    page.screenshot(path=f"{SHOTS}/03-inventory.png", full_page=True)

    # --- Sales: build cart, checkout, receipt, history, void ---
    page.goto(f"{BASE}/sales.php")
    page.wait_for_selector("#pos-results .product-pick")
    page.fill("#pos-search", "peanuts"); page.wait_for_timeout(600)
    page.click("#pos-results .product-pick"); page.wait_for_timeout(500)
    page.click("#pos-results .product-pick"); page.wait_for_timeout(500)
    page.fill("#pos-search", "cola"); page.wait_for_timeout(600)
    page.locator("#pos-results .product-pick", has_text="Cola 1.5L").click(); page.wait_for_timeout(300)
    page.fill("#discount", "5"); page.fill("#tendered", "200")
    page.locator("#discount").dispatch_event("input")
    total = page.inner_text("#sum-total")
    check("cart total preview (2x25 + 70 - 5 = 115)", "115.00" in total, total)
    page.click("#complete-sale")
    page.wait_for_selector("#sale-modal:not([hidden]) .receipt")
    receipt = page.inner_text("#sale-body")
    check("receipt from server shows total and change", "115.00" in receipt and "85.00" in receipt, receipt[-120:])
    page.screenshot(path=f"{SHOTS}/04-receipt.png")
    page.click("#void-sale")
    page.wait_for_selector("#void-modal:not([hidden])")
    page.fill("#void-reason", "Customer changed mind")
    page.click("#void-form [type=submit]")
    page.wait_for_selector("#sale-body .alert-error")
    check("void shows voided status", "Voided" in page.inner_text("#sale-body"))
    page.locator("#sale-modal .modal-footer [data-modal-close]").click(); page.wait_for_timeout(300)
    page.click(".tab[data-tab=history]")
    page.wait_for_selector("#sales-table tbody tr:not(.loading-row)")
    check("sales history loads", page.locator("#sales-table [data-view]").count() > 0)
    page.select_option("#h-status", "voided"); page.wait_for_timeout(900)
    check("history filter by status", page.locator("#sales-table tbody .badge-success").count() == 0 and page.locator("#sales-table tbody .badge-danger").count() >= 1)
    page.screenshot(path=f"{SHOTS}/05-sales-history.png", full_page=True)

    # --- Reports + CSV ---
    page.goto(f"{BASE}/reports.php")
    page.wait_for_selector("#report-table tbody tr:not(.loading-row)")
    check("sales report rendered with totals", "TOTAL" in page.inner_text("#report-table tfoot"))
    page.select_option("#r-type", "slow_moving")
    page.wait_for_selector("#report-extra .rules-box")
    check("slow-moving report explains rules", "classified" in page.inner_text("#report-extra"))
    page.screenshot(path=f"{SHOTS}/06-reports-slow.png", full_page=True)
    page.select_option("#r-type", "sales")
    page.fill("#r-from", "2026-12-31"); page.fill("#r-to", "2026-01-01")
    page.click("#report-form [type=submit]")
    check("invalid date range rejected", "on or after" in page.inner_text("#report-form"))
    page.click("#quick-range [data-range='30']"); page.wait_for_timeout(800)
    with page.expect_download() as dl:
        page.click("#export-csv")
    path = dl.value.path()
    content = open(path, encoding="utf-8-sig").read()
    check("CSV export downloaded", dl.value.suggested_filename.endswith(".csv") and "Net sales" in content, dl.value.suggested_filename)

    # --- Categories ---
    page.goto(f"{BASE}/categories.php")
    page.wait_for_selector("#category-table tbody tr:not(.loading-row)")
    check("categories listed with counts", page.locator("#category-table tbody tr").count() == 5)
    check("used category delete disabled", page.locator("#category-table [data-action=delete][disabled]").count() == 5)

    # --- Users ---
    page.goto(f"{BASE}/users.php")
    page.wait_for_selector("#user-table tbody tr:not(.loading-row)")
    page.click("#add-user"); page.wait_for_selector("#user-modal:not([hidden])")
    page.fill("#u-name", "Maria Staff"); page.fill("#u-email", "maria@shop.test"); page.select_option("#u-role", "staff"); page.fill("#u-password", "TestStaff123")
    page.click("#user-form [type=submit]")
    page.wait_for_function("document.querySelector('#user-table').innerText.includes('Maria Staff')")
    check("admin creates staff user", True)

    # --- Mobile layout ---
    m = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mp = m.new_page()
    mp.on("pageerror", lambda e: console_errors.append(f"mobile {mp.url}: {e}"))
    mp.goto(f"{BASE}/login.php")
    mp.fill("#email", "maria@shop.test"); mp.fill("#password", "TestStaff123")
    mp.click("#login-form [type=submit]"); mp.wait_for_url("**/dashboard.php")
    mp.wait_for_selector("#stats .stat-value:not(:has(.spinner))"); mp.wait_for_timeout(600)
    overflow = mp.evaluate("document.documentElement.scrollWidth > window.innerWidth + 1")
    check("mobile: no horizontal page scroll on dashboard", not overflow)
    check("mobile: staff does not see Users link", mp.locator(".nav a[href='users.php']").count() == 0)
    mp.screenshot(path=f"{SHOTS}/07-mobile-dashboard.png", full_page=True)
    mp.click("[data-sidebar-toggle]"); mp.wait_for_timeout(300)
    mp.screenshot(path=f"{SHOTS}/08-mobile-menu.png")
    mp.goto(f"{BASE}/products.php"); mp.wait_for_selector("#product-table tbody tr:not(.loading-row)"); mp.wait_for_timeout(300)
    overflow = mp.evaluate("document.documentElement.scrollWidth > window.innerWidth + 1")
    check("mobile: products table stacks without page overflow", not overflow)
    check("mobile: staff sees no Add product button", mp.locator("#add-product").count() == 0)
    mp.screenshot(path=f"{SHOTS}/09-mobile-products.png", full_page=True)
    mp.goto(f"{BASE}/users.php")
    check("staff blocked from users page", "dashboard.php" in mp.url)
    mp.goto(f"{BASE}/sales.php"); mp.wait_for_selector("#pos-results .product-pick"); mp.wait_for_timeout(300)
    overflow = mp.evaluate("document.documentElement.scrollWidth > window.innerWidth + 1")
    check("mobile: POS fits screen", not overflow)
    mp.screenshot(path=f"{SHOTS}/10-mobile-pos.png", full_page=True)

    # --- Logout ---
    page.goto(f"{BASE}/dashboard.php")
    page.click(".logout-btn"); page.wait_for_url("**/login.php*")
    page.goto(f"{BASE}/products.php")
    check("logout ends session", "login.php" in page.url)

    browser.close()

print("\nJS console errors:", len(console_errors))
for e in console_errors[:20]:
    print("  ", e)
passed = sum(1 for r in results if r[1])
print(f"\n{passed}/{len(results)} checks passed")
