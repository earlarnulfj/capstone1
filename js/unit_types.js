// Unit Types helper: fetch, cache, populate selects, add new unit
(function(){
  const CACHE_KEY = 'unit_types_cache_v1';
  const CACHE_TTL_MS = 5 * 60 * 1000; // 5 minutes
  const API_URL = '../api/unit-types.php';

  function now(){ return Date.now(); }
  function readCache(){
    try {
      const raw = localStorage.getItem(CACHE_KEY);
      if (!raw) return null;
      const obj = JSON.parse(raw);
      if (!obj || !Array.isArray(obj.items)) return null;
      if ((now() - (obj.ts || 0)) > CACHE_TTL_MS) return null;
      return obj.items;
    } catch (e) { return null; }
  }
  function writeCache(items){
    try { localStorage.setItem(CACHE_KEY, JSON.stringify({ ts: now(), items: items })); } catch(e){}
  }
  async function fetchUnitTypes(){
    const cached = readCache();
    if (cached) return cached;
    const res = await fetch(API_URL, { method: 'GET' });
    if (!res.ok) throw new Error('Failed to fetch unit types');
    const obj = await res.json();
    const items = Array.isArray(obj) ? obj : (Array.isArray(obj.items) ? obj.items : []);
    if (Array.isArray(items)) { writeCache(items); }
    return items;
  }
  async function addUnitTypeByName(name){
    const body = new URLSearchParams();
    body.set('name', name);
    const res = await fetch(API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    });
    if (!res.ok) {
      const err = await res.text();
      throw new Error(err || 'Failed to add unit type');
    }
    const out = await res.json();
    // Invalidate and refetch
    try { localStorage.removeItem(CACHE_KEY); } catch(e){}
    const items = await fetchUnitTypes();
    return { created: out, items };
  }
  function createInlineAdd(select){
    const wrap = document.createElement('div');
    wrap.className = 'input-group input-group-sm mt-2';
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-control';
    input.placeholder = 'New Unit Type (name)';
    input.setAttribute('aria-label','New Unit Type');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-outline-primary';
    btn.textContent = 'Add';
    const feedback = document.createElement('span');
    feedback.className = 'ms-2 text-success';
    feedback.style.display = 'none';
    feedback.textContent = 'Added';
    btn.addEventListener('click', async function(){
      const name = (input.value || '').trim();
      if (!name) { input.focus(); return; }
      try {
        const { items, created } = await addUnitTypeByName(name);
        const selName = (created && created.created && created.created.name) ? created.created.name : name;
        populateSelect(select, items, selName);
        input.value = '';
        feedback.style.display = 'inline';
        setTimeout(() => { feedback.style.display = 'none'; }, 2000);
      } catch (e) {
        alert('Error adding unit: ' + (e && e.message ? e.message : e));
      }
    });
    wrap.appendChild(input);
    wrap.appendChild(btn);
    wrap.appendChild(feedback);
    return wrap;
  }
  function populateSelect(select, items, selectName){
    const prev = select.value;
    // Preserve any custom options
    while (select.firstChild) select.removeChild(select.firstChild);
    const frag = document.createDocumentFragment();
    items.forEach(function(u){
      const opt = document.createElement('option');
      opt.value = (u.name || '').toLowerCase();
      opt.textContent = u.name || '';
      frag.appendChild(opt);
    });
    select.appendChild(frag);
    const target = selectName ? selectName.toLowerCase() : prev;
    if (target) { select.value = target; }
  }
  async function hydrateSelect(select){
    try {
      const items = await fetchUnitTypes();
      populateSelect(select, items);
      // Add inline input+Add if not present
      const next = select.nextElementSibling;
      if (!(next && next.__unitInlineAdd)) {
        const inline = createInlineAdd(select);
        inline.__unitInlineAdd = true;
        select.insertAdjacentElement('afterend', inline);
      }
      // Notify listeners that this select has been hydrated
      try { document.dispatchEvent(new CustomEvent('unitTypesHydrated', { detail: { select } })); } catch(e){}
    } catch (e) {
      // Leave existing options; log best-effort
      console.warn('UnitTypes hydrate error', e);
    }
  }

  async function populateSelects(selectors){
    const nodes = [];
    selectors.forEach(function(sel){
      document.querySelectorAll(sel).forEach(function(el){ nodes.push(el); });
    });
    for (const el of nodes) { await hydrateSelect(el); }
  }

  window.UnitTypes = {
    fetch: fetchUnitTypes,
    addByName: addUnitTypeByName,
    populateSelects: populateSelects
  };
})();