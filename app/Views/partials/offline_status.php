<?php
// Offline status bar: Online / Offline / Syncing / "N waiting to sync".
// The visible state is controlled by public/assets/js/offline.js; this partial
// only holds the markup (and its styles) so it exists on every page.
?>
<div id="offline-status" class="offline-status" data-state="unknown" hidden>
  <span id="offline-dot" class="offline-status__dot" aria-hidden="true"></span>
  <span id="offline-text">Checking connection...</span>
  <button type="button" id="offline-attention-btn" class="offline-status__btn" hidden>
    Needs attention (<span id="offline-attention-count">0</span>)
  </button>
  <button type="button" id="offline-sync-btn" class="offline-status__btn" hidden>Sync now</button>
</div>
<div id="offline-attention" class="offline-attention" hidden>
  <div class="offline-attention__box" role="dialog" aria-label="Offline items needing attention">
    <div class="offline-attention__head">
      <strong>Needs attention</strong>
      <button type="button" id="offline-attention-close" class="offline-status__btn" aria-label="Close">&times;</button>
    </div>
    <p class="muted small">Offline actions the server rejected or could not accept. Nothing is lost until you discard it.</p>
    <div id="offline-attention-list" class="offline-attention__list"></div>
  </div>
</div>
<style>
.offline-status{position:fixed;left:0;right:0;bottom:0;z-index:9000;display:flex;align-items:center;gap:10px;
  padding:6px 14px;background:#1f1740;color:#fff;font-size:12.5px;box-shadow:0 -2px 8px rgba(0,0,0,.25)}
.offline-status__dot{width:10px;height:10px;border-radius:50%;background:#9aa;flex:0 0 auto}
.offline-status[data-state="online"] .offline-status__dot{background:#22c55e}
.offline-status[data-state="offline"] .offline-status__dot{background:#f59e0b}
.offline-status[data-state="syncing"] .offline-status__dot{background:#38bdf8;animation:offline-pulse 1s infinite}
.offline-status[data-state="attention"] .offline-status__dot{background:#ef4444}
@keyframes offline-pulse{50%{opacity:.3}}
.offline-status__btn{background:rgba(255,255,255,.12);border:0;color:#fff;font-size:12px;padding:3px 10px;border-radius:6px;cursor:pointer}
.offline-status__btn:hover{background:rgba(255,255,255,.25)}
.offline-notice{margin:10px 0;padding:10px 14px;border-radius:8px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font-size:13.5px}
.offline-queue-flag{display:inline-block;margin-left:6px;padding:1px 8px;border-radius:99px;background:#fef3c7;color:#92400e;font-size:11px;font-weight:600}
.offline-attention{position:fixed;inset:0;z-index:9100;background:rgba(20,12,45,.55);display:flex;align-items:center;justify-content:center}
.offline-attention__box{background:#fff;color:#2d2350;border-radius:12px;max-width:640px;width:92%;max-height:80vh;overflow:auto;padding:16px 18px}
.offline-attention__head{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.offline-attention__list .off-item{border:1px solid #e7e3f5;border-radius:8px;padding:10px 12px;margin-bottom:8px}
.offline-attention__list .off-item__reason{color:#b91c1c;font-size:13px;margin:4px 0 8px}
.offline-attention__list .off-item__meta{color:#6b5fa8;font-size:11.5px}
[disabled][data-online-blocked]{opacity:.55;cursor:not-allowed}
</style>
