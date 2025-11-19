# Admin Inventory Cache

Purpose:
- Track admin-side inventory and variation price changes with version numbers.
- Provide an admin/staff-only record of committed changes.

Files:
- `versions.json`: Map of `inventory_id -> latest_version`.
- `changes/`: Per-inventory JSON files, e.g. `inventory_123.json`, containing last committed state.

Access restrictions:
- Only `$_SESSION['admin']` or `$_SESSION['staff']` features may write/read here.
- Supplier pages must not access or depend on these files.