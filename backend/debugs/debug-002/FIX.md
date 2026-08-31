# Debug 002 — Fix

> Fix record for [Add MenuSection](README.md).

## Root cause

Menu system lacked section/category grouping. Products were flat within a menu with no way to organize them into named groups like "Appetizers", "Main Dishes", etc.

## Changes made

### Created — Schema (umbrella repo)

| File | Purpose |
|------|---------|
| `schemas/Backend/Product/MenuSection.yaml` | New MenuSection entity schema |
| `schemas/Backend/Product/MenuMenuSection.yaml` | Pivot schema for Menu <-> MenuSection N:N |

### Modified — Schema (umbrella repo)

| File | What changed |
|------|--------------|
| `schemas/Backend/Product/Menu.yaml` | Added `menuSections` ManyToMany (owning side) |
| `schemas/Backend/Product/MenuProduct.yaml` | Added `menuSection` ManyToOne (nullable, SET NULL) + index |

### Created — Backend (auto-generated)

| File | Purpose |
|------|---------|
| `database/migrations/omnify/*_create_menu_sections_table.php` | menu_sections migration |
| `database/migrations/omnify/*_create_menu_menu_sections_table.php` | pivot migration |

### Created — Backend (manual, following Omnify pattern)

| File | Purpose |
|------|---------|
| `app/Omnify/Modules/MenuSection/Models/MenuSectionBaseModel.php` | Base model with relationships |
| `app/Omnify/Modules/MenuSection/Locales/MenuSectionLocales.php` | i18n (ja/en/vi) |
| `app/Omnify/Modules/MenuSection/Resources/MenuSectionResourceBase.php` | API resource base |
| `app/Omnify/Modules/MenuSection/Requests/MenuSectionStoreRequestBase.php` | Store validation base |
| `app/Omnify/Modules/MenuSection/Requests/MenuSectionUpdateRequestBase.php` | Update validation base |
| `app/Models/MenuSection.php` | Editable model |
| `app/Http/Resources/MenuSectionResource.php` | Editable resource |
| `app/Http/Requests/MenuSectionStoreRequest.php` | Editable store request |
| `app/Http/Requests/MenuSectionUpdateRequest.php` | Editable update request |
| `database/factories/MenuSectionFactory.php` | Test factory |
| `app/Http/Controllers/Api/V1/HQ/MenuSectionController.php` | CRUD controller + Swagger |
| `app/Services/Product/MenuSectionService.php` | Service layer |

### Modified — Backend

| File | What changed |
|------|--------------|
| `app/Omnify/Modules/Menu/Models/MenuBaseModel.php` | Added `menuSections()` BelongsToMany |
| `app/Omnify/Modules/Menu/Resources/MenuResourceBase.php` | Added menuSections to schemaArray |
| `app/Omnify/Modules/MenuProduct/Models/MenuProductBaseModel.php` | Added `menu_section_id` fillable + `menuSection()` BelongsTo |
| `app/Omnify/Modules/MenuProduct/Resources/MenuProductResourceBase.php` | Added menu_section_id + menuSection |
| `app/Omnify/Modules/MenuProduct/Requests/MenuProductStoreRequestBase.php` | Added menu_section_id rule |
| `app/Omnify/Modules/MenuProduct/Requests/MenuProductUpdateRequestBase.php` | Added menu_section_id rule |
| `app/Omnify/Modules/MenuProduct/Locales/MenuProductLocales.php` | Added menu_section_id locale |
| `app/Services/Product/MenuService.php` | Load menuSections in findById |
| `app/Http/Controllers/Api/V1/HQ/MenuController.php` | Added syncSections endpoint |
| `routes/api/hq/menus.php` | Added menu-sections CRUD + menus/{menu}/sections sync routes |

### Test

| File | Purpose |
|------|---------|
| `tests/Feature/Product/MenuSection/MenuSectionCrudTest.php` | Full CRUD, N:N sync, backward compat |

## API Endpoints Added

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/hq/{brand}/menu-sections` | List sections |
| POST | `/api/v1/hq/{brand}/menu-sections` | Create section |
| GET | `/api/v1/hq/{brand}/menu-sections/{id}` | Show section |
| PUT | `/api/v1/hq/{brand}/menu-sections/{id}` | Update section |
| DELETE | `/api/v1/hq/{brand}/menu-sections/{id}` | Delete section |
| PUT | `/api/v1/hq/{brand}/menus/{menu}/sections` | Sync sections for a menu |

## Verification

- [ ] Run `composer install` (vendor is empty)
- [ ] Run `php artisan migrate` to create new tables
- [ ] Run `php artisan test --compact --filter=MenuSection` to verify tests
- [ ] Run `vendor/bin/pint --dirty --format agent` for code style
- [ ] Run full test suite: `php artisan test --compact`

## Follow-ups

- When Omnify PHP codegen is enabled for workspace mode, regenerate to replace manually created base files
- Consider adding MenuSection to workstation sync API (`/api/v1/workstation/sync/pull`)
- Consider adding MenuSection to shop menu routes if branch-level customization is needed
