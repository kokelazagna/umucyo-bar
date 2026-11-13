<?php
$isEdit = ($mode === 'edit');
?>
<?php if ($errors): ?>
  <div class="alert alert-danger"><strong>Please fix the following:</strong>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" action="<?= e(url($isEdit ? 'customer/update' : 'customer/store')) ?>" class="panel form-grid" data-online-only>
  <?= csrf_field() ?>
  <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$customer['id'] ?>"><?php endif; ?>

  <?php if ($isEdit): ?>
    <div class="full muted">
      Customer ID: <strong><?= field($customer, 'customer_code') ?></strong> &nbsp;|&nbsp;
      Current debt: <strong><?= e(rwf($customer['current_debt'] ?? 0)) ?></strong>
      <br><small>The debt changes only through credit sales and repayments, never by editing the customer.</small>
    </div>
  <?php endif; ?>

  <div><label for="full_name">Full name *</label>
    <input type="text" id="full_name" name="full_name" maxlength="120" required value="<?= field($customer, 'full_name') ?>"></div>
  <div><label for="phone">Phone number</label>
    <input type="text" id="phone" name="phone" maxlength="20" value="<?= field($customer, 'phone') ?>"></div>
  <div><label for="address">Address</label>
    <input type="text" id="address" name="address" maxlength="255" value="<?= field($customer, 'address') ?>"></div>

  <?php if ($isManager): ?>
    <div><label for="credit_limit">Credit limit (RWF) - 0 means no credit</label>
      <input type="number" id="credit_limit" name="credit_limit" min="0" step="1" value="<?= field($customer, 'credit_limit', '0') ?>"></div>
    <div><label for="status">Status</label>
      <select id="status" name="status">
        <?php foreach (['active', 'inactive', 'blocked'] as $st): ?>
          <option value="<?= e($st) ?>" <?= ($customer['status'] ?? 'active') === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
        <?php endforeach; ?>
      </select></div>
  <?php else: ?>
    <div class="full muted small">The credit limit and status can only be set by a manager or the owner. New customers start with no credit.</div>
  <?php endif; ?>

  <div class="full form-actions">
    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Register customer' ?></button>
    <a class="btn btn-light" href="<?= e(url('customer')) ?>">Cancel</a>
  </div>
</form>
