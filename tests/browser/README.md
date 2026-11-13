# Automated offline tests (Playwright)

Tests the offline mode of the app in a real Chromium browser: cached pages load
offline, sales created offline sync exactly once, a duplicate retry creates
nothing, a rejected action appears in "Needs attention", and logging out clears
the cached data and the queue.

## Run (one-time setup)

1. The app must run at http://localhost/bssbms3modn (WAMP + MySQL running) and
   `database/upgrade-v2.2-offline.sql` must be applied.
2. A test user is created by the script's first run? NO - create it once yourself
   (or change USER/PASS in offline.spec.js to any active manager/cashier):
   ```sql
   -- generate a hash with: php -r "echo password_hash('YOUR-PASSWORD', PASSWORD_DEFAULT);"
   -- then run the tests with: set BSSBMS_TEST_USER=pw_tester && set BSSBMS_TEST_PASS=YOUR-PASSWORD
   INSERT INTO users (username, email, password_hash, full_name, role_id, status)
   VALUES ('pw_tester', 'pw_tester@example.com', '<hash>', 'Playwright Tester', 2, 'active');
   ```
3. Node.js 18+ required:
   ```
   npm install playwright mysql2
   npx playwright install chromium
   node offline.spec.js
   ```

## What it checks (19 checks)

1. Login, service worker registration and control
2. POS page loads; status bar "Online"; snapshot cached in IndexedDB
3. Offline: status bar "Offline"; cached POS page reloads
4. 3 sales made offline ("3 waiting to sync"); queue survives a reload
5. An action the server will reject is queued too ("4 waiting")
6. Back online: sync runs; exactly 3 new sales in MySQL (no duplicates)
7. Re-sending an already-synced key returns "duplicate" and creates nothing
8. The rejected action is listed in "Needs attention" with its reason
9. Logging out warns about unsynced items and clears the offline database
