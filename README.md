# Umucyo Bar - BSSBMS (Bar Stock, Sales and Business Management System)

Plain PHP, written with **functions only** (no classes, no framework), arranged in the
same MVC structure as the ILMS project: a single `route.php` front controller,
`app/controllers`, `app/models`, `app/services`, `app/validators`, `app/helpers`,
`app/middleware` and `app/views`.

## Run it (WAMP)

1. Copy the `bssbms3mod` folder to `C:\wamp64\www\`.
2. In phpMyAdmin run `database/schema.sql`, then `database/seed.sql`.
3. Check `app/config/connection.php` (default WAMP: user `root`, empty password,
   database `bar_management_db`).
4. Open `http://localhost/bssbms3mod/` (the root `.htaccess` sends every address to
   `route.php`, like ILMS does).

Sample accounts (password for all: `Admin@123`, change it!):
`admin` (owner), `manager`, `cashier`, `stock`.
You can sign in with the username **or** the email.

## Folder map (same layout as ILMS)

```
route.php               every request enters here (front controller): ?url=module/action
.htaccess               rewrites every pretty address to route.php
app/config/connection.php   the one MySQL connection ($conn, mysqli)
app/controllers/        one file per module, e.g. product_controller.php with
                        handleProductRequest($action, $data, $conn)
app/models/             database queries only: search_products(), find_customer() ...
app/services/           business rules that change stock or money, in a transaction:
                        create_sale(), confirm_purchase(), close_cash_session() ...
app/validators/         form validation rules only
app/helpers/            php_helper.php (executeQuery, transactions) and
                        app_helper.php (url, e, render, input readers), xlsx_helper.php
app/middleware/auth.php session, flash messages, CSRF, login state and roles
app/views/              the HTML of each page (layouts/ and partials/ are shared parts)
public/assets/          css, js, video
database/               schema.sql, seed.sql, erd.png / erd.svg (diagram), ERD.md
docs/                   DEMO_SCRIPT.md (the 17 steps) and TESTING_CHECKLIST.md
storage/backups/        safety copies made before a restore (created automatically)
```

`app/helpers/xlsx_helper.php` builds Excel files, `app/models/report_model.php` holds the
report queries with `app/services/report_service.php` assembling them, and
`app/services/backup_service.php` makes and restores database backups.

## How a request travels

```
browser -> route.php  (the .htaccess rewrote /product/store to route.php?url=product/store)
   1. reads the module and the action from the address
   2. checks login, account status and the CSRF token (middleware/auth.php)
   3. loads app/controllers/{module}_controller.php and calls
      handle{Module}Request($action, $data, $conn)
          controller: checks the roles, reads the form, validates it
          -> validator: the form rules (app/validators)
          -> service: the business rules, in ONE transaction (app/services)
          -> model: the database queries (app/models, only database data)
          -> render('products/index', [...])  shows the view inside layouts/main.php
```

## Separation of the layers (same as ILMS)

| Layer | Folder | Contains | Never contains |
|-------|--------|----------|----------------|
| Controller | app/controllers | reads the form, role checks, flash messages, which view to show | SQL, business rules, HTML |
| Validator | app/validators | the form rules (returns a list of errors) | SQL, HTML |
| Service | app/services | business rules, transactions, stock and money changes | HTML, echoing |
| Model | app/models | database queries only (through executeQuery) | rules, HTML |
| View | app/views | HTML only (front end) | SQL |

The one AJAX endpoint of the application (the POS checkout, answered with JSON by
`pos/checkout`) works exactly as before.

## Naming rules (so you can guess a function name)

| Kind | Style | Examples |
|------|-------|---------|
| Controller file | `{module}_controller.php` with `handle{Module}Request` | `product_controller.php`, `handleProductRequest` |
| Model file | `{module}_model.php` | `product_model.php`, `sale_model.php` |
| Service file | `{module}_service.php` | `sale_service.php`, `table_service.php` |
| Validator file | `{module}_validator.php` | `product_validator.php` |
| Model (query) | `verb_noun` | `search_products`, `find_customer`, `create_user`, `set_product_status` |
| Service (rules) | `verb_noun` | `create_sale`, `cancel_purchase`, `deposit_cash` |
| Helpers | short and clear | `url`, `e`, `flash`, `render`, `has_role`, `csrf_field`, `executeQuery` |

Every model and service function receives the connection `$conn` and every database
query returns the same shape:
`['success' => bool, 'error' => bool, 'data' => mixed, 'msg' => string]`.

## Offline mode (v2.2)

The bar keeps working when the internet or server connection drops, and everything
syncs safely when the connection returns. In short:

- The app is installable and offline-capable: `public/sw.js` (service worker,
  served at the project root by a `.htaccess` rewrite so its scope covers the whole
  app) plus `public/manifest.webmanifest`. The app shell and visited pages are
  cached; the data the screens need (products, units and prices, categories,
  customers with limits and debts, the open cash session, expense categories) is
  cached in IndexedDB and refreshed from `sync/data` on every page load.
- A status bar at the bottom of every page shows Online / Offline / Syncing /
  "N waiting to sync", and a **Needs attention** panel lists every action the
  server rejected, with the reason and a Retry / Discard button. Nothing is lost
  silently.
- While offline you can still: sell at the POS (cash and Mobile Money only, walk-in
  customer only), add draft drinks to a table tab, confirm a served round, take a
  tab payment (cash / Mobile Money) and record expenses (cash / Mobile Money).
  Everything else is clearly marked **online only** (users, cash session close and
  movements, sale cancellation, purchases, stock adjustments, credit sales, debt
  payments, bank payments) and is disabled while offline.
- Every queued action gets a unique idempotency key. When the connection returns,
  the queue is sent in order to `sync/push`, where the SERVER stays the authority:
  it re-checks stock, prices, the cash session, roles and CSRF using the same
  business functions as online, then answers accepted or rejected with a reason.
  A retry can never create a duplicate sale or payment (UNIQUE `sync_log.client_id`).
- If a session or token expires during sync, the app asks you to sign in again and
  then continues syncing automatically. If stock is no longer enough at sync time,
  that action is rejected with a clear message - stock can never go below zero.
- No passwords are ever stored offline. Logging out clears the cached data and the
  queue (with a warning when unsynced items exist). Every synced action is audited.

Details and diagrams: `docs/OFFLINE_MODE.md`. To apply on an existing database run
`database/upgrade-v2.2-offline.sql` once (adds the `sync_log` table).

Automated offline tests (Playwright): see `tests/offline.spec.js` and
`docs/TESTING_CHECKLIST.md`.
