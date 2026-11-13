<?php
// ============================================================================
// THE one shared pagination partial - every list table in the project renders
// its pagination through this file (via render_pagination() in app_helper).
// Expects: $pages, $page, $from, $to, $total, $parts, $what
// Style: small square buttons in a row; the current page is a filled square
// with a check mark; "Previous" and "Next" walk one page at a time.
// ============================================================================
?>
<nav class="pagin" aria-label="Pagination">
  <span class="pagin__info">Showing <?= number_format($from) ?>&ndash;<?= number_format($to) ?>
    of <?= number_format($total) ?> <?= e($what) ?></span>

  <div class="pagin__pages">
    <?php if ($page > 1): ?>
      <a class="pagin__btn" href="<?= e(page_url(1)) ?>" title="First page">&laquo;</a>
      <a class="pagin__btn pagin__txt" href="<?= e(page_url($page - 1)) ?>">Previous</a>
    <?php else: ?>
      <span class="pagin__btn is-off">&laquo;</span><span class="pagin__btn is-off pagin__txt">Previous</span>
    <?php endif; ?>

    <?php foreach ($parts as $p): ?>
      <?php if ($p === 0): ?>
        <span class="pagin__dots">&hellip;</span>
      <?php elseif ($p === $page): ?>
        <span class="pagin__num is-active" aria-current="page" title="You are on page <?= (int)$p ?>">&#10003;</span>
      <?php else: ?>
        <a class="pagin__num" href="<?= e(page_url((int)$p)) ?>" title="Page <?= (int)$p ?>"><?= (int)$p ?></a>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($page < $pages): ?>
      <a class="pagin__btn pagin__txt" href="<?= e(page_url($page + 1)) ?>">Next</a>
      <a class="pagin__btn" href="<?= e(page_url($pages)) ?>" title="Last page">&raquo;</a>
    <?php else: ?>
      <span class="pagin__btn is-off pagin__txt">Next</span><span class="pagin__btn is-off">&raquo;</span>
    <?php endif; ?>
  </div>

  <form class="pagin__goto" method="get">
    <?php foreach ($_GET as $k => $v): ?>
      <?php if ($k === 'page' || $v === '' || is_array($v)) continue; ?>
      <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
    <?php endforeach; ?>
    <label for="goto-page">Go to</label>
    <input type="number" id="goto-page" name="page" min="1" max="<?= (int)$pages ?>" value="<?= (int)$page ?>">
    <button type="submit" class="pagin__go">Go</button>
  </form>
</nav>
