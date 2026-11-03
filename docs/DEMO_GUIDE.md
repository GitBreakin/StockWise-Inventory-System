# Presentation / demo guide

Target length: **10–12 minutes**. Split the parts among the group.

## Before the presentation

- [ ] `php scripts/check_connection.php` shows **Connection: OK**.
- [ ] One **admin** and one **staff** account exist (create staff on the Users page).
- [ ] Demo data loaded (`seed.sql`; optionally `demo_history.sql` so charts and slow movers have data).
- [ ] Start the server: `php -S localhost:8000 router.php` (or XAMPP Apache).
- [ ] Open two browser windows: normal window = admin, private/incognito window = staff.
- [ ] Open DevTools → **Network** tab, filter **Fetch/XHR**, to show AJAX + JSON live.
- [ ] Have a backup: screenshots or a screen recording, in case the internet fails.

## How the technologies work together (1 minute, say this first)

> "When the user clicks a button, **jQuery** catches the event and sends an **AJAX** request.
> The data travels as **JSON**. **PHP** receives it, validates everything again on the server, and talks
> to **PostgreSQL on Supabase** through **PDO prepared statements**. PHP answers with JSON, and jQuery
> updates only the part of the page that changed, without reloading."

```
Browser (HTML/CSS)                    Server (PHP)                     Supabase
jQuery event ──$.ajax(JSON)──▶ api/sales.php ──PDO prepared──▶ PostgreSQL
            ◀──JSON response──  json_encode()  ◀── rows ───────
jQuery updates the DOM
```

## Demo script

| Time | Who | Show | Rubric |
|---|---|---|---|
| 0:00 | Member 1 | Login (wrong password first → error message, then success). Mention: password hashing, no public sign-up, CSRF token. | Security |
| 1:00 | Member 1 | **Dashboard**: switch Today → 30 days. In the Network tab, click `dashboard.php?period=30d` → Preview: the JSON. "Every number is a live SQL query." | AJAX, JSON |
| 2:30 | Member 2 | **Products**: live search, sort by Stock, filter Low stock. Add a product with an invalid SKU → field errors; then a duplicate SKU → server error under the field; then a valid one with opening stock. | jQuery, validation |
| 4:00 | Member 2 | View the product → its history shows "Opening stock". Try **Delete** on a product with sales → refused, explain **archive** keeps history. | Database integrity |
| 5:00 | Member 3 | **Sales (POS)**: add 2–3 products, discount, cash tendered → change. Complete sale → receipt. In the Network tab show the request contains only IDs and quantities; prices come from the server. | PHP business logic |
| 6:30 | Member 3 | Try to sell more than is in stock → 409 message, nothing saved. Explain the **transaction + row lock** ("two cashiers can't sell the last item"). | Transactions |
| 7:30 | Member 4 | **Inventory**: low-stock alerts with suggested quantity → click **Restock** (pre-filled) → stock in. The alert disappears; the sidebar badge updates. Show the movement history with before → after. | Integration |
| 8:30 | Member 4 | **Reports**: Sales summary (daily ↔ monthly), Best sellers, **Slow-moving** (read the rule box and one recommendation). **Export CSV** and open it in Excel. | Creativity, reports |
| 10:00 | Member 5 | Switch to the **staff** window: no Users menu, no Add product; open `users.php` directly → redirected. Optional: admin **voids** the earlier sale → stock returns. | Roles |
| 11:00 | Member 5 | Resize to phone width: drawer menu, stacked tables. Wrap up with the architecture sentence. | HTML/CSS |

## Likely panel questions

- **Why archive instead of delete?** Deleting would break sales history; foreign keys forbid it. Archived products keep their reports.
- **What if the browser sends a fake price?** The server ignores it. Prices and totals are read from the database and recalculated in PHP.
- **What if two people buy the last item at once?** `SELECT … FOR UPDATE` locks the row; the second sale waits, sees 0 left and is rejected. We tested this.
- **What stops SQL injection?** Every query uses PDO prepared statements. Sort columns come from a fixed whitelist.
- **How is "slow-moving" decided?** No sales in the last 30 days, or more than 90 days of stock at the current pace, and only after 30 days of sales history. See BUSINESS_RULES.md.
- **Where is the database password?** Only in `.env` on the server. It is not in GitHub and never sent to the browser. Supabase's public REST API is closed by RLS.
- **Is the demo data real?** No. Seeded products are tagged `[DEMO]`, and simulated sales are numbered `DEMO-…`. Real use starts with an empty database.
