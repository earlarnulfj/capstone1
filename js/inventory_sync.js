(function(){
  const FEED_URL = '/admin/ajax/inventory_change_feed.php';
  const POLL_MS = 5000; // 5 seconds
  let lastVersion = 0;
  let lastMtime = 0;
  let lastReloadAt = 0;

  function isRelevantPage(){
    const p = window.location.pathname.toLowerCase();
    return p.endsWith('/admin/admin_pos.php') || p.endsWith('/staff/pos.php');
  }

  function poll(){
    const url = `${FEED_URL}?last_version=${encodeURIComponent(lastVersion)}&last_mtime=${encodeURIComponent(lastMtime)}`;
    fetch(url, { credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => {
        if (!data || !data.success) return;
        const invMtime = parseInt(data.inventory_mtime || 0, 10) || 0;
        const latestVer = parseInt(data.latest_version || 0, 10) || 0;
        const changed = !!data.changed;

        // Update local state
        lastMtime = Math.max(lastMtime, invMtime);
        lastVersion = Math.max(lastVersion, latestVer);

        if (changed && isRelevantPage()) {
          const now = Date.now();
          // Debounce reloads to avoid loops
          if (now - lastReloadAt > 8000) {
            console.info('[inventory_sync] Change detected. Reloading to apply updates...');
            lastReloadAt = now;
            // Prefer a soft refresh for cache busting
            window.location.reload();
          } else {
            console.info('[inventory_sync] Change detected but reload debounced.');
          }
        }
      })
      .catch(err => {
        console.warn('[inventory_sync] Poll error:', err);
      })
      .finally(() => {
        setTimeout(poll, POLL_MS);
      });
  }

  // Start polling when DOM is ready
  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    poll();
  } else {
    document.addEventListener('DOMContentLoaded', poll);
  }
})();