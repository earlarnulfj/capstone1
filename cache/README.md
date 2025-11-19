# Cache and Change Tracking (Decoupled)

This directory contains cache and change-tracking data used by admin and staff features only.

Separation policy:
- Admin-side cache lives under `cache/admin_inventory/` and records versioned changes to inventory and variation prices.
- Supplier-specific data must live under `cache/supplier/` (if needed) and MUST NOT read or write admin cache files.
- Supplier-facing pages under `supplier/` are prohibited from consuming any data from `cache/admin_inventory/`.
- Access is enforced in PHP endpoints using session role checks. Admin/staff only.

Do not introduce direct coupling between `admin/*` and `supplier/*` via shared caches.