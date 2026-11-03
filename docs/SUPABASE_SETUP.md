# Supabase setup guide

Our project: **INVENTORYSYSTEM** (region `eu-west-1`, PostgreSQL 17).
The schema is **already applied** (migration `initial_inventory_schema`), so for this project you only
need sections 3–6.

## 1. Creating a project (only for a new project)

1. Go to <https://supabase.com/dashboard> and sign in with GitHub.
2. Click **New project** and choose your organization.
3. **Name:** e.g. `INVENTORYSYSTEM`.
4. **Database password:** click *Generate a password*, then save it in a password manager.
   You will put it in `.env` later. It is not shown again (you can reset it).
5. **Region:** choose the one closest to your users. For the Philippines, **Southeast Asia (Singapore)**
   is the fastest. Far regions (e.g. Europe) work but every query is slower.
6. Plan: Free is enough for this project. Click **Create new project** and wait ~2 minutes.

> Free plan limit: 2 active projects per organization.

## 2. Creating the tables (only for a new, empty project)

1. Left menu → **SQL Editor** → **New query**.
2. Open `database/schema.sql`, copy **everything**, paste it, click **Run**.
3. Left menu → **Table Editor**: you should see 7 tables:
   `users, categories, products, sales, sale_items, stock_movements, low_stock_alerts`.

## 3. Getting the connection details

1. Open the project. Click the **Connect** button at the top of the page.
2. Choose **Session pooler** (not "Direct connection").
   - *Why:* the direct host `db.<project-ref>.supabase.co` only works over IPv6, which many home
     ISPs and Windows setups in the Philippines do not have. The Session pooler works over IPv4.
   - Do **not** use the *Transaction pooler* (port 6543); it is meant for serverless apps.
3. Note these values from the connection string or the "View parameters" panel:

| `.env` key | Where it comes from | Example format |
|---|---|---|
| `DB_HOST` | host | `aws-0-eu-west-1.pooler.supabase.com` (copy yours exactly) |
| `DB_PORT` | port | `5432` |
| `DB_NAME` | database | `postgres` |
| `DB_USER` | user | `postgres.<your-project-ref>` |
| `DB_PASSWORD` | the database password from step 1.4 | — |
| `DB_SSLMODE` | always | `require` |

**Forgot the password?** Project Settings → Database → **Reset database password**.
(Everyone in the group then needs the new password in their own `.env`.)

## 4. Configuring PHP

```powershell
Copy-Item .env.example .env
notepad .env
```

Fill in the values above and save. `.env` is ignored by Git. Share the password with groupmates
privately (not in GitHub, not in group chats that get screenshotted).

Optional, stronger SSL: Project Settings → Database → SSL Configuration → **Download certificate**,
save it somewhere outside the project folder, then set:

```ini
DB_SSLMODE=verify-full
DB_SSLROOTCERT=C:/path/to/prod-supabase.cer
```

## 5. Verifying that PHP can connect

```powershell
php scripts/check_connection.php
```

Expected output:

```
PHP version        : 8.x
pdo_pgsql loaded   : yes
Connection         : OK
Server version     : 17.x
Tables in public   : categories, low_stock_alerts, products, sale_items, sales, stock_movements, users
Admin accounts     : 0  (run php scripts/create_admin.php)
```

Common errors:

| Message | Fix |
|---|---|
| `pdo_pgsql loaded : NO` | Enable `extension=pdo_pgsql` in `php.ini` (README step 3). |
| `could not find driver` | Same as above. |
| `could not translate host name` | Check `DB_HOST`; use the **Session pooler** host, not `db.<ref>.supabase.co`. |
| `password authentication failed` | Wrong `DB_PASSWORD`, or `DB_USER` is missing the `.project-ref` part. |
| `Tenant or user not found` | `DB_USER` must be `postgres.<project-ref>` for the pooler. |
| `timeout` | Firewall or school network blocking port 5432; try another network or mobile hotspot. |

## 6. First admin and (optional) demo data

```powershell
php scripts/create_admin.php
```

Then, optionally, in the SQL Editor run `database/seed.sql` (demo products) and, for the classroom
demo only, `database/demo_history.sql` (simulated past sales, numbered `DEMO-…`).

## Security notes

- The app connects **only** through PHP PDO. It never uses the Supabase JavaScript client, anon key or
  service-role key, so no key is ever sent to the browser.
- RLS is enabled on every table **with no policies**. That closes Supabase's automatic REST API
  (`/rest/v1/...`) for the anon/authenticated roles. The Supabase dashboard "RLS enabled, no policy"
  notice is therefore expected and intentional. PHP connects as the database owner and is not affected.
- The Supabase security advisor also warns about `public.rls_auto_enable()`. That function was created
  by Supabase itself, not by this project; review it in the dashboard before changing anything.
