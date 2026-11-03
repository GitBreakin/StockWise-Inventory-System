# StockWise Inventory — Inventory and Sales Management System

IT305W – Advanced Web Applications group project.

A web-based inventory and sales system for a small store: products, categories, stock in/out,
point-of-sale, sales history, low-stock alerts, slow-moving product recommendations, reports and CSV export.

| Layer | Technology |
|---|---|
| Front end | HTML5, CSS3, JavaScript, **jQuery 3.7.1** (bundled in `assets/vendor`) |
| Requests | **AJAX** with jQuery (`$.ajax`) |
| Data format | **JSON** both ways (`JSON.stringify` → `json_decode`, `json_encode` → `$.ajax dataType:'json'`) |
| Back end | **PHP 8.1+** (plain PHP, no framework) |
| Database | **Supabase PostgreSQL** through **PHP PDO** (`pdo_pgsql`) with prepared statements |

More documentation lives in [`docs/`](docs):

- [Supabase setup guide](docs/SUPABASE_SETUP.md)
- [Database design / ERD](docs/DATABASE.md)
- [API endpoints + JSON examples](docs/API.md)
- [Business rules (low stock, slow moving)](docs/BUSINESS_RULES.md)
- [Test checklist and results](docs/TESTING.md)
- [Rubric compliance matrix](docs/RUBRIC.md)
- [Presentation / demo guide](docs/DEMO_GUIDE.md)

---

## 1. Requirements

- PHP **8.1 or newer** with the **`pdo_pgsql`** extension enabled
  (XAMPP 8.2 on Windows works — see step 3).
- A Supabase project (ours is **INVENTORYSYSTEM**).
- Git.

## 2. Get the code

PowerShell (one command per line):

```powershell
cd C:\xampp\htdocs
git clone https://github.com/GitBreakin/StockWise-Inventory-System.git
cd StockWise-Inventory-System
git status
```

Before working on an existing copy, always check for local changes first:

```powershell
git status
git stash list
git log --branches --not --remotes --oneline
git pull
```

## 3. Enable the PostgreSQL driver in PHP

```powershell
php -v
php -m | Select-String pgsql
```

If `pdo_pgsql` is **not** listed:

1. Run `php --ini` and open the `php.ini` file it shows (XAMPP: `C:\xampp\php\php.ini`).
2. Find these two lines and remove the `;` at the start:
   ```ini
   extension=pdo_pgsql
   extension=pgsql
   ```
3. Save, close the terminal, open a new one and run `php -m | Select-String pgsql` again.
4. If you use XAMPP's Apache, restart Apache in the XAMPP Control Panel.

## 4. Configure the database connection

```powershell
Copy-Item .env.example .env
notepad .env
```

Fill in the values from **Supabase → INVENTORYSYSTEM → Connect → Session pooler**
(full walkthrough: [docs/SUPABASE_SETUP.md](docs/SUPABASE_SETUP.md)).
`.env` is in `.gitignore` — **never commit it** and never paste the password into chat or docs.

Check the connection:

```powershell
php scripts/check_connection.php
```

## 5. Create the database (only once per Supabase project)

> The schema has **already been applied** to INVENTORYSYSTEM (migration `initial_inventory_schema`).
> Skip 5a for that project. Do 5a only for a brand-new, empty project.

5a. Supabase → **SQL Editor** → New query → paste all of `database/schema.sql` → **Run**.

5b. Create the first administrator (there is no public sign-up page):

```powershell
php scripts/create_admin.php
```

5c. *(Optional)* Load the demonstration products: SQL Editor → paste `database/seed.sql` → **Run**.
Every seeded row is tagged `[DEMO]`. No sales are seeded.

5d. *(Optional, classroom demo only)* `database/demo_history.sql` simulates 60 days of past sales
(transaction numbers start with `DEMO-`) so the charts, best-sellers and slow-moving analysis have data
on presentation day. **Do not run it on a database used for real business.** Run it right after 5c.

## 6. Run the app

**Option A — PHP built-in server (simplest):**

```powershell
php -S localhost:8000 router.php
```

Open <http://localhost:8000> and log in with the admin you created.
Always start it with `router.php` — it blocks access to `.env` and the server-side folders.

**Option B — XAMPP Apache:** put the folder in `C:\xampp\htdocs\StockWise-Inventory-System`, start Apache,
open <http://localhost/StockWise-Inventory-System/>. The included `.htaccess` files block `.env`, SQL files and
server-side folders (requires `AllowOverride All`, which is XAMPP's default).

## 7. Roles

| Action | Admin | Staff |
|---|:-:|:-:|
| View dashboard, products, categories, reports | ✔ | ✔ |
| Record sales, stock in, stock out | ✔ | ✔ |
| Add / edit / archive / delete products | ✔ | |
| Add / edit / delete categories | ✔ | |
| Inventory adjustments (physical count) | ✔ | |
| Void sales | ✔ | |
| Manage users | ✔ | |

Every rule is enforced **on the server** (PHP), not only by hiding buttons.

## 8. Project structure

```
StockWise-Inventory-System/
├── index.php, login.php, logout.php          entry pages
├── dashboard.php, products.php, categories.php,
│   inventory.php, sales.php, reports.php, users.php   page shells (data loads via AJAX)
├── router.php                 security router for `php -S`
├── api/                       JSON endpoints (PHP)
│   ├── login.php  dashboard.php  products.php  categories.php
│   └── inventory.php  sales.php  reports.php  users.php
├── config/                    bootstrap (.env, sessions) + PDO connection
├── includes/                  auth, CSRF, validation, JSON helpers, business rules, layout
├── assets/css/style.css       responsive design
├── assets/js/                 app.js (shared jQuery helpers) + one script per page
├── assets/vendor/             jQuery 3.7.1
├── database/                  schema.sql, seed.sql, demo_history.sql
├── scripts/                   create_admin.php, check_connection.php (command line only)
└── docs/                      documentation
```

## 9. Security summary

PDO prepared statements everywhere · `password_hash()` / `password_verify()` · CSRF token on every
POST · session cookie `HttpOnly` + `SameSite=Lax` + `Secure` on HTTPS, ID regenerated at login,
30-minute idle timeout · login lockout after 5 failures (per session) · output escaping (`e()` in PHP, `App.esc()` in JS)
· server-side validation of every field · role checks in every API · prices and totals recalculated on
the server · row locks (`SELECT … FOR UPDATE`) and transactions for every stock change · RLS enabled on
all tables so Supabase's public REST API exposes nothing · `.env`, SQL and server folders blocked from the web.

## License

MIT — see [LICENSE](LICENSE). jQuery is © OpenJS Foundation, MIT license (`assets/vendor/jquery-LICENSE.txt`).
