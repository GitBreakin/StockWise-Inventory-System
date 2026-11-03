# Business rules

Code: [`includes/inventory.php`](../includes/inventory.php).

## Stock changes

Every change to a product's quantity goes through one PHP function, `apply_stock_change()`. It:

1. requires an open database transaction;
2. locks the product row (`SELECT … FOR UPDATE`) so two users cannot change it at the same moment;
3. refuses the change if the result would be below 0;
4. updates `products.quantity`;
5. writes a `stock_movements` row with the previous and resulting quantity, the user and the reason;
6. opens or resolves the product's low-stock alert.

| Movement type | Who | Effect |
|---|---|---|
| `initial` | system (product created with opening stock) | + |
| `stock_in` | admin, staff | + (e.g. supplier delivery) |
| `stock_out` | admin, staff | − with a required reason: damaged, expired, returned to supplier, internal use, lost, other |
| `adjustment` | admin | sets quantity to a **physical count**; the difference (+ or −) is recorded with a reason and notes |
| `sale` | admin, staff | − (one per sale line) |
| `sale_void` | admin | + (items returned when a sale is voided) |

## Sales

- The browser sends only product IDs and quantities.
- The server reads the current selling price from the database, calculates every line, the subtotal,
  discount and total, and checks stock, all inside **one transaction**.
- If anything fails (not enough stock, archived product, discount larger than subtotal, database error),
  the whole sale is rolled back. Nothing is half-saved.
- Transaction numbers come from a PostgreSQL sequence: `TXN-YYYYMMDD-000001`. A failed sale may
  "use up" a number, so gaps in the sequence are normal and expected.
- A completed sale can be **voided** by an admin with a reason. Its items go back to stock
  (`sale_void` movements) and it is excluded from sales totals. It is never deleted.

## Low-stock alerts

- **Rule:** an active product is *low stock* when `quantity <= reorder_level`
  (*out of stock* when `quantity = 0`).
- An alert is opened automatically when that becomes true and resolved automatically when stock goes
  back above the reorder level (or the product is archived).
- A partial unique index allows **only one open alert per product**, so repeated sales of a low item
  do not create duplicate alerts.
- **Suggested restock quantity:**
  - if the product sold in the last 30 days:
    `suggested = reorder_level + units sold in the last 30 days − current quantity`
    (enough for about 30 more days of sales plus the safety level);
  - if it had no sales in 30 days: `suggested = 2 × reorder_level − current quantity`;
  - if the reorder level is 0: no suggestion (set a reorder level first).
  The basis of each suggestion is shown in the app (hover the number on the Inventory page) and in the report.

## Slow-moving products

Slow-moving is different from low stock: low stock means *too little* stock;
slow-moving means stock that is **not selling** and ties up money.

Only **active products that still have stock** are analyzed. Defaults: analysis period **30 days**,
coverage threshold **90 days** (both can be changed on the Reports page).

1. **Not enough data:** a product needs at least *period* days of sales tracking. Tracking starts at
   the later of (a) the day the product was added and (b) the day the first sale was ever recorded in
   the system. Products without enough history are listed separately and **never** labeled slow.
   *(A new system with no sales cannot call anything slow-moving.)*
2. **No sales:** 0 units sold in the last *period* days.
3. **Slow:** sold something, but at the current pace the stock would last longer than the threshold:
   `days of stock = quantity ÷ (units sold in period ÷ period days)` and `days of stock > 90`.

Each slow product gets plain-language recommendations, chosen by its situation:

| Situation | Recommendations |
|---|---|
| Never sold | Investigate why (placement, visibility, demand); do not reorder until it sells |
| No sales in the period, sold before | Consider a promotion or bundle; pause reordering, sell existing stock first |
| Slow (too many days of stock) | Sell existing stock before reordering; reduce the next purchase quantity |
| Margin ≥ 25 % | There is room for a discount or promo price |
| Margin < 25 % | Review the selling price or supplier cost instead of discounting further |

These are **suggestions based on recorded sales only**, not guaranteed predictions. The app says this
on the report.

## Product deletion vs. archiving

- **Archive** (`is_active = false`): hidden from the POS and default lists, history kept, can be restored.
- **Delete:** only allowed when the product has **no sales** and **no stock movements other than its
  opening stock** (e.g. a product created by mistake). Otherwise the API answers 409 and suggests archiving.

## Categories

- Names are unique regardless of letter case ("Snacks" = "snacks").
- A category can only be deleted when **no product** (active or archived) uses it.
