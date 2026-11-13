<?php
// One extra selling unit (crate, pack...) of the product form.
// Expects: $r (the unit values), $unitNames and $baseUnit.
?>
  <div class="unit-row">
    <input type="hidden" name="unit_id[]" value="<?= (int)$r['id'] ?>">
    <select name="unit_name[]">
      <?php foreach ($unitNames as $n): ?>
        <option value="<?= e($n) ?>" <?= ($r['name'] === $n) ? 'selected' : '' ?>><?= e(ucfirst($n)) ?></option>
      <?php endforeach; ?>
    </select>
    <span>contains</span>
    <input type="number" name="unit_factor[]" min="2" max="1000" step="1" placeholder="e.g. 20" value="<?= e($r['factor']) ?>">
    <span class="base-label"><?= e($baseUnit) ?>(s)</span>
    <span>price</span>
    <input type="number" name="unit_price[]" min="0" step="1" placeholder="auto" value="<?= e($r['price']) ?>">
    <?php if ((int)$r['id'] > 0): ?>
      <label class="check"><input type="checkbox" name="unit_remove[]" value="<?= (int)$r['id'] ?>"> Remove</label>
    <?php else: ?>
      <button type="button" class="btn btn-light remove-new">&times;</button>
    <?php endif; ?>
  </div>
