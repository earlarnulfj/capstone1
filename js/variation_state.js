// Centralized variation state manager shared across POS and supplier pages
// Exposes a global VariationState for tracking selected attributes and computed UI data.

(function(){
  const getCardId = (cardEl) => {
    const id = parseInt(
      cardEl.getAttribute('data-id') ||
      cardEl.getAttribute('data-product-id') || '0', 10
    );
    return isNaN(id) ? 0 : id;
  };

  const getSelectedAttributes = (cardEl) => {
    const id = getCardId(cardEl);
    const container = cardEl.querySelector('.variation-attrs');
    if (!container) return {};
    const inputs = container.querySelectorAll(`input[name^="variant_attr_${id}_"]`);
    if (!inputs.length) return {};
    const groupNames = Array.from(new Set(Array.from(inputs).map(i => i.getAttribute('name'))));
    const attrs = {};
    for (const gn of groupNames){
      const sel = container.querySelector(`input[name="${gn}"]:checked`);
      const attrName = gn.replace(`variant_attr_${id}_`, '');
      if (sel) attrs[attrName] = sel.value;
    }
    return attrs;
  };

  const buildVariantKey = (attrs) => {
    const parts = Object.keys(attrs).map(k => `${k}:${attrs[k]}`);
    parts.sort((a,b)=>a.localeCompare(b));
    return parts.join('|');
  };

  const readComputedData = (cardEl) => {
    return {
      price: parseFloat(cardEl.getAttribute('data-price') || '0') || 0,
      unitType: cardEl.getAttribute('data-unit_type') || '',
      stock: parseInt(cardEl.getAttribute('data-stock') || '0', 10) || 0,
      unavailable: (cardEl.getAttribute('data-unavailable') || '0') === '1'
    };
  };

  window.VariationState = {
    store: {}, // id -> { attrs, key, price, stock, unitType, unavailable }

    updateFromCard(cardEl){
      const id = getCardId(cardEl);
      if (!id) return;
      const attrs = getSelectedAttributes(cardEl);
      const key = buildVariantKey(attrs);
      // Expect page to update price/stock first, then we read attributes
      const data = readComputedData(cardEl);
      this.store[id] = {
        attrs, key,
        price: data.price,
        stock: data.stock,
        unitType: data.unitType,
        unavailable: data.unavailable
      };
    },

    get(id){ return this.store[id]; },

    clear(id){ delete this.store[id]; }
  };
})();