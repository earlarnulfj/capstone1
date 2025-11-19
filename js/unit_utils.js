(function(){
  function isNails(name, category){
    const n = (name||'').toLowerCase();
    const c = (category||'').toLowerCase();
    return n.includes('nail') || c.includes('nail');
  }
  function getAutoUnitType(name, category){
    const n = (name||'').toLowerCase();
    const c = (category||'').toLowerCase();
    if (n.includes('nail') || c.includes('nail')) return 'per kilo';
    if (n.includes('paint') || c.includes('paint')) return 'per gallon';
    if (n.includes('cement') || n.includes('sand') || n.includes('gravel')) return 'per bag';
    if (n.includes('wire') || n.includes('rope')) return 'per meter';
    if (n.includes('tile') || n.includes('sheet') || n.includes('plywood')) return 'per sheet';
    return 'per piece';
  }
  function getSizeFactor(size){
    const map = { '1.5mm': 0.9, '2mm': 0.95, '2.5mm': 1.0, '3mm': 1.1, '4mm': 1.25, '5mm': 1.4 };
    return map[size] ?? 1.0;
  }
  function computeEffectivePrice(basePrice, name, category, unitType, size){
    let price = +basePrice || 0;
    if (isNails(name, category) && unitType === 'per kilo' && size){
      price = price * getSizeFactor(size);
    }
    return +price;
  }
  window.unitUtils = {
    isNails,
    getAutoUnitType,
    getSizeFactor,
    computeEffectivePrice
  };
})();