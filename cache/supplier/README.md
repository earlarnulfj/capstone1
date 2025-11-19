# Supplier Cache (Independent)

Supplier-specific cache and tracking (if introduced) must be stored here and remain independent from admin caches.

Rules:
- Supplier pages under `supplier/` cannot read or write `cache/admin_inventory/*`.
- Admin/staff systems must not write into `cache/supplier/*`.
- Any synchronization for supplier features must go through supplier-only endpoints.