<?php if ($errors): ?>
  <div class="alert alert-danger"><strong>Please fix the following:</strong>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" action="<?= e(url('expense/store')) ?>" class="panel form-grid" data-offline-action="expense_create" data-offline-note="Expense saved offline - it syncs when the connection returns.">
  <?= csrf_field() ?>
  <div>
    <label for="category_id">Category *</label>
    <select id="category_id" name="category_id" required data-other-input="category_other">
      <option value="">Choose...</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= ((int)$e['category_id'] === (int)$c['id']) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
      <option value="__other__">&#10133; Other / New category...</option>
    </select>
    <input type="text" id="category_other" name="category_other" hidden maxlength="80" placeholder="Type the new category name...">
  </div>
  <div>
    <label for="amount">Amount (RWF) *</label>
    <input type="number" id="amount" name="amount" min="1" step="1" required value="<?= e($e['amount']) ?>">
  </div>
  <div>
    <label for="payment_method">Payment method *</label>
    <select id="payment_method" name="payment_method">
      <?php foreach (['cash' => 'Cash (from the till)', 'mobile_money' => 'Mobile Money', 'bank' => 'Bank (online only)'] as $k => $label): ?>
        <option value="<?= e($k) ?>" <?= $e['payment_method'] === $k ? 'selected' : '' ?> <?= $k === 'bank' ? 'data-online-only' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="expense_date">Expense date *</label>
    <input type="date" id="expense_date" name="expense_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e($e['expense_date']) ?>">
  </div>
  <div class="full">
    <label for="description">Description *</label>
    <input type="text" id="description" name="description" minlength="3" maxlength="255" required value="<?= e($e['description']) ?>">
  </div>
  <div class="full muted small">Cash expenses are taken from the open cash session. The expense is recorded under your name.</div>
  <div class="full form-actions">
    <button type="submit" class="btn btn-primary">Save expense</button>
    <a class="btn btn-light" href="<?= e(url('expense')) ?>">Cancel</a>
  </div>
</form>
<script src="<?= e(asset('js/other_option.js')) ?>"></script>
