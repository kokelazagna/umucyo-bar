# Testing checklist

Tick each line while testing. "Expected" says what must happen. Start from a fresh database
(`schema.sql` then `seed.sql`) and an open cash session where cash is involved.

## 1. Login and security

- [ ] Wrong password is refused with a message; the right one opens the dashboard.
- [ ] After logout, opening a page address directly sends you back to the login page.
- [ ] `cashier` opening `/purchase`, `/user`, `/report`, `/backup` gets "403".
- [ ] `stock` can open stock pages and stock reports, but not `/report/show?r=net_profit`.
- [ ] Deactivating a user (Users -> Deactivate) logs that user out on the next click.
- [ ] You cannot deactivate yourself or the last active owner.
- [ ] Passwords in the `users` table are long hashes (start with `$2y$`), never plain text.
- [ ] After 30 minutes without clicking, the session expires and asks you to sign in again.

## 2. Stock rules

- [ ] A saved purchase is **pending** and does not change stock; **Confirm & receive** adds stock.
- [ ] 5 crates of 20 bottles adds **100** bottles. Selling 1 crate removes 20; selling 7 bottles from a crate leaves 13 bottles, not a crate.
- [ ] Selling more than the stock is refused. Stock can never go below zero (also for adjustments).
- [ ] Cancelling a confirmed purchase removes the stock it added and writes a `purchase_cancel` movement. It is refused if that stock was already sold.
- [ ] Cancelling a sale restores the stock and writes `sale_cancel` movements.
- [ ] Stock Control lists every movement with the balance after it, the reason and the user.
- [ ] Stock adjustments (damaged, expired, missing, returned, correction) need a reason.

## 3. Money rules

- [ ] Average cost: buy at 700 then at 750 -> the average cost is the weighted average. Cancelling the second purchase returns it to 700.
- [ ] Each sale line stores the cost at the moment of sale (check `sale_items.unit_cost` in phpMyAdmin).
- [ ] A sale with several products is saved as one receipt with a unique receipt number.
- [ ] Split payment (cash + Mobile Money) is accepted; payments above the total are refused.
- [ ] Credit sale: needs a customer with a credit limit above 0; over the limit it is refused unless a manager/owner ticks **Authorize over limit**.
- [ ] A debt payment higher than the debt is refused; the oldest debt is paid first.
- [ ] Only a manager or the owner can give a discount.
- [ ] Owner withdrawals are not business expenses: net profit does not change when you record one.
- [ ] Cash paid to a supplier needs an open cash session with enough cash in it.

## 4. Cash session

- [ ] No sale is possible without an open cash session.
- [ ] Expected cash = opening + cash sales + cash debt payments + deposits - supplier payments - expenses - withdrawals - refunds.
- [ ] Credit sales, Mobile Money and bank payments do **not** change the expected cash.
- [ ] Closing with a different counted amount needs a note; the variance is saved.

## 5. Reports (open each one, then try the date filter)

- [ ] All 15 reports open: daily / weekly / monthly sales, product sales, purchases, stock balance, low stock, expired products, damaged stock, customer debt, supplier balance, daily expenses, gross profit, net profit, cash reconciliation.
- [ ] **Print / Save as PDF** shows only the report (menu hidden, A4 landscape).
- [ ] **Excel (.xlsx)** opens in Excel with numbers as numbers; **CSV** opens too.
- [ ] Gross profit = revenue - actual cost of goods sold (not selling price).
- [ ] Net profit = gross profit - expenses; owner withdrawals excluded.

## 6. Backup and restore (owner)

- [ ] **Download backup** gives a `.sql` file that starts with `-- BSSBMS BACKUP`.
- [ ] Change some data (add a category), then **Restore** the file with `RESTORE` typed: the category is gone and you sign in again.
- [ ] A safety copy of the data before the restore is in `storage/backups/before-restore-*.sql`.
- [ ] Restore refuses: no `RESTORE` typed, a file that is not a backup, a file with extra statements, a backup with a missing table.

## 7. Traceability

- [ ] Audit Log shows logins, price changes, discounts, cancellations, stock adjustments, withdrawals, user changes, exports and backups.
- [ ] Every purchase, sale, payment, adjustment and cash movement shows the responsible user and date.
- [ ] Completed sales are never deleted: they can only be cancelled, with a reason.

---

### What was tested automatically

The whole system was run against a real MySQL-compatible database and a test script clicked through
the application like a user. **157 checks** passed: the complete workflow above, all 15 reports with their
real numbers, Excel and CSV export, roles, backup and restore (including the refusal cases). The 17-step
demonstration (`docs/DEMO_SCRIPT.md`) was run separately: **24 checks** passed.

## 8. Offline mode (v2.2) - manual checklist

Prerequisites: run `database/upgrade-v2.2-offline.sql` once (adds `sync_log`),
open the app once online so the service worker installs, then press F12 and check
Application > Service workers shows the worker with scope `/bssbms3modn/`.

### Install and cache
- [ ] The browser offers "Install Umucyo Bar" (install icon in the address bar); the installed app opens in its own window.
- [ ] DevTools > Network > Offline, then reload the POS: the page still loads and shows the status bar "Offline".
- [ ] A page you NEVER opened while online shows the clear "You are offline - this page was never cached" notice instead of an error.
- [ ] Pages that cannot work offline (Users, Purchases, Stock, Reports...) show the orange "You are offline..." notice when offline.

### Offline selling (POS)
- [ ] Open a cash session first (online). Go offline, sell 3 items with cash and Mobile Money: the status bar shows "3 waiting to sync" and each sale shows a "queued" toast.
- [ ] While offline, try to sell on credit or select a customer: blocked ("online only").
- [ ] While offline, try a bank payment: blocked.
- [ ] Reload while offline: the 3 queued sales are still waiting.
- [ ] Go back online: the status bar shows "Syncing...", then the sales appear in Sales History with normal receipt numbers.
- [ ] Submitting was tried twice for the same sale? There is exactly ONE receipt per offline sale (the status bar shows "Already synced earlier - no duplicate was created" on retries).
- [ ] Sell more units than the server stock (e.g. cached stock said 6, another till sold 5 while you were offline): that sale lands in "Needs attention" with the reason "Not enough stock for ...". Stock never goes below zero in Reports > Stock balance.
- [ ] Mobile Money sale shows the reference on the receipt; confirm the MoMo payment on the phone (flagged "needs confirmation").

### Offline table tabs and expenses
- [ ] Offline: add draft drinks to a table tab, confirm served, take a cash/MoMo payment - all queued and synced.
- [ ] Offline: settle a tab on customer credit or with bank - blocked ("online only").
- [ ] Offline: record a cash expense with a category that still exists - queued and synced.
- [ ] Discard a rejected item in "Needs attention": it disappears and is never sent.

### Session and logout
- [ ] Let the session expire (or log in on another browser and change your own password), then sync: the app asks you to sign in again, and after signing in the queue continues by itself.
- [ ] With unsynced items, click "Log out": a warning lists them; logging out clears the cached data and the queue (DevTools > Application > IndexedDB > bssbms_offline is empty).

### Automated browser tests (Playwright, needs Node.js)
```
cd tests/browser
npm install playwright mysql2
npx playwright install chromium
node offline.spec.js        # 19 checks: cached pages offline, 3 offline sales,
                            # reload, sync-once, duplicate retry, needs attention, logout clears
```
(This suite was run against a live WAMP+MySQL copy of this project: **19/19 checks passed**.)

### What still needs a manual test on your server
- [ ] The sync of a sale made on ONE device while ANOTHER device sold the same stock at the same time (real concurrent conflict).
- [ ] Install as an app on the actual tablet/PC used at the bar and sell with the WiFi unplugged (not just DevTools offline).
- [ ] Mobile Money reference confirmation flow after a synced offline MoMo sale.
- [ ] The receipt page (Sales History) of an offline sale right after it synced.
