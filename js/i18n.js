// Lightweight i18n module with JSON-configured translations, dynamic switching, and English fallback
(function(){
  const CONFIG_URL = '/config/translations.json';
  const STORAGE_KEY = 'app.language';
  const DEFAULT_LANG = 'en';
  let cfg = null;
  let currentLang = DEFAULT_LANG;
  let cache = {}; // cache of translations per lang

  function safeGetStorage(key){ try { return localStorage.getItem(key); } catch(e){ return null; } }
  function safeSetStorage(key,val){ try { localStorage.setItem(key,val); } catch(e){} }

  function interpolate(str, params){
    if (!params) return str;
    return str.replace(/\{(\w+)\}/g, (m, k) => (params[k] != null ? String(params[k]) : m));
  }

  async function loadConfig(){
    try {
      const res = await fetch(CONFIG_URL, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
      const text = await res.text();
      cfg = JSON.parse(text);
      if (!cfg || !cfg.translations || !cfg.translations.en) throw new Error('Invalid translations config');
      const stored = safeGetStorage(STORAGE_KEY);
      currentLang = (stored && cfg.translations[stored]) ? stored : (cfg.default || DEFAULT_LANG);
      cache = cfg.translations;
    } catch(e){
      // Fallback hardcoded English
      cfg = { languages: ['en'], default: 'en', translations: { en: {} } };
      currentLang = 'en';
    }
  }

  function t(key, params){
    if (!cfg) return interpolate(key, params); // before init, return key
    const enDict = cache['en'] || {};
    const langDict = cache[currentLang] || {};
    let str = langDict[key];
    if (str == null) str = enDict[key];
    if (str == null) str = key; // ultimate fallback
    return interpolate(String(str), params);
  }

  function setLanguage(lang){
    if (!cfg) return;
    if (!cache[lang]) return;
    currentLang = lang;
    safeSetStorage(STORAGE_KEY, lang);
    document.dispatchEvent(new CustomEvent('languageChanged', { detail: { lang } }));
  }

  function getLanguage(){ return currentLang; }
  function getLanguages(){ return (cfg && cfg.languages) ? cfg.languages : Object.keys(cache || { en: {} }); }

  // Minimal UI: inject a language selector if a header exists
  function injectSwitcher(){
    try {
      const langs = getLanguages();
      if (!langs || langs.length < 2) return; // skip if only one
      let host = document.querySelector('.navbar') || document.body;
      const wrap = document.createElement('div');
      wrap.style.position = 'fixed';
      wrap.style.top = '8px';
      wrap.style.right = '8px';
      wrap.style.zIndex = '9999';
      wrap.style.background = 'rgba(255,255,255,0.9)';
      wrap.style.border = '1px solid #ddd';
      wrap.style.borderRadius = '6px';
      wrap.style.padding = '6px';
      wrap.style.boxShadow = '0 1px 4px rgba(0,0,0,0.1)';
      const label = document.createElement('label');
      label.textContent = 'Language:';
      label.style.marginRight = '6px';
      const sel = document.createElement('select');
      sel.setAttribute('aria-label', 'Language selector');
      langs.forEach(l => {
        const opt = document.createElement('option');
        opt.value = l; opt.textContent = l.toUpperCase();
        if (l === currentLang) opt.selected = true;
        sel.appendChild(opt);
      });
      sel.addEventListener('change', () => setLanguage(sel.value));
      wrap.appendChild(label); wrap.appendChild(sel);
      host.appendChild(wrap);
    } catch(e){}
  }

  // Initialize
  document.addEventListener('DOMContentLoaded', async function(){
    await loadConfig();
    injectSwitcher();
    document.dispatchEvent(new CustomEvent('languageReady', { detail: { lang: currentLang } }));
  });

  window.I18N = { t, setLanguage, getLanguage, getLanguages };
})();