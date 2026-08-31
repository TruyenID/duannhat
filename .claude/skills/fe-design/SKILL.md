---
name: fe-design
description: "Run after a BE plan is shipped. Reads the completed plan and generates two design docs in godx-tempo-frontend/design/{slug}/: workflow.md (user journeys, API endpoints, state transitions) and ui.md (routes, page layout, components, React Query hooks). Invoke whenever a plan status changes to shipped or when the user asks to write the frontend design for a feature."
---

# fe-design — Frontend Design Writer

You are writing frontend design specifications for a shipped backend feature.
Do NOT write any application code. Write documentation only.

## Inputs

You need:
1. The BE plan slug (e.g. `shop-table-management`) — derive from the plan directory name
2. The BE plan docs:
   - `plans/{plan-dir}/README.md` — scope, in/out of scope, success criteria
   - `plans/{plan-dir}/TESTS.md` — all test scenarios (reveals what the API can do)
   - `plans/{plan-dir}/HANDOFF.md` (if exists) — files changed, endpoints added

## Output location

Write to: `../godx-tempo-frontend/design/{slug}/`

Three files:
- `workflow.md`
- `ui.md`
- `api.md`

## workflow.md — What to write

```markdown
---
feature: {slug}
plan: "{NNN}"
be-branch: {branch from plan README}
be-pr: {pr url if shipped}
status: draft
---

# Workflow — {Feature Title}

## User Roles
(table of roles + what each can do, derived from policy/authorization in TESTS.md)

## User Journeys
(numbered steps for each main user action — match test scenarios)

## API Endpoints
(table: Method | Path | Usage — list every endpoint the tests hit)

## State Transitions
(if any status/availability/enum changes — show as flow diagram)
```

## ui.md — What to write

```markdown
---
feature: {slug}
plan: "{NNN}"
status: draft
---

# UI & Architecture — {Feature Title}

## Routes
(table: Route | Page component | Description)

## Page Layout
(ASCII tree of the page structure — header, toolbar, main content, sub-components)

## Components
(table: Component name | Responsibility)

## Data Fetching (React Query)
(hook names + which endpoint each calls — one hook file per domain)

## Notes
(anything FE dev needs to know: libraries used, role guards, edge cases)
```

## api.md — What to write

```markdown
---
feature: {slug}
plan: "{NNN}"
status: draft
---

# API Reference — {Feature Title}

## Authentication
(Bearer token via Sanctum, standard 401 on unauthenticated)

## Roles & Permissions
(table per endpoint group: which roles can do what, 403 cases)

## Enums
(any enum used in this feature: values + Vietnamese labels)

## {Resource} Endpoints
(one section per resource group, each endpoint with:)
  - HTTP method + full path
  - Query params table (param | type | description)
  - Request body JSON example + Validation table (field | rules)
  - Response JSON example (200/201/204)
  - Notable error cases (422, 403, 404)
```

**Sources for api.md:**
- Routes: from `TESTS.md` HTTP method + path patterns
- Validation rules: from `TESTS.md` validation scenarios (422 cases)
- Auth/roles: from `TESTS.md` authorization scenarios (401/403 cases)
- Response shape: from `TESTS.md` side-effects + happy-path assertions
- Enums: from `HANDOFF.md` or schema files referenced in the plan

## Rules

- Derive user journeys directly from `TESTS.md` happy-path scenarios
- Derive API endpoints from `TESTS.md` HTTP assertions (status codes, routes)
- Derive roles from `TESTS.md` authorization test scenarios (401/403 cases)
- Derive validation rules from `TESTS.md` validation scenarios (422 cases)
- Derive response shape from `TESTS.md` side-effect assertions (`assertDatabaseHas`, JSON structure checks)
- Component names use PascalCase
- Hook names use camelCase starting with `use`
- Do not invent endpoints — only list what exists in BE tests
- If a file already exists in `../godx-tempo-frontend/design/{slug}/`, read it first and update rather than overwrite
- After writing all three files, report the paths created and a one-line summary of each
