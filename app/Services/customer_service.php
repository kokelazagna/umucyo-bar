<?php
// ============================================================================
// Customer rules that go beyond one query: creating a customer on the spot
// from a form ("Other / New..." in a dropdown). Queries live in customer_model.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';
require_once __DIR__ . '/../models/customer_model.php';
require_once __DIR__ . '/audit_service.php';

// Returns the id of the customer with this name; creates it when it does not
// exist yet (used by the "Other / New..." option in the settle and POS forms).
function resolve_or_create_customer(string $name, $conn): int
{
    $name = trim($name);
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        throw new RuntimeException('Type the customer name (2 to 120 characters).');
    }
    $found = find_customer_by_name($name, $conn);
    if ($found['success'] && !empty($found['data'])) {
        return (int)$found['data'][0]['id'];
    }
    $created = create_customer([
        'full_name'    => $name,
        'phone'        => null,
        'address'      => null,
        'credit_limit' => 0,
        'status'       => 'active',
    ], $conn);
    if (!$created['success']) {
        throw new RuntimeException('The customer could not be created. Please try again.');
    }
    $id = (int)$created['data']['id'];
    audit_log($conn, current_user_id(), 'customer_create', 'customer', $id, $name . ' (added from the bill form)');
    return $id;
}
