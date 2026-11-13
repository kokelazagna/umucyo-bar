<?php
$isEdit = ($mode === 'edit');
?>
<?php if ($errors): ?>
  <div class="alert alert-danger"><strong>Please fix the following:</strong>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" action="<?= e(url($isEdit ? 'supplier/update' : 'supplier/store')) ?>" class="panel form-grid" data-online-only>
  <?= csrf_field() ?>
  <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$supplier['id'] ?>"><?php endif; ?>

  <div><label for="name">Supplier name *</label>
    <input type="text" id="name" name="name" maxlength="120" required value="<?= field($supplier, 'name') ?>"></div>
  <div><label for="business_name">Business name</label>
    <input type="text" id="business_name" name="business_name" maxlength="150" value="<?= field($supplier, 'business_name') ?>"></div>
  <div><label for="phone">Phone number</label>
    <input type="text" id="phone" name="phone" maxlength="20" value="<?= field($supplier, 'phone') ?>"></div>
  <div><label for="email">Email address</label>
    <input type="text" id="email" name="email" maxlength="120" value="<?= field($supplier, 'email') ?>"></div>
  <div><label for="address">Address</label>
    <input type="text" id="address" name="address" maxlength="255" value="<?= field($supplier, 'address') ?>"></div>
  <div><label for="status">Status</label>
    <select id="status" name="status">
      <option value="active"   <?= ($supplier['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= ($supplier['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select></div>

  <div class="full form-actions">
    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Add supplier' ?></button>
    <a class="btn btn-light" href="<?= e(url('supplier')) ?>">Cancel</a>
  </div>
</form>
