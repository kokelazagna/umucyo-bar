<?php
// ============================================================================
// Sync endpoints for offline mode (answers the browser's offline.js with JSON).
//   sync/data  (GET)  -> snapshot to cache in IndexedDB (products, units and
//                        prices, categories, customers with limits and debts,
//                        the open cash session, expense categories) + CSRF token.
//   sync/push  (POST) -> a batch of queued offline actions, processed in order.
//                        Every item carries its idempotency key (client_id).
// Session, CSRF and roles are checked here; business rules are re-checked by
// the online service functions in sync_service.php. The server is the authority.
// ============================================================================

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/sync_service.php';

function handleSyncRequest($action, $data, $conn) {
    // Every sync endpoint needs a logged-in user (a not-logged-in push is
    // answered with 401 so the browser asks the user to sign in again).
    if (!is_logged_in()) {
        json_response(['ok' => false, 'error' => 'login'], 401);
    }

    switch ($action) {
        case 'data':
            return sync_data($conn);
        case 'push':
            return sync_push($conn);
        default:
            json_response(['ok' => false, 'error' => 'not_found'], 404);
    }
}

function sync_data($conn): void
{
    json_response([
        'ok'          => true,
        'csrf'        => csrf_token(),
        'user'        => [
            'id'   => (int)current_user_id(),
            'name' => current_user()['full_name'] ?? '',
            'role' => (string)current_role(),
        ],
        'snapshot'    => sync_snapshot($conn),
        'server_time' => date('Y-m-d H:i:s'),
    ]);
}

function sync_push($conn): void
{
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        json_response(['ok' => false, 'error' => 'bad_json'], 400);
    }

    // CSRF: the browser sends the token inside the JSON body. Merge it into
    // $_POST so the normal csrf_verify() protection stays in place.
    if (isset($body['_csrf']) && is_string($body['_csrf'])) {
        $_POST['_csrf'] = $body['_csrf'];
    }
    if (!csrf_verify()) {
        // 419: the browser will ask the user to log in again and continue syncing.
        json_response(['ok' => false, 'error' => 'csrf'], 419);
    }

    $actions = $body['actions'] ?? null;
    if (!is_array($actions) || !count($actions)) {
        json_response(['ok' => false, 'error' => 'no_actions'], 422);
    }

    $results = sync_process_batch($actions, (int)current_user_id(), (string)current_role(), $conn);
    json_response(['ok' => true, 'results' => $results]);
}
