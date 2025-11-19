// POS Products Synchronization and Standardized Display
// Fetches standardized product data and updates POS UIs consistently
(function(){
  const API_URL = '../api/pos_products.php';
  const REFRESH_MS = 15000; // 15 seconds
  const FEED_URL = '/admin/ajax/inventory_change_feed.php';
  const FEED_POLL_MS = 5000; // 5 seconds
  let lastVersion = 0;
  let lastMtime = 0;
  let refreshing = false;

  function $(sel, root){ return (root||document).querySelector(sel); }
  function $all(sel, root){ return Array.from((root||document).querySelectorAll(sel)); }

  function formatPrice(n){
    try { return '₱' + Number(n).toFixed(2); } catch(e){ return '₱0.00'; }
  }

  function applyStockBadge(badgeEl, qty){
    if (!badgeEl) return;
    const q = parseInt(qty, 10) || 0;
    badgeEl.classList.remove('bg-danger','bg-warning','text-dark','bg-success');
    if (q <= 0) {
      badgeEl.classList.add('bg-danger');
    } else if (q <= 10) {
      badgeEl.classList.add('bg-warning','text-dark');
    } else {
      badgeEl.classList.add('bg-success');
    }
    badgeEl.innerHTML = `<i class="bi bi-box me-1"></i>${q}`;
  }

  function ensureSyncIndicator(){
    let el = document.getElementById('posSyncIndicator');
    if (!el) {
      el = document.createElement('div');
      el.id = 'posSyncIndicator';
      el.className = 'alert alert-info position-fixed top-0 end-0 m-3 py-1 px-2 d-none';
      el.style.zIndex = '1060';
      el.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span><span>Syncing inventory…</span>';
      document.body.appendChild(el);
    }
    return el;
  }

  function setSyncIndicator(active){
    const el = ensureSyncIndicator();
    el.classList.toggle('d-none', !active);
  }

  function updateCardFromProduct(card, p){
    if (!card || !p) return;
    // Update data attributes
    card.setAttribute('data-price', String(p.selling_price));
    card.setAttribute('data-stock', String(p.quantity));
    card.setAttribute('data-base-stock', String(p.quantity));
    card.removeAttribute('data-unavailable');
    card.classList.remove('opacity-50');

    // Price display
    const priceEl = card.querySelector('.price-display');
    if (priceEl) {
      const unitLabel = priceEl.querySelector('.auto-unit-label');
      const labelHtml = unitLabel ? unitLabel.outerHTML : '';
      priceEl.innerHTML = `${formatPrice(p.selling_price)} ${labelHtml}`;
    }

    // Name
    const nameEl = card.querySelector('h6, .card-title');
    if (nameEl && p.name) { nameEl.textContent = p.name; }

    // Stock badge
    const badgeEl = card.querySelector('.stock-badge');
    applyStockBadge(badgeEl, p.quantity);
  }

  function showError(msg){
    let alert = $('#productErrAlert');
    if (!alert) {
      alert = document.createElement('div');
      alert.id = 'productErrAlert';
      alert.className = 'alert alert-danger mt-2';
      // Try to inject near the products container
      const container = $('#productContainer') || document.body;
      container.parentNode.insertBefore(alert, container);
    }
    alert.textContent = msg;
    alert.style.display = 'block';
  }

  function hideError(){
    const alert = $('#productErrAlert');
    if (alert) alert.style.display = 'none';
  }

  async function fetchProducts(){
    const url = `${API_URL}?ordered_only=0`;
    const res = await fetch(url, { credentials: 'same-origin' });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    if (!data || !data.success) throw new Error(data && data.error ? data.error : 'Unknown error');
    return data.products || [];
  }

  async function refresh(){
    if (refreshing) return; // prevent overlap
    refreshing = true;
    setSyncIndicator(true);
    try {
      const products = await fetchProducts();
      hideError();
      const byId = new Map();
      products.forEach(p => byId.set(String(p.id), p));
      // Update existing cards by ID, mark missing ones as unavailable
      const cards = $all('.product-card[data-id]');
      cards.forEach(card => {
        const pid = card.getAttribute('data-id');
        const p = byId.get(String(pid));
        if (p) {
          updateCardFromProduct(card, p);
        } else {
          // Mark card as unavailable
          card.setAttribute('data-unavailable', '1');
          card.classList.add('opacity-50');
          const badgeEl = card.querySelector('.stock-badge');
          applyStockBadge(badgeEl, 0);
          const priceEl = card.querySelector('.price-display');
          if (priceEl) {
            priceEl.innerHTML = '<span class="text-danger">Unavailable</span>';
          }
        }
      });
    } catch(e){
      console.warn('[pos_products] refresh error:', e);
      showError('Unable to access inventory data. Please check your connection.');
    } finally {
      refreshing = false;
      setSyncIndicator(false);
    }
  }

  function pollChangeFeed(){
    const url = `${FEED_URL}?last_version=${encodeURIComponent(lastVersion)}&last_mtime=${encodeURIComponent(lastMtime)}`;
    fetch(url, { credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => {
        if (!data || !data.success) return;
        const invMtime = parseInt(data.inventory_mtime || 0, 10) || 0;
        const latestVer = parseInt(data.latest_version || 0, 10) || 0;
        const changed = !!data.changed;
        lastMtime = Math.max(lastMtime, invMtime);
        lastVersion = Math.max(lastVersion, latestVer);
        if (changed) {
          refresh();
        }
      })
      .catch(err => {
        console.warn('[pos_products] change-feed poll error:', err);
      });
  }

  function init(){
    const start = () => {
      refresh();
      const refreshTimer = setInterval(() => { if (!document.hidden) refresh(); }, REFRESH_MS);
      const feedTimer = setInterval(() => { if (!document.hidden) pollChangeFeed(); }, FEED_POLL_MS);
      document.addEventListener('visibilitychange', () => {
        // No-op; timers already check document.hidden
      });
      // Expose for debugging
      window.__posProducts = { refresh, pollChangeFeed, refreshTimer, feedTimer };
    };
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
      start();
    } else {
      document.addEventListener('DOMContentLoaded', start);
    }
  }

  init();
})();