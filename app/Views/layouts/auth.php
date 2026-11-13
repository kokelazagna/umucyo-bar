<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#100b20">
  <meta name="description" content="Umucyo Bar management system sign in">
  <title><?= e($title ?? 'Sign in') ?> · Umucyo Bar</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(asset('css/login.css')) ?>">
</head>
<body>
  <?= $content ?>
  <script src="<?= e(asset('js/login.js')) ?>"></script>
</body>
</html>
