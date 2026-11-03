# Rubric compliance matrix

| # | Rubric item | Points | Where it is in the project | What to show |
|---|---|---|---|---|
| 1 | **JSON Implementation** | 20 | `includes/functions.php` → `json_response()` (`json_encode`), `read_json_body()` (`json_decode` + malformed-JSON handling); every file in `api/`; `assets/js/app.js` → `App.api()` (`JSON.stringify` request, `dataType: 'json'` response) | Open DevTools → Network → create a product: the request body and the response are both JSON in the `{success, message, data, errors}` envelope. Show a 422 with field errors. See [API.md](API.md). |
| 2 | **AJAX Implementation** | 20 | `App.api()` in `app.js` (`$.ajax`); every page script: dashboard stats, product list/search/sort/paginate/CRUD, stock in/out/adjust, POS checkout, sales history, void, reports, CSV export (`App.download`, AJAX blob) | Change the dashboard period, search products, complete a sale: no page reloads; loading spinners and toasts. |
| 3 | **PHP Programming & Server-Side Processing** | 20 | `api/*.php`, `includes/inventory.php` (stock rules, alerts, restock suggestions, slow-moving analysis), `includes/validator.php`, `includes/auth.php`; transactions with row locks in sales and inventory | Explain `apply_stock_change()` and the sale transaction; show the server recalculating prices (send a fake price and see it ignored). |
| 4 | **HTML & CSS Implementation** | 10 | Semantic HTML5 pages (`<main>`, `<nav>`, `<aside>`, labels, `aria-*`), `assets/css/style.css` (CSS variables, grid/flex, responsive breakpoints, stacked tables on phones, print styles) | Resize the window / open on a phone: sidebar becomes a drawer, tables become cards. |
| 5 | **jQuery / Advanced Web Operations** (bonus) | 5 | Event delegation on dynamic rows (`$table.on('click', '[data-action]', …)`), modals, `$.Deferred` promises, debounced live search, form serialization, dynamic DOM building, toasts, `.data()` JSON attributes, AJAX file download | Point at `products.js` and `sales.js`; show live search and the cart updating. |
| 6 | **Database / Data Management** | 10 | `database/schema.sql`: 7 tables, PK/FK, `CHECK` constraints, unique and partial indexes, sequence, audit trail, RLS; `seed.sql`; Supabase migration applied | Show the ERD in [DATABASE.md](DATABASE.md); try to oversell → blocked; show the stock-movement history. |
| 7 | **System Functionality & Integration** | 10 | All modules connected: products ↔ inventory ↔ sales ↔ alerts ↔ dashboard ↔ reports; role-based access | Follow the demo script in [DEMO_GUIDE.md](DEMO_GUIDE.md): a sale instantly changes stock, alerts, dashboard and reports. |
| 8 | **Creativity, UX & Interface Design** | 5 | POS with live cart and change computation, restock suggestions with estimated cost, slow-moving recommendations with plain-language advice, sales chart, receipt view/print, empty states | Show the slow-moving analysis and the "Restock" button that pre-fills the suggested quantity. |
| 9 | **Security, Validation & Error Handling** | 5 | Prepared statements, `password_hash`, CSRF tokens, session hardening + idle timeout, login lockout, role checks in every API, XSS escaping (`e()`, `App.esc()`), server-side validation, generic 500 messages, `.env` blocked, RLS | Staff account: try an admin action → 403. Remove the CSRF header → 419. Show validation messages. |

**Total: 100 + 5 bonus.**

## Required features checklist

| Feature (from the brief) | Status |
|---|---|
| Login / logout, hashing, sessions, roles, safe first admin | ✅ (`scripts/create_admin.php`, no public sign-up) |
| Dashboard with real statistics loaded by AJAX | ✅ |
| Product add / view / edit / safe delete / archive / search / filter / sort / paginate | ✅ |
| Category CRUD with safe delete and duplicate prevention | ✅ |
| Stock-in, stock-out, adjustment with reason, history, previous/resulting quantity, filters | ✅ |
| Multi-item sales, server-side totals, stock check, transaction numbers, history, filters, void | ✅ |
| Low-stock alerts with suggested quantity, no duplicates | ✅ |
| Slow-moving analysis with documented rules and recommendations | ✅ |
| Daily / weekly / monthly / custom-range reports, best sellers, valuation, movements, low stock, transaction details, CSV | ✅ |
| `schema.sql`, `seed.sql`, `.env.example`, `.gitignore`, README, Supabase guide, ERD, API docs, tests, rubric matrix, demo guide | ✅ |
