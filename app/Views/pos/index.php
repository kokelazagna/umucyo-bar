<div class="pos">

  <!-- ========== Left: search + categories + product grid ========== -->
  <section class="pos__catalog">
    <label class="posearch">
      <span class="sr-only">Search products</span>
      <input type="text" id="pos-search" placeholder="Search by name or brand, or scan a barcode and press Enter" autofocus>
      <button type="button" class="square-btn" id="pos-search-btn" aria-label="Search"><svg class="icon"><use href="#i-search"/></svg></button>
    </label>

    <div class="tabs pos__tabs" id="pos-cats" role="tablist" aria-label="Product categories"></div>

    <div id="product-grid" class="product-grid"></div>
  </section>

  <!-- ========== Right: current sale ========== -->
  <form id="pos-form" class="pos__cart" autocomplete="off" onsubmit="return false;">
    <?= csrf_field() ?>
    <input type="hidden" name="checkout_token" value="<?= e($token) ?>">

    <div class="cart__head">
      <h2 class="cart__title">Current sale</h2>
      <span class="cart__count" id="cart-count">0</span>
    </div>

    <div class="cart__customer">
      <label for="customer_id">Customer</label>
      <select id="customer_id" name="customer_id" data-other-input="customer_other"></select>
      <input type="text" id="customer_other" name="customer_other" hidden maxlength="120" placeholder="Type the new customer name...">
    </div>

    <div id="cart-rows" class="cart__rows"></div>
    <p id="cart-empty" class="cart__empty">Click a product to add it to the sale.</p>

    <div class="cart__box totals">
      <div class="totals__row"><span>Subtotal</span><strong id="t-subtotal">0 RWF</strong></div>
      <?php if ($payload['isManager']): ?>
        <div class="totals__row"><label for="discount">Discount (RWF)</label>
          <input type="number" id="discount" name="discount" min="0" step="1" value="0"></div>
      <?php endif; ?>
      <div class="totals__row grand"><span>TOTAL</span><strong id="t-total">0 RWF</strong></div>
    </div>

    <div class="cart__paytitle">
      <h3>Payment</h3>
      <button type="button" class="linkbtn" id="add-pay">+ Add payment (split)</button>
    </div>
    <div id="pay-rows" class="pay-rows"></div>

    <div class="cart__summary">
      <div class="cart__box"><span class="cart__label">Paid now</span><strong id="t-paid">0 RWF</strong></div>
      <div class="cart__box"><span class="cart__label">On credit (debt)</span><strong id="t-credit">0 RWF</strong></div>
    </div>

    <div id="credit-warning" class="alert alert-warning" hidden></div>
    <?php if ($payload['isManager']): ?>
      <label class="check" id="override-box" hidden>
        <input type="checkbox" name="override_limit" value="1"> Authorize over limit
      </label>
    <?php endif; ?>

    <div id="pos-errors" class="alert alert-danger" hidden></div>

    <div class="cart__actions">
      <button type="button" class="btn btn-light" id="btn-clear">Clear</button>
      <button type="button" class="btn btn-primary" id="btn-complete" disabled>
        <svg class="icon"><use href="#i-check"/></svg><span id="btn-complete-label">Complete sale</span>
      </button>
    </div>
  </form>
</div>

<script type="application/json" id="pos-data"><?= json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= e(asset('js/pos.js')) ?>"></script>
<script src="<?= e(asset('js/other_option.js')) ?>"></script>
