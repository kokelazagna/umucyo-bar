<div class="panel">
  <h3>Add category</h3>
  <form method="post" action="<?= e(url('category/store')) ?>" class="inline-form">
    <?= csrf_field() ?>
    <input type="text" name="name" placeholder="Name (e.g. Beer)" maxlength="80" required>
    <input type="text" name="description" placeholder="Description (optional)" maxlength="255">
    <button type="submit" class="btn btn-primary">Add</button>
  </form>
</div>

<div class="table-wrap">
  <table class="table">
    <thead>
      <tr><th>#</th><th>Name</th><th>Description</th><th>Products</th><th>Status</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php foreach ($categories as $c): ?>
      <tr>
        <form method="post" action="<?= e(url('category/update')) ?>" id="cat-<?= (int)$c['id'] ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        </form>
        <td><?= (int)$c['id'] ?></td>
        <td><input form="cat-<?= (int)$c['id'] ?>" type="text" name="name" value="<?= e($c['name']) ?>" maxlength="80" required></td>
        <td><input form="cat-<?= (int)$c['id'] ?>" type="text" name="description" value="<?= e($c['description']) ?>" maxlength="255"></td>
        <td><?= (int)$c['product_count'] ?></td>
        <td><span class="badge badge-<?= $c['status'] === 'active' ? 'green' : 'gray' ?>"><?= e($c['status']) ?></span></td>
        <td class="actions">
          <button form="cat-<?= (int)$c['id'] ?>" type="submit" class="btn btn-light">Save</button>
          <form method="post" action="<?= e(url('category/toggle')) ?>" style="display:inline"
                onsubmit="return confirm('Change the status of this category?');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button type="submit" class="btn btn-light"><?= $c['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$categories): ?>
      <tr><td colspan="6" class="muted">No categories yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_pagination($total, $page, PER_PAGE, 'categories'); ?>
