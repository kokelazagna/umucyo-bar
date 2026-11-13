<?php
// Messages waiting from the server (wrong password, logged out, session expired...).
// The design has ONE message line, so all waiting messages are joined into it.
$messageText  = '';
$messageClass = '';
foreach (flash_pull() as $flash) {
    $messageText  .= ($messageText === '' ? '' : ' ') . $flash['message'];
    $messageClass  = ($flash['type'] === 'success' && $messageClass !== 'error') ? 'success' : 'error';
}
?>
<main class="login-shell">
  <video class="background-video" autoplay muted loop playsinline poster="">
    <source src="<?= e(asset('video/bg_vd.mp4')) ?>" type="video/mp4">
  </video>
  <div class="video-shade" aria-hidden="true"></div>
  <div class="ambient ambient-one" aria-hidden="true"></div>
  <div class="ambient ambient-two" aria-hidden="true"></div>
  <div class="grain" aria-hidden="true"></div>

  <section class="content">
    <div class="brand-side">
      <a class="brand" href="<?= e(url('auth/login')) ?>" aria-label="Umucyo Bar home">
        <span class="brand-mark"><span></span></span>
        <span class="brand-name">Umucyo <b>Bar</b></span>
      </a>

      <div class="intro">
        <div class="eyebrow"><span class="eyebrow-dot"></span> BAR MANAGEMENT PLATFORM</div>
        <h1>Every pour.<br><span>Every detail.</span><br>Under control.</h1>
        <p class="intro-copy">A clearer view of your business starts here. Manage sales, stock, cash sessions and customer accounts from one place.</p>

        <div class="feature-row" aria-label="Platform features">
          <div class="feature">
            <span class="feature-icon amber"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h10l-1 8a4 4 0 0 1-8 0L7 3Z"/><path d="M12 15v5m-4 1h8M17 6h3v2a3 3 0 0 1-3 3"/></svg></span>
            <span><strong>Fast POS</strong><small>Scan &amp; sell</small></span>
          </div>
          <div class="feature">
            <span class="feature-icon violet"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2 9 5v10l-9 5-9-5V7l9-5Z"/><path d="m3.5 7.5 8.5 5 8.5-5M12 12.5V22"/></svg></span>
            <span><strong>Live stock</strong><small>Low-stock alerts</small></span>
          </div>
          <div class="feature">
            <span class="feature-icon coral"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="M7 9h.01M17 15h.01M10 12h4"/></svg></span>
            <span><strong>Cash control</strong><small>Shift sessions</small></span>
          </div>
        </div>
      </div>

      <div class="brand-foot"><span class="pulse"></span> BUILT FOR THE FLOW OF YOUR BAR</div>
    </div>

    <div class="form-side">
      <div class="login-card">
        <div class="card-topline"><span class="card-kicker">YOUR WORKSPACE</span><span class="secure"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4.5 7V5a3.5 3.5 0 0 1 7 0v2M3 7h10v7H3z"/></svg> SECURE ACCESS</span></div>
        <div class="heading">
          <div class="mini-mark"><span></span></div>
          <h2>Welcome back<span>.</span></h2>
          <p>Sign in to pick up where your shift left off.</p>
        </div>

        <form id="loginForm" method="post" action="<?= e(url('auth/login')) ?>" novalidate>
          <?= csrf_field() ?>

          <label for="username">Username or email</label>
          <div class="input-wrap">
            <svg class="field-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/></svg>
            <input id="username" name="username" type="text" placeholder="e.g. admin" autocomplete="username" required>
          </div>

          <div class="label-line"><label for="password">Password</label></div>
          <div class="input-wrap password-wrap">
            <svg class="field-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
            <input id="password" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" required>
            <button class="visibility" type="button" id="togglePassword" aria-label="Show password" aria-pressed="false">
              <svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 5.2A10.7 10.7 0 0 1 12 5c6.4 0 10 7 10 7a16 16 0 0 1-3.1 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.3 0 2.5-.3 3.6-.8"/></svg>
            </button>
          </div>

          <div class="form-options">
            <label class="remember"><input type="checkbox" name="remember" id="remember"><span class="checkmark"></span> Remember me</label>
            <button class="text-button" type="button" id="forgotButton">Forgot password?</button>
          </div>

          <button class="submit-button" type="submit" id="submitButton">
            <span id="submitLabel">Sign in</span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
            <span class="button-glint" aria-hidden="true"></span>
          </button>
          <p class="form-message<?= $messageClass !== '' ? ' ' . e($messageClass) : '' ?>" id="formMessage" role="status" aria-live="polite"><?= e($messageText) ?></p>
        </form>

        <div class="card-footer">
          <span class="system-status"><i></i> All systems operational</span>
          <span>© <?= e(date('Y')) ?> Umucyo Bar</span>
        </div>
      </div>
      <p class="outside-note">A better shift starts with a clear picture.</p>
    </div>
  </section>
  <div class="corner-caption"><span class="caption-line"></span> UMUCYO BAR <span>·</span> MANAGEMENT SYSTEM</div>
</main>
