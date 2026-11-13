# Offline mode - how it works (simple English)

## The idea in one paragraph

When the bar has no internet, the Point of Sale, the table tabs and the expenses
page keep working from a copy saved inside the browser. Anything you do offline is
put in a waiting list inside the browser. As soon as the connection comes back,
the app sends the waiting list to the server, one action at a time and in order.
The server re-checks everything (stock, prices, cash session, permissions, form
token) exactly as if the action was done online, then either accepts it or rejects
it with a reason. Rejected actions are never thrown away - they stay in the
"Needs attention" list so you can fix or discard them.

## What still works offline

| Action offline | How it behaves |
|---|---|
| POS sale - **cash** | Queued, syncs automatically. Receipt appears in Sales History after sync. |
| POS sale - **Mobile Money** | Queued and synced, flagged in the app as "needs confirmation" (check the MoMo reference after sync). |
| POS sale - credit / bank / non-walk-in customer | **Blocked offline** (online only). |
| Table tab: add draft drink, change/remove a draft line | Queued. Draft lines never touch stock until "Confirm served". |
| Table tab: "Confirm served" | Queued; the server re-checks stock when syncing - if stock is gone, it is rejected with a reason and stock never goes below zero. |
| Table tab: take payment (cash / MoMo) | Queued; credit settlement and bank are blocked offline. |
| Expenses (cash / MoMo) | Queued. Bank expenses are blocked offline. |
| Cash session open/close, deposits, withdrawals | **Online only.** |
| Cancel a sale, void a served line, transfers, merges | **Online only.** |
| Purchases (create, confirm, pay), stock adjustments | **Online only.** |
| Users, settings, reports, backup, debt payments | **Online only.** |

Pages that cannot work offline show a clear orange notice: *"You are offline. This
page is showing the last saved copy only..."* instead of failing silently.

## The moving parts

1. **Service worker** - `public/sw.js` (served at `/bssbms3modn/sw.js` through a
   `.htaccess` rewrite, so its scope is the whole app). It caches the app shell
   (CSS, JS, icons, fonts, manifest) and every visited page. Offline, it serves
   the last saved copy of a page; a page that was never visited gets a clear
   "you are offline" notice page. POST requests are never served from the cache.
2. **Web manifest** - `public/manifest.webmanifest` with icons, so the app can be
   installed on a tablet/PC and start without the browser UI.
3. **Data snapshot** - on every page load the app fetches `sync/data` (JSON):
   products with their selling units and prices, categories, customers with credit
   limits and debts, the open cash session, expense categories, plus the current
   CSRF token. This snapshot is saved in IndexedDB. NO passwords are ever cached.
4. **Queue** - `IndexedDB` database `bssbms_offline`, store `queue`. Each entry:
   a unique idempotency key (`client_id`), the action type, the payload, the time
   it was made, and its status (pending / rejected + reason).
5. **Status bar** - bottom of every page: Online / Offline / Syncing /
   "N waiting to sync" / "N item(s) need attention", with buttons for
   "Needs attention" (list with reason + Retry / Discard) and "Sync now".
6. **Sync endpoint** - `sync/push` (POST, JSON). Checks the session and the CSRF
   token, then runs each queued action through the SAME functions used online
   (`create_sale`, `add_draft_item`, `serve_draft_round`, `settle_table_session`,
   `create_expense`), each inside its own database transaction. Every action is
   first written to the `sync_log` table with a UNIQUE key: if the same key is
   sent again (a retry), the server answers "duplicate - nothing was done again"
   instead of executing it twice. Accepted and rejected actions are recorded in
   `sync_log` and in the audit log (`sync_pos_sale`, `sync_expense_create`, ...).

## What you see when the connection returns

1. The status bar switches to **Syncing...** and the queue is sent in order.
2. Accepted actions disappear from the queue and a toast shows the result
   (e.g. "Sale REC-20261113-0001 accepted (4,500 RWF)").
3. If a session expired while you were offline, the app asks you to **sign in
   again**; after signing in the queue continues by itself.
4. Rejected actions stay in **Needs attention** with the reason, for example:
   - "Not enough stock for 'Primus 72cl': 4 bottle(s) available, 6 requested."
   - "Offline sales can only be for the walk-in customer. Credit sales are online only."
   - "Bank payments are online only."
   You can Retry (after fixing the problem online) or Discard (it is then never
   sent; nothing was charged and no stock moved).

## Safety rules

- The server is always the authority - the browser cache is only a convenience.
- A retry can never duplicate a sale or payment (UNIQUE idempotency key).
- Stock can never go below zero: the sale is rejected instead.
- No passwords or password hashes are stored offline, ever.
- Logging out clears the cached data and the queue (you get a warning first when
  unsynced items exist - sync them first or accept losing them).
- All existing protections stay: CSRF on every POST (including sync/push),
  prepared statements, role checks, transactions, audit log.

## Database change

`database/upgrade-v2.2-offline.sql` (run once in phpMyAdmin) adds only one table:
`sync_log`. Nothing else in the database changes.
