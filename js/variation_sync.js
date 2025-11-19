/*
 * VariationSync - polls for variation price updates and applies them to POS cards.
 * Requirements:
 * - Endpoint: ../admin/ajax/get_variation_updates.php
 * - Cards must have attributes:
 *   - data-id (inventory id)
 *   - data-variation-prices (JSON map)
 *   - Optionally data-variation-unittypes (JSON map) for admin POS
 * - A global function updateCardUnitAndPrice(cardEl) should recalc and refresh price display
 */
(function(){
  const VariationSync = function(options){
    options = options || {};
    this.feedUrl = options.feedUrl || '../admin/ajax/get_variation_updates.php';
    this.intervalMs = options.intervalMs || 15000; // 15s default
    this.since = options.since || null; // ISO or unix seconds
    this.timer = null;
    this.running = false;
  };
  VariationSync.prototype.log = function(msg){
    // Debug logging removed for production
  };
  VariationSync.prototype.toast = function(message, type){
    // Try to use page-provided toast helper if present
    try {
      if (typeof showToast === 'function') { showToast(message, type || 'info'); return; }
    } catch(e){}
    // Fallback console
    this.log(message);
  };
  VariationSync.prototype.fetchUpdates = async function(){
    const params = this.since ? ('?since=' + encodeURIComponent(this.since)) : '';
    const res = await fetch(this.feedUrl + params, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
    if (!res.ok) { throw new Error((window.I18N && I18N.t('common.http_error', { status: res.status })) || ('HTTP ' + res.status)); }
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); } catch (e) {
      throw new Error((window.I18N && I18N.t('common.invalid_json')) || 'Invalid JSON response');
    }
    if (!data.success) { throw new Error(data.error || (window.I18N && I18N.t('common.feed_error')) || 'Feed error'); }
    if (data.since) { this.since = data.since; }
    return data.data || [];
  };
  VariationSync.prototype.applyUpdates = function(updates){
    if (!Array.isArray(updates) || !updates.length) return;
    let appliedCount = 0;
    updates.forEach(u => {
      const id = +u.inventory_id;
      const cards = document.querySelectorAll('.product-card[data-id="' + id + '"]');
      if (!cards || !cards.length) return;
      cards.forEach(card => {
        // Update price map
        if (u.prices_map) {
          try {
            card.setAttribute('data-variation-prices', JSON.stringify(u.prices_map));
          } catch(e){}
        }
        // Update unit type map if exists (admin POS)
        if (u.unit_type_map && card.hasAttribute('data-variation-unittypes')) {
          try {
            card.setAttribute('data-variation-unittypes', JSON.stringify(u.unit_type_map));
          } catch(e){}
        }
        // Recompute price immediately
        try {
          if (typeof updateCardUnitAndPrice === 'function') {
            updateCardUnitAndPrice(card);
          }
        } catch(e){}
        appliedCount++;
      });
    });
    if (appliedCount > 0) {
      const msg = (window.I18N && I18N.t('variations.applied_updates', { count: appliedCount })) || ('Applied ' + appliedCount + ' price variation update(s)');
      this.toast(msg, 'success');
    }
  };
  VariationSync.prototype.pollOnce = async function(){
    try {
      const updates = await this.fetchUpdates();
      this.applyUpdates(updates);
    } catch (err) {
      const msg = (window.I18N && I18N.t('variations.sync_error_retry')) || 'Variation sync error. Retrying...';
      this.toast(msg, 'danger');
      this.log(err && err.message ? err.message : err);
    }
  };
  VariationSync.prototype.start = function(){
    if (this.running) return;
    this.running = true;
    this.pollOnce();
    this.timer = setInterval(() => this.pollOnce(), this.intervalMs);
    this.log((window.I18N && I18N.t('variations.started', { ms: this.intervalMs })) || ('Started with interval ' + this.intervalMs + 'ms'));
  };
  VariationSync.prototype.stop = function(){
    if (!this.running) return;
    this.running = false;
    if (this.timer) { clearInterval(this.timer); this.timer = null; }
    this.log((window.I18N && I18N.t('variations.stopped')) || 'Stopped');
  };

  // Auto-init on DOM ready (unless disabled)
  document.addEventListener('DOMContentLoaded', function(){
    try {
      // Check if sync is disabled (e.g., on supplier ordering pages)
      if (window.__DISABLE_VARIATION_SYNC === true) {
        // Still create instance for compatibility but don't start it
        const sync = new VariationSync();
        window.__variationSync = sync; // expose for debugging
        sync.log('Variation sync disabled via __DISABLE_VARIATION_SYNC flag');
        return;
      }
      const sync = new VariationSync();
      window.__variationSync = sync; // expose for debugging
      sync.start();
    } catch(e){}
  });
})();