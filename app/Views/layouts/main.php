<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? 'Dashboard') ?> – Umucyo Bar</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(asset('css/design.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/reports.css')) ?>?v=<?= (int)@filemtime(BASE_PATH . '/public/assets/css/reports.css') ?>">
  <!-- Offline mode: installable app + service worker + client sync engine -->
  <link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
  <meta name="theme-color" content="#7c3aed">
  <script id="offline-boot" type="application/json"><?= json_encode([
      'csrf'    => csrf_token(),
      'user'    => ['id' => (int)(current_user_id() ?? 0), 'name' => (string)(current_user()['full_name'] ?? ''), 'role' => (string)(current_role() ?? '')],
      'base'    => base_url(),
      'urls'    => ['syncData' => url('sync/data'), 'syncPush' => url('sync/push')],
      'page'    => ['active' => (string)($active ?? ''), 'title' => (string)($title ?? '')],
      'offline' => is_file(BASE_PATH . '/public/assets/js/offline.js'),
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <script src="<?= e(asset('js/offline.js')) ?>"></script>
</head>
<body>
  <?php require __DIR__ . '/../partials/icons.php'; ?>

  <div class="app-shell">
    <?php require __DIR__ . '/../partials/sidebar.php'; ?>

    <main class="orders">
      <?php require __DIR__ . '/../partials/pagehead.php'; ?>

      <?php foreach (flash_pull() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>

      <?= $content ?>
    </main>
  </div>

  <?php require __DIR__ . '/../partials/offline_status.php'; ?>
</body>
</html>
