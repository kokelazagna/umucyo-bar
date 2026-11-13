<div class="toolbar">
  <div class="muted">Configure the floor and the crate/pack conversions. Changing a conversion affects future rounds and sales only.</div>
  <div><a class="btn btn-light" href="<?= e(url('table')) ?>">Open floor</a></div>
</div>

<div class="split">
  <div class="panel">
    <h3>Service areas</h3>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Name</th><th>Order</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($areas as $a): ?>
          <?php $fid = 'area-' . (int)$a['id']; ?>
          <tr>
            <td>
              <form method="post" action="<?= e(url('settings/areaUpdate')) ?>" id="<?= $fid ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <input type="text" name="name" maxlength="80" value="<?= e($a['name']) ?>" required>
              </form>
            </td>
            <td><input form="<?= $fid ?>" type="number" name="sort_order" min="0" step="1" value="<?= (int)$a['sort_order'] ?>" style="width:80px"></td>
            <td><label class="check"><input form="<?= $fid ?>" type="checkbox" name="active" value="1" <?= (int)$a['active'] === 1 ? 'checked' : '' ?>></label></td>
            <td><button form="<?= $fid ?>" type="submit" class="btn btn-light">Save</button></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$areas): ?><tr><td colspan="4" class="muted">No areas yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <h4>Add an area</h4>
    <form method="post" action="<?= e(url('settings/areaStore')) ?>" class="inline-form">
      <?= csrf_field() ?>
      <input type="text" name="name" maxlength="80" placeholder="e.g. Terrace" required>
      <input type="number" name="sort_order" min="0" step="1" value="0" style="width:80px" aria-label="Sort order">
      <button type="submit" class="btn btn-light">Add area</button>
    </form>
  </div>

  <div class="panel">
    <h3>Add a table</h3>
    <form method="post" action="<?= e(url('settings/tableStore')) ?>" class="inline-form">
      <?= csrf_field() ?>
      <input type="text" name="code" maxlength="20" placeholder="Code (T07)" required style="width:110px">
      <select name="area_id" required>
        <option value="">Area...</option>
        <?php foreach ($areas as $a): ?>
          <option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="number" name="capacity" min="1" max="100" value="4" required style="width:80px" aria-label="Capacity">
      <input type="text" name="floor_position" maxlength="40" placeholder="Position (A1)" style="width:110px">
      <button type="submit" class="btn btn-light">Add table</button>
    </form>
    <p class="muted small">A table with an open tab is never changed by taking it out of service; settle the tab first.</p>
  </div>
</div>

<h3>Tables</h3>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Code</th><th>Area</th><th>Capacity</th><th>Position</th><th>Active</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tables as $t): ?>
      <?php $tfid = 'table-' . (int)$t['id']; ?>
      <tr>
        <td>
          <form method="post" action="<?= e(url('settings/tableUpdate')) ?>" id="<?= $tfid ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <input type="text" name="code" maxlength="20" value="<?= e($t['code']) ?>" required style="width:90px">
          </form>
        </td>
        <td>
          <select form="<?= $tfid ?>" name="area_id">
            <?php foreach ($areas as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= (int)$t['area_id'] === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td><input form="<?= $tfid ?>" type="number" name="capacity" min="1" max="100" value="<?= (int)$t['capacity'] ?>" style="width:80px"></td>
        <td><input form="<?= $tfid ?>" type="text" name="floor_position" maxlength="40" value="<?= e($t['floor_position']) ?>" style="width:90px"></td>
        <td><label class="check"><input form="<?= $tfid ?>" type="checkbox" name="active" value="1" <?= (int)$t['active'] === 1 ? 'checked' : '' ?>></label></td>
        <td>
          <span class="badge badge-<?= $t['session_id'] ? 'yellow' : ($t['status'] === 'available' ? 'green' : 'gray') ?>"><?= e($t['session_id'] ? 'occupied' : str_replace('_', ' ', $t['status'])) ?></span>
          <?php if ($t['session_id']): ?><a class="small" href="<?= e(url('table/session?id=' . (int)$t['session_id'])) ?>">tab</a><?php endif; ?>
        </td>
        <td><button form="<?= $tfid ?>" type="submit" class="btn btn-light">Save</button></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$tables): ?><tr><td colspan="7" class="muted">No tables yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<h3>Unit conversions and prices</h3>
<p class="muted small">
  Example: 1 crate = 20 bottles. The base unit always has a factor of 1. Each served line and sale line stores its own
  factor and price snapshot, so changing a conversion never rewrites completed transactions.
</p>

<?php foreach ($products as $p): ?>
  <div class="panel">
    <h4 style="margin-top:0"><?= e($p['name']) ?> <span class="muted small"><?= e($p['product_code']) ?> &middot; base <?= e($p['base_unit']) ?></span></h4>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Unit</th><th>Contains (<?= e($p['base_unit']) ?>s)</th><th class="right">Price (RWF)</th><th>Reason</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($p['units'] as $u): ?>
          <?php $ufid = 'unit-' . (int)$u['id']; ?>
          <tr>
            <td><strong><?= e($u['unit_name']) ?></strong><?= (int)$u['is_base'] === 1 ? ' <span class="badge badge-gray">base</span>' : '' ?></td>
            <td>
              <form method="post" action="<?= e(url('settings/unitUpdate')) ?>" id="<?= $ufid ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="unit_id" value="<?= (int)$u['id'] ?>">
                <input type="number" name="factor" min="1" max="1000" step="1" value="<?= (int)$u['conversion_factor'] ?>" <?= (int)$u['is_base'] === 1 ? 'readonly' : '' ?> style="width:90px">
              </form>
            </td>
            <td class="right"><input form="<?= $ufid ?>" type="number" name="price" min="0" step="1" value="<?= (int)$u['selling_price'] ?>" style="width:110px" required></td>
            <td><input form="<?= $ufid ?>" type="text" name="reason" maxlength="255" placeholder="Why (optional)" style="width:170px"></td>
            <td><button form="<?= $ufid ?>" type="submit" class="btn btn-light">Save</button></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$p['units']): ?><tr><td colspan="5" class="muted">No selling units.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>

<h3>Recent conversion / price changes</h3>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>When</th><th>Product</th><th>Unit</th><th>Factor</th><th class="right">Price</th><th>By</th><th>Reason</th></tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
      <tr>
        <td><?= e($h['changed_at']) ?></td>
        <td><?= e($h['product_name']) ?></td>
        <td><?= e($h['unit_name']) ?></td>
        <td><?= (int)$h['conversion_factor'] ?><?= $h['previous_factor'] !== null ? ' <span class="muted small">(was ' . (int)$h['previous_factor'] . ')</span>' : '' ?></td>
        <td class="right"><?= e(rwf($h['selling_price'])) ?><?= $h['previous_price'] !== null ? ' <span class="muted small">(was ' . e(rwf($h['previous_price'])) . ')</span>' : '' ?></td>
        <td><?= e($h['changed_by_name'] ?: '-') ?></td>
        <td><?= e($h['reason'] ?: '-') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$history): ?><tr><td colspan="7" class="muted">No changes recorded yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
