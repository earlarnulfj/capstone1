# Unit Type Name Field Behavior

The Unit Type management modal allows suppliers to define and manage unit types used when adding or editing products.

## Name Field (No Restrictions)
- The `Name` field accepts any string value.
- There is no validation or restriction against a predefined list of unit types.
- Example valid names: `dozen`, `pack`, `carton`, `bundle`, `roll`, `custom size`, `per 2x4 wood`.

## Normalization in UI
- Internally, names are normalized to the format `per <name>` in lowercase (e.g., `Meter` → `per meter`).
- This normalized value is used to label radios and to render variation options consistently.

## Backend Behavior
- Product save/update endpoints may still normalize or restrict `unit_type` to a fixed list.
- If a non-standard unit type is chosen during product save, the backend may fallback to `per piece`.
- This ensures existing product flows continue working even if custom unit names are used in the UI.

## Impact on Existing Functionality
- UI radios for unit types render using whatever names you create.
- Variation management and price inputs continue to work with custom names.
- Add/Edit/Delete actions in the modal still update the UI in real time and navigate back to `Add New Product`.

## Future Extension
- If you want the backend to persist new custom unit types, update the server-side validation list and data model to include them.
- Reach out to extend backend allowed unit types if needed (e.g., add `per dozen`, `per roll`).