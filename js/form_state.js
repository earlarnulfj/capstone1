// Form state persistence for Admin Inventory add/edit modals
(function(){
  const DEFAULT_TTL_MS = 24 * 60 * 60 * 1000; // 24 hours

  function now(){ return Date.now(); }

  // Detect best available storage (localStorage -> sessionStorage -> none)
  function detectStore(){
    try {
      localStorage.setItem('__fs_test__', '1');
      localStorage.removeItem('__fs_test__');
      return localStorage;
    } catch (e) {
      try {
        sessionStorage.setItem('__fs_test__', '1');
        sessionStorage.removeItem('__fs_test__');
        return sessionStorage;
      } catch(e2) {
        console.warn('FormState: storage unavailable; persistence will be disabled for this session');
        return null;
      }
    }
  }

  const STORE = detectStore();
  const MEMORY_FALLBACK = {}; // last-resort non-persistent cache

  function read(key){
    try {
      const raw = STORE ? STORE.getItem(key) : MEMORY_FALLBACK[key] || null;
      if (!raw) return null;
      const obj = JSON.parse(raw);
      if (!obj || typeof obj !== 'object') return null;
      if (obj.ts && (now() - obj.ts) > (obj.ttl || DEFAULT_TTL_MS)) return null;
      return obj.data || null;
    } catch(e){ return null; }
  }

  function write(key, data, ttl){
    const payload = JSON.stringify({ ts: now(), ttl: ttl || DEFAULT_TTL_MS, data: data });
    try {
      if (STORE) {
        STORE.setItem(key, payload);
      } else {
        MEMORY_FALLBACK[key] = payload;
      }
    } catch(e){
      // Attempt sessionStorage if localStorage failed at runtime
      try {
        if (STORE === localStorage && typeof sessionStorage !== 'undefined') {
          sessionStorage.setItem(key, payload);
        }
      } catch(e2){ /* swallow */ }
    }
  }

  function clear(key){
    try {
      if (STORE) STORE.removeItem(key);
      delete MEMORY_FALLBACK[key];
    } catch(e){}
  }

  function serialize(form){
    const out = {};
    const els = form.querySelectorAll('input, select, textarea');
    els.forEach(function(el){
      const name = el.name || el.id;
      if (!name) return;
      if (el.type === 'file') return;
      if (el.type === 'checkbox') {
        out[name] = !!el.checked;
      } else if (el.type === 'radio') {
        if (el.checked) { out[name] = el.value; }
      } else if (el.tagName === 'SELECT' && el.multiple) {
        const vals = [];
        for (const opt of el.selectedOptions) vals.push(opt.value);
        out[name] = vals;
      } else {
        out[name] = el.value;
      }
    });
    return out;
  }

  function applyValue(el, val){
    if (el.type === 'checkbox') {
      el.checked = !!val;
    } else if (el.type === 'radio') {
      el.checked = (el.value === val);
    } else if (el.tagName === 'SELECT' && el.multiple && Array.isArray(val)) {
      for (const opt of el.options) { opt.selected = val.includes(opt.value); }
    } else if (typeof val === 'string' || typeof val === 'number') {
      el.value = val;
    }
  }

  function restore(form, data){
    if (!data) return;
    const els = form.querySelectorAll('input, select, textarea');
    els.forEach(function(el){
      const name = el.name || el.id;
      if (!name) return;
      if (!(name in data)) return;
      applyValue(el, data[name]);
    });
  }

  function initForm(form, key){
    // Restore immediately (for static inputs)
    const saved = read(key);
    restore(form, saved);

    // Special: unit type selects may hydrate asynchronously; listen and re-apply
    document.addEventListener('unitTypesHydrated', function(ev){
      try {
        const sel = ev && ev.detail && ev.detail.select;
        if (!sel || !form.contains(sel)) return;
        const name = sel.name || sel.id;
        const data = read(key);
        if (data && data[name]) {
          // Attempt to select the saved unit type (by lowercased text value)
          const target = (data[name] || '').toString().toLowerCase();
          let matched = false;
          for (const opt of sel.options) {
            if ((opt.value || '').toString().toLowerCase() === target) { sel.value = opt.value; matched = true; break; }
          }
          if (!matched) {
            // Fallback by visible text
            for (const opt of sel.options) {
              if ((opt.textContent || '').toString().toLowerCase() === target) { sel.value = opt.value; break; }
            }
          }
        }
      } catch(e){}
    });

    // Persist on change/input
    const save = function(){ write(key, serialize(form)); };
    form.addEventListener('change', save, true);
    form.addEventListener('input', save, true);

    // Save on submit, then clear to avoid stale data
    form.addEventListener('submit', function(){
      try { write(key, serialize(form)); } catch(e){}
      try { clear(key); } catch(e){}
    }, true);
  }

  function initForSelectors(items){
    items.forEach(function(it){
      const form = document.querySelector(it.form);
      if (form) initForm(form, it.key);
    });
  }

  window.FormState = {
    initForSelectors: initForSelectors,
    clear: clear,
    saveNow: function(formSelector, key){ const f = document.querySelector(formSelector); if (f) write(key, serialize(f)); },
    restoreNow: function(formSelector, key){ const f = document.querySelector(formSelector); if (f) restore(f, read(key)); }
  };
})();