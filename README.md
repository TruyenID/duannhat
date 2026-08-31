# TempoFast — monorepo

Dev orchestration cho TempoFast. Repo này chứa toàn bộ application code + Docker stack + Omnify schemas:

| Path | Stack | Notes |
|---|---|---|
| `backend/` | Laravel 13 / PHP 8.4 | In-tree (absorbed from `godx-jp/godx-tempo-backend` 2026-04-15, archived) |
| `web/admin/` | Next.js 16 | HQ/Shop admin (`:5430`). In-tree (absorbed from `godx-jp/godx-tempo-frontend` 2026-04-15, archived) |
| `web/customer/` | Next.js 16 | Customer-facing ordering (`:5450`). In-tree |
| `web/pos/` | React 19 + Vite | POS terminal UI (`:5440`). Talks to workstation over LAN |
| `workstation/` | Go 1.25 + Wails v3 | Per-restaurant LAN gateway + offline cache |
| `app/tms/` | Expo 54 / React Native | Table-management terminal |
| `app/kiosk/` | Expo 54 / React Native | Self-service kiosk app |
| `app/kds/` | Vite + React 19 | Kitchen display (`:5460`) |
| `app/handy/` | Expo 56 / React Native | PDA order-taking app for floor staff |
| `schemas/` | Omnify YAML | Single source of truth for migrations + types |
| `tools-src/` | Go MCP servers | Git-ignored standalone clone of [`godx-jp/godx-tools`](https://github.com/godx-jp/godx-tools) (private) |

## Clone

```sh
git clone https://github.com/godx-jp/godx-tempo.git tempo
cd tempo
```

**Không còn submodule nào** (#2306, hoàn tất 2026-08-09): mọi app đều nằm thẳng
trong cây. Bảy repo con cũ được archive read-only trên GitHub và vẫn tra được
bằng `gh api repos/<repo>/commits/<sha>` — ref lúc gộp ghi trong
[`docs/reference/deploy-web-amplify.md`](docs/reference/deploy-web-amplify.md).

## Run

```sh
docker compose up -d
```

Stack mặc định ~260 MB RAM: backend (Laravel) + mysql + minio + mailpit. **Admin web (Next.js) KHÔNG chạy mặc định** — dev workflow khuyến nghị là `cd web/admin && pnpm dev` native trên host (HMR mượt hơn nhiều, đặc biệt trên Windows). Xem [`docs/guide/setup-docker.md`](docs/guide/setup-docker.md) để biết lý do.

Nếu không muốn cài Node trên host, bật profile `admin-web`:

```sh
docker compose --profile admin-web up -d
```

## Sửa code — mọi app, cùng một cách

Edit + commit thẳng vào repo. Không còn pointer dance ở đâu cả:

```sh
# ví dụ: thêm migration backend
cd backend
php artisan make:migration create_foo_table
# ... edit ...
cd ..
git add backend
git commit -m "feat(backend): add foo migration"
git push
```

Cross-cutting changes (schema → backend regen → admin-web regen) gói trong **một commit duy nhất**:

```sh
# edit schemas/Backend/Foo/Bar.yaml
npm run omnify:gen
git add schemas backend web/admin
git commit -m "feat: add Bar schema + regen"
```

## History

`backend/` hấp thụ bằng subtree nên giữ nguyên history: `git blame backend/app/Models/Product.php` walk back qua mọi commit pre-absorption.

**Bảy app gộp ngày 2026-08-09 thì KHÔNG kéo history** (quyết định chủ dự án — tránh phình repo): `web/{admin,customer,pos}`, `app/{workstation,tms,kiosk,kds}` vào cây dưới dạng snapshot. History cũ sống ở repo archive, tra bằng:

```sh
gh api repos/godx-jp/godx-tempo-admin-web/commits/<sha>
gh browse -R godx-jp/godx-tempo-admin-web -c <sha>
```

Ref lúc gộp của từng app ghi trong [`docs/reference/deploy-web-amplify.md`](docs/reference/deploy-web-amplify.md).
