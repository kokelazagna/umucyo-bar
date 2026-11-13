<?php
// ============================================================================
// Settings forms validation: areas, tables and unit conversions.
// ============================================================================

function validate_area(string $name): ?string
{
    if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
        return 'Enter an area name (2 to 80 characters).';
    }
    return null;
}

function validate_table_form(string $code, int $area, int $cap): ?string
{
    if ($code === '' || mb_strlen($code) > 20) {
        return 'Enter a table code such as T07.';
    }
    if ($area <= 0) {
        return 'Choose a service area for the table.';
    }
    if ($cap < 1 || $cap > 100) {
        return 'Capacity must be from 1 to 100 seats.';
    }
    return null;
}

function validate_unit_price(string $price): ?string
{
    if (!is_numeric($price)) {
        return 'Enter a valid price.';
    }
    return null;
}

function validate_withdrawal_reason(string $desc): ?string
{
    if (mb_strlen($desc) < 3 || mb_strlen($desc) > 255) {
        return 'Enter a reason for the withdrawal (3 to 255 characters).';
    }
    return null;
}
