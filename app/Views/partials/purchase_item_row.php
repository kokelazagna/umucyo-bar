<?php
// One line of the purchase form. Expects: $r (the line values) and $products (list for the drop-down).
?>
  <tr class="item-row" data-unit="<?= (int)$r['unit_id'] ?>">
    <td>
      <select name="product_id[]" class="p-product">
        <option value="0">Choose product...</option>
        <?php foreach ($products as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= ((int)$r['product_id'] === (int)$p['id']) ? 'selected' : '' ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><select name="unit_id[]" class="p-unit"></select></td>
    <td><input type="number" name="quantity[]" class="p-qty" min="1" step="1" value="<?= e($r['quantity']) ?>"></td>
    <td><input type="number" name="unit_cost[]" class="p-cost" min="0" step="1" value="<?= e($r['unit_cost']) ?>"></td>
    <td class="p-total">0 RWF</td>
    <td><button type="button" class="btn btn-light remove-row">&times;</button></td>
  </tr>
