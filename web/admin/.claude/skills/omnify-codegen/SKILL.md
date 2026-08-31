---
name: omnify-codegen
description: "Invoke when working with Omnify schemas, codegen, types, services, or hooks. Covers: adding/modifying schema YAML, running omnify:gen, understanding generated files (BaseModel, BaseService, types, metadata, QueryKeys, Hooks), service.filterable/searchable config, translatable fields, and the relationship between backend ServiceBase and frontend Service/Hooks. Also invoke when user mentions 'schema', 'omnify', 'codegen', 'BaseService', 'metadata', 'service.filterable', or 'omnify:gen'."
---

# Omnify Codegen Rules

## Schema → Code Pipeline

```
schemas/*.yaml → omnify generate → {
  backend/app/Omnify/Modules/   (BaseModel, BaseService, Requests, Resources, Locales)
  frontend/src/types/models/    (Types, Zod, Metadata, Service, QueryKeys, Hooks)
  tms-app/src/types/models/     (Types, Zod, Metadata, Service, QueryKeys, Hooks — Expo target)
}
```

**Version**: omnify v3.15.1 — `npm run omnify:gen` from umbrella root.

## NEVER edit these files (auto-generated, overwritten on next gen)

```
backend/app/Omnify/Modules/*/Models/*BaseModel.php
backend/app/Omnify/Modules/*/Services/*ServiceBase.php
backend/app/Omnify/Modules/*/Requests/*RequestBase.php
backend/app/Omnify/Modules/*/Resources/*ResourceBase.php
backend/app/Omnify/Modules/*/Locales/*.php
backend/app/Omnify/Enums/*.php
frontend/src/types/models/base/*.ts
tms-app/src/types/models/base/*.ts
```

## SAFE to edit (user-land, never overwritten)

```
backend/app/Models/*.php                    ← extends BaseModel
backend/app/Services/*/*.php                ← extends ServiceBase
frontend/src/types/models/*.ts              ← extends base types
frontend/src/services/*-service.ts          ← wraps generated Service factory
frontend/src/hooks/api/use-*.ts             ← wraps generated Hooks factory
```

## Adding a new field to a schema

1. Edit `schemas/**/*.yaml` — add property
2. Run `npm run omnify:gen` from umbrella root
3. Run migration: `php artisan migrate` (Herd) + `docker compose exec app php artisan migrate` (Docker)
4. Commit backend + frontend + umbrella separately

## Schema service config

```yaml
# schemas/Shop/Zone.yaml
options:
  service:
    searchable: [name, code]           # LIKE search columns
    filterable: [is_active, branch_id] # exact-match filter columns
    defaultSort: display_order         # default ORDER BY
    eagerLoad: []                      # default with()
    eagerCount: [tables]               # default withCount()
    lookupFields: [id, code, name]     # fields in lookup() response
```

This generates:
- **Backend**: `ZoneServiceBase::list()` with filter/search/sort
- **Frontend**: `ZoneService.ts` with `ZoneFilters` interface + `toParams()`
- **Frontend**: `ZoneQueryKeys.ts` + `ZoneHooks.ts`

## Translatable fields

In schema YAML:
```yaml
properties:
  name:
    type: String
    translatable: true    # ← this flag
```

Generated code automatically:
- Backend: `syncTranslations()` in create/update
- Frontend: `translatedAttributes` in metadata, form state is `Record<Locale, string>`
- SchemaField: renders locale tab switcher automatically

## Conflict resolution for .omnify/lock.json

`.gitattributes` has `merge=ours` for `.omnify/` files. On conflict:
```bash
git checkout --ours .omnify/lock.json .omnify/schemas.json
npm run omnify:gen
git add .omnify/
```

Each machine needs once: `git config merge.ours.driver true`

## omnify:reset vs omnify:gen

- `omnify:gen` — incremental, safe, preferred
- `omnify:reset` — full rebuild, rewrites ALL migration timestamps, requires `migrate:fresh`

After reset: always `migrate:fresh` on both Herd + Docker DBs.

## Hard rules

1. **NEVER create migrations by hand** — add to schema YAML, run `omnify:gen`
2. **NEVER edit `base/*.ts`** — overwritten on next gen
3. **NEVER hardcode model namespace** — omnify resolves via config (Sylius pattern)
4. **Run both DBs** — `php artisan migrate` (Herd) + `docker compose exec app php artisan migrate` (Docker)
5. **Commit 3 repos** — backend, frontend, umbrella (schemas + .omnify/)
