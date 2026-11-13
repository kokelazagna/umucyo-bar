<?php
// ============================================================================
// Supplier rules that go beyond one query: creating a supplier on the spot
// from a form ("Other / New..." in a dropdown). Queries live in supplier_model.
// ============================================================================

require_once __DIR__ . '/../helpers/php_helper.php';
require_once __DIR__ . '/../models/supplier_model.php';
require_once __DIR__ . '/audit_service.php';

// Returns the id of the supplier with this name; creates it when it does not
// exist yet (used by the "Other / New..." option in the purchase form).
function resolve_or_create_supplier(string $name, $conn): int
{
    $name = trim($name);
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        throw new RuntimeException('Type the supplier name (2 to 120 characters).');
    }
    $found = find_supplier_by_name($name, $conn);
    if ($found['success'] && !empty($found['data'])) {
        return (int)$found['data'][0]['id'];
    }
    $created = create_supplier([
        'name'          => $name,
        'business_name' => null,
        'phone'         => null,
        'email'         => null,
        'address'       => null,
        'status'        => 'active',
    ], $conn);
    if (!$created['success']) {
        throw new RuntimeException('The supplier could not be created. Please try again.');
    }
    $id = (int)$created['data']['id'];
    audit_log($conn, current_user_id(), 'supplier_create', 'supplier', $id, $name . ' (added from the purchase form)');
    return $id;
}
