# API endpoints and JSON examples

All endpoints live in `api/` and are called by jQuery `$.ajax`. Every response uses the same JSON envelope:

```json
{ "success": true, "message": "Human-readable text", "data": { }, "errors": [] }
```

- `Content-Type: application/json; charset=utf-8` on every response (except CSV downloads).
- Reads use **GET** with query-string parameters.
- Writes use **POST** with a **JSON body** (`Content-Type: application/json`) that contains an `action`.
- Every POST must send the CSRF token in the `X-CSRF-Token` header (`app.js` does this automatically).
- Login is required for everything except `api/login.php`.

The examples below are **real responses** captured from the running app during testing.

## HTTP status codes

| Code | Meaning in this app |
|---|---|
| 200 / 201 | OK / created |
| 400 | Malformed JSON, missing or unknown `action` |
| 401 | Not logged in, session expired, or wrong login |
| 403 | Logged in but your role is not allowed (e.g. staff trying to void a sale) |
| 404 | Record not found |
| 405 | Wrong HTTP method |
| 409 | Business-rule conflict (not enough stock, category in use, already voided…) |
| 413 / 415 | Body too large / not sent as JSON |
| 419 | Missing or invalid CSRF token |
| 422 | Validation failed; `errors` maps each field to a message |
| 500 | Server or database error (generic message only; details go to the server log) |

## Endpoint list

| Method | Endpoint | Role | Purpose |
|---|---|---|---|
| POST | `api/login.php` | public | Log in `{email, password}` |
| GET | `api/dashboard.php?period=today\|week\|month\|30d` | any | All dashboard figures |
| GET | `api/products.php?search=&category_id=&stock=all\|in_stock\|low\|out&status=active\|archived\|all&sort=&dir=&page=&per_page=` | any | Product list |
| GET | `api/products.php?id=5` | any | One product + recent movements + sales totals |
| GET | `api/products.php?lookup=1&q=cola` | any | Quick search for forms / POS |
| POST | `api/products.php` `create / update / archive / restore / delete` | admin | Manage products |
| GET | `api/categories.php` | any | Categories with product counts |
| POST | `api/categories.php` `create / update / delete` | admin | Manage categories |
| GET | `api/inventory.php?search=&movement_type=&product_id=&from=&to=&page=` | any | Movement history |
| GET | `api/inventory.php?view=low_stock` | any | Low-stock list with restock suggestions |
| POST | `api/inventory.php` `stock_in / stock_out` | any | Receive / remove stock |
| POST | `api/inventory.php` `adjustment` | admin | Set quantity to a physical count |
| GET | `api/sales.php?from=&to=&status=&search=&page=` | any | Sales history |
| GET | `api/sales.php?id=7` | any | One sale with items |
| POST | `api/sales.php` `create` | any | Complete a sale |
| POST | `api/sales.php` `void` | admin | Void a sale and return items to stock |
| GET | `api/reports.php?type=sales\|best_sellers\|slow_moving\|valuation\|movements\|low_stock\|transactions` | any | Reports (`&format=csv` to download) |
| GET / POST | `api/users.php` `create / update / set_active / reset_password` | admin | User management |

## Examples

### List products — `GET api/products.php?search=cola&per_page=2`

```json
{
  "success": true,
  "message": "Products loaded.",
  "data": {
    "items": [
      {
        "id": 6, "category_id": 7, "category_name": "Snacks", "sku": "SNK-002",
        "name": "Chocolate Bar 40g", "description": "[DEMO] Milk chocolate",
        "purchase_price": "18.00", "selling_price": "30.00",
        "quantity": 70, "reorder_level": 20, "is_active": true,
        "updated_at": "2026-11-03 00:03:45.073764+08", "stock_status": "in_stock"
      }
    ],
    "pagination": { "page": 1, "per_page": 2, "total": 2, "total_pages": 1 }
  },
  "errors": []
}
```

### Validation error — `POST api/products.php`

Request body:

```json
{ "action": "create", "category_id": 6, "sku": "bad sku", "name": "A",
  "purchase_price": "-1", "selling_price": "5", "reorder_level": 2 }
```

Response **422**:

```json
{
  "success": false,
  "message": "Please correct the highlighted fields.",
  "data": {},
  "errors": {
    "name": "Name must be at least 2 characters.",
    "purchase_price": "Purchase price must be a valid amount with up to 2 decimal places.",
    "sku": "SKU may only contain letters, numbers and dashes (e.g. BEV-001)."
  }
}
```

`app.js` puts each message under the matching form field (`App.showErrors`).

### Stock in — `POST api/inventory.php`

```json
{ "action": "stock_in", "product_id": 1, "quantity": 10, "notes": "DR-1002" }
```

Response **201**:

```json
{
  "success": true,
  "message": "Added 10 unit(s) to Orange Juice 1L. New stock: 16.",
  "data": {
    "movement_id": 34, "product_id": 1, "product_name": "Orange Juice 1L",
    "movement_type": "stock_in", "quantity": 10,
    "previous_quantity": 6, "resulting_quantity": 16,
    "created_at": "2026-11-03 00:31:59.550266+08"
  },
  "errors": []
}
```

### Create a sale — `POST api/sales.php`

Only product IDs and quantities are sent. **Prices and totals come from the database.**

```json
{
  "action": "create",
  "items": [ { "product_id": 1, "quantity": 2 }, { "product_id": 2, "quantity": 1 } ],
  "discount": "5",
  "payment_method": "cash",
  "amount_tendered": "200"
}
```

Response **201**:

```json
{
  "success": true,
  "message": "Sale TXN-20261103-000007 completed.",
  "data": {
    "sale": {
      "id": 7, "transaction_number": "TXN-20261103-000007",
      "subtotal": "180.00", "discount": "5.00", "total_amount": "175.00",
      "payment_method": "cash", "status": "completed",
      "cashier": "Test Admin", "created_at": "2026-11-03 00:31:59.569444+08",
      "items": [
        { "product_id": 1, "sku": "BEV-004", "name": "Orange Juice 1L", "quantity": 2, "unit_price": "85.00", "line_total": "170.00" },
        { "product_id": 2, "sku": "BEV-003", "name": "3-in-1 Coffee Sachet", "quantity": 1, "unit_price": "10.00", "line_total": "10.00" }
      ],
      "amount_tendered": "200.00", "change": "25.00"
    }
  },
  "errors": []
}
```

### Not enough stock — response **409**

```json
{
  "success": false,
  "message": "Some items cannot be sold. Please review the cart.",
  "data": {},
  "errors": { "product_8": "Sweet Corn 425g: only 0 in stock, 5 requested." }
}
```

Nothing is saved: no sale row, no items, no stock change.

### Dashboard — `GET api/dashboard.php?period=today` (shortened)

```json
{
  "success": true,
  "message": "Dashboard loaded.",
  "data": {
    "period": { "key": "today", "start": "2026-11-03", "end": "2026-11-03", "days": 1 },
    "inventory": { "active_products": 16, "archived_products": 0, "units_in_stock": 892,
      "value_at_cost": "16785.00", "value_at_retail": "25255.00",
      "low_stock": 1, "out_of_stock": 1, "categories": 5, "open_alerts": 2 },
    "sales": { "transactions": 5, "total": "541.00", "average": "108.20", "units_sold": 25,
      "previous_total": "0.00", "change_percent": null },
    "top_sellers": [], "low_stock": [], "slow_moving": {}, "chart": [],
    "recent_movements": [], "recent_sales": []
  },
  "errors": []
}
```

### Other error examples (real)

```json
{"success":false,"message":"Your session has expired. Please log in again.","data":{},"errors":[]}
{"success":false,"message":"Your session security token is invalid or expired. Please reload the page.","data":{},"errors":[]}
{"success":false,"message":"Malformed JSON in request body.","data":{},"errors":[]}
{"success":false,"message":"You do not have permission to do that.","data":{},"errors":[]}
```

Money values are returned as strings with 2 decimals (e.g. `"175.00"`) because PostgreSQL `NUMERIC`
is exact; converting to floating-point numbers could introduce rounding errors.
