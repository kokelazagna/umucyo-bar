<?php
// ============================================================================
// Product form validation (rules only; the database lookups stay in the controller).
// ============================================================================

function validate_product(array $d, array $rows): array
{
    $errors = [];

    if ($d['name'] === '' || mb_strlen($d['name']) > 120) {
        $errors[] = 'Product name is required (maximum 120 characters).';
    }
    if ((int)$d['category_id'] <= 0) {
        $errors[] = 'Choose a category.';
    }
    if (mb_strlen($d['brand']) > 80) {
        $errors[] = 'Brand is too long (maximum 80 characters).';
    }
    if (mb_strlen($d['barcode']) > 50) {
        $errors[] = 'Barcode is too long (maximum 50 characters).';
    }
    if (!in_array($d['base_unit'], product_base_units(), true)) {
        $errors[] = 'Choose a valid base unit.';
        $d['base_unit'] = 'bottle';
    }
    if (!in_array($d['status'], ['active', 'inactive'], true)) {
        $d['status'] = 'active';
    }
    if (!is_numeric($d['purchase_price']) || (float)$d['purchase_price'] < 0 || (float)$d['purchase_price'] > 100000000) {
        $errors[] = 'Purchase price must be a number of 0 or more.';
    }
    if (!is_numeric($d['selling_price']) || (float)$d['selling_price'] <= 0 || (float)$d['selling_price'] > 100000000) {
        $errors[] = 'Selling price must be a number greater than 0.';
    }
    if (!ctype_digit((string)$d['min_stock']) || (int)$d['min_stock'] > 1000000) {
        $errors[] = 'Minimum stock must be a whole number of 0 or more.';
    }
    if ($d['expiry_date'] !== '' && !is_valid_date($d['expiry_date'])) {
        $errors[] = 'Expiry date is not valid.';
    }

    // Extra selling units (crate, pack...): the form sends several lists, one value per row
    $seen = [$d['base_unit'] => true];   // a unit name can be used only once per product
    foreach ($rows as $row) {
        if ($row['remove']) {
            continue;
        }
        $name   = $row['name'];
        $factor = $row['factor'];
        $price  = $row['price'];

        if (!in_array($name, product_unit_names(), true)) {
            $errors[] = 'Choose a valid selling unit.';
        } elseif (isset($seen[$name])) {
            $errors[] = 'The unit "' . $name . '" is listed more than once (or equals the base unit).';
        }
        $seen[$name] = true;

        if (!ctype_digit((string)$factor) || (int)$factor < 2 || (int)$factor > 1000) {
            $errors[] = 'Units per ' . $name . ' must be a whole number from 2 to 1000.';
        }
        if ($price !== '' && (!is_numeric($price) || (float)$price < 0)) {
            $errors[] = 'Price per ' . $name . ' must be a number of 0 or more.';
        }
    }

    return $errors;
}
