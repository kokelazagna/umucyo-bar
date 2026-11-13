<?php
$isEdit = ($mode === 'edit');
$roleLabel = ['owner' => 'Owner / Administrator', 'manager' => 'Manager', 'cashier' => 'Cashier', 'stock_manager' => 'Stock Manager'];
?>
<?php if ($errors): ?>
  <div class="alert alert-danger"><strong>Please fix the following:</strong>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" action="<?= e(url($isEdit ? 'user/update' : 'user/store')) ?>" class="panel form-grid" autocomplete="off" data-online-only>
  <?= csrf_field() ?>
  <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$user['id'] ?>"><?php endif; ?>

  <div><label for="full_name">Full name *</label>
    <input type="text" id="full_name" name="full_name" maxlength="120" required value="<?= field($user, 'full_name') ?>"></div>
  <div><label for="username">Username *</label>
    <input type="text" id="username" name="username" maxlength="50" required value="<?= field($user, 'username') ?>"></div>
  <div><label for="email">Email</label>
    <input type="text" id="email" name="email" maxlength="120" value="<?= field($user, 'email') ?>"></div>
  <div><label for="phone">Phone</label>
    <input type="text" id="phone" name="phone" maxlength="20" value="<?= field($user, 'phone') ?>"></div>

  <div><label for="role_id">Role *</label>
    <select id="role_id" name="role_id" required <?= $self ? 'disabled' : '' ?>>
      <option value="">Choose...</option>
      <?php foreach ($roles as $r): ?>
        <option value="<?= (int)$r['id'] ?>" <?= ((int)($user['role_id'] ?? 0) === (int)$r['id']) ? 'selected' : '' ?>><?= e($roleLabel[$r['name']] ?? $r['name']) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div><label for="status">Status</label>
    <select id="status" name="status" <?= $self ? 'disabled' : '' ?>>
      <option value="active"   <?= ($user['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= ($user['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select></div>
  <?php if ($self): ?>
    <div class="full muted small">You cannot change your own role or status.</div>
  <?php endif; ?>

  <div><label for="password"><?= $isEdit ? 'New password (leave empty to keep the current one)' : 'Password *' ?></label>
    <input type="password" id="password" name="password" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>></div>
  <div><label for="password2">Repeat password</label>
    <input type="password" id="password2" name="password2" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>></div>
  <div class="full muted small">Passwords need at least 8 characters, with a letter and a digit. They are stored as secure bcrypt hashes and can never be read back.</div>

  <div class="full form-actions">
    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create user' ?></button>
    <a class="btn btn-light" href="<?= e(url('user')) ?>">Cancel</a>
  </div>
</form>
