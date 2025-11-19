/**
 * Shared client-side validation for Add Product/Item forms.
 * Targets:
 * - Supplier: '#addProductModal form'
 * - Admin: '#addInventoryModal form'
 */
(function(){
  function showFieldError(input, message){
    if (!input) return;
    input.classList.add('is-invalid');
    const err = input.nextElementSibling;
    if (err && err.classList && err.classList.contains('error-text')) {
      err.textContent = message || 'Invalid input';
      err.style.display = 'block';
    } else {
      const span = document.createElement('div');
      span.className = 'error-text';
      span.textContent = message || 'Invalid input';
      input.parentElement && input.parentElement.appendChild(span);
    }
  }
  function clearFieldError(input){
    if (!input) return;
    input.classList.remove('is-invalid');
    const err = input.nextElementSibling;
    if (err && err.classList && err.classList.contains('error-text')) {
      err.textContent = '';
      err.style.display = 'none';
    }
  }

  function validateCommon(form){
    let valid = true;
    var name = form.querySelector('[name="name"]');
    var sku = form.querySelector('[name="sku"]');
    var unitPrice = form.querySelector('[name="unit_price"]');
    var quantity = form.querySelector('[name="quantity"]');
    var reorder = form.querySelector('[name="reorder_threshold"]');
    var category = form.querySelector('[name="category"]');
    var location = form.querySelector('[name="location"]');

    [name, sku, unitPrice, quantity, reorder, category, location].forEach(clearFieldError);

    if (name && name.value.trim() === '') { showFieldError(name, 'Product name is required.'); valid = false; }
    if (sku && !/^[A-Za-z0-9_-]{2,}$/.test(sku.value.trim())) { showFieldError(sku, 'SKU must be 2+ chars (letters, numbers, _ or -).'); valid = false; }
    if (category && category.value.trim() === '') { showFieldError(category, 'Category is required.'); valid = false; }
    if (location && location.value.trim() === '') { showFieldError(location, 'Location is required.'); valid = false; }

    // Numeric validations
    function isNum(v){ return v !== '' && !isNaN(v); }
    if (unitPrice && (!isNum(unitPrice.value) || parseFloat(unitPrice.value) < 0)) { showFieldError(unitPrice, 'Unit price must be a non-negative number.'); valid = false; }
    if (quantity && (!isNum(quantity.value) || parseInt(quantity.value) < 0 || !/^[0-9]+$/.test(quantity.value))) { showFieldError(quantity, 'Quantity must be a non-negative integer.'); valid = false; }
    if (reorder && (!isNum(reorder.value) || parseInt(reorder.value) < 0 || !/^[0-9]+$/.test(reorder.value))) { showFieldError(reorder, 'Reorder threshold must be a non-negative integer.'); valid = false; }

    // Unit type required (if radios/select exist)
    var unitTypeRadioChecked = form.querySelector('#unitTypeRadios input[type="radio"]:checked');
    var unitTypeSelect = form.querySelector('#unit_type');
    if (unitTypeRadioChecked === null && unitTypeSelect && unitTypeSelect.value.trim() === '') {
      valid = false;
      const ut = unitTypeSelect;
      showFieldError(ut, 'Please select a unit type.');
    }

    // Variation stock inputs must be whole numbers or blank
    var variationStocks = form.querySelectorAll('.variation-stock-input');
    variationStocks.forEach(function(inp){
      if (inp.value !== '') {
        if (!/^[0-9]+$/.test(inp.value)) { showFieldError(inp, 'Stock must be a whole number or leave blank.'); valid = false; }
      }
    });

    return valid;
  }

  function attach(form){
    if (!form || form.dataset.sharedValidationAttached === '1') return;
    form.dataset.sharedValidationAttached = '1';
    form.addEventListener('submit', function(e){
      // Avoid double-submit loops
      if (this.dataset.clientValidated === '1') { this.dataset.clientValidated = ''; return; }
      const ok = validateCommon(this);
      if (!ok) {
        e.preventDefault();
        const firstInvalid = this.querySelector('.is-invalid');
        if (firstInvalid) { try { firstInvalid.focus(); } catch(_){} }
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    var supplierForm = document.querySelector('#addProductModal form');
    var adminForm = document.querySelector('#addInventoryModal form');
    attach(supplierForm);
    attach(adminForm);
  });
})();