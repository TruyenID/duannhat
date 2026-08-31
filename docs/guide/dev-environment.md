---
title: Môi trường dev — thêm web app mới, và coverage pcov
category: guide
tags: [dev, pnpm, workspace, test, coverage, pcov]
summary: "Sáu bước thêm một web app vào workspace pnpm, và ba quyết định cấu hình pcov không hiển nhiên (cờ tắt mặc định, ini của Herd, .so copy khỏi Cellar)."
related:
  - local-config
---

# Môi trường dev — thêm web app mới, và coverage pcov

Tách khỏi `CLAUDE.md` (#2303): hai mục này chỉ cần khi thật sự dựng app mới hoặc
đo coverage, trong khi `CLAUDE.md` nạp ở mọi phiên và mọi agent con. Lệnh chạy
hàng ngày (`pnpm dev:*`, `pest --compact`) VẪN nằm ở `CLAUDE.md`. Nguyên văn:

## Thêm một web app mới vào workspace

**Adding a new web app:**
1. Create the app directory and `package.json` with `"@tempo/eslint-config": "workspace:*"`, `"@tempo/prettier-config": "workspace:*"`, `"@tempo/tsconfig": "workspace:*"` in `devDependencies`.
2. Add the app name to `pnpm-workspace.yaml`.
3. In `eslint.config.*`: register all plugins globally (no `files` restriction) before spreading `...tempoConfig` — see `web/pos/eslint.config.js` as the Vite+React reference, `web/admin/eslint.config.mjs` as the Next.js reference.
4. In `.prettierrc.mjs`: `import sharedConfig from "@tempo/prettier-config"; export default { ...sharedConfig, plugins: [...] }`.
5. In `tsconfig.json`: `"extends": "@tempo/tsconfig/nextjs.json"` (add `include`/`exclude` locally — do NOT put them in the shared config).
6. Run `pnpm install` from root.

**Important:** `@tempo/eslint-config` is **rules-only**. It does not register plugins. Each app must register all plugins it uses globally (not scoped inside a `files` block) so that the shared rules can reference them from separate config objects.


## Coverage — pcov

**Coverage: `composer test:coverage`** (chấp nhận đường dẫn, ví dụ
`composer run test:coverage -- tests/Feature/Print`).

Driver là **pcov**, cài 2026-08-07. Ba quyết định đáng biết vì chúng không hiển
nhiên và sẽ tốn thời gian dựng lại:

- **`pcov.enabled=0` mặc định.** Suite này chạy liên tục nên không trả phí
  instrument cho mọi lượt chạy chỉ để thỉnh thoảng đo một lần. Đã đo: bật ext mà
  tắt cờ thì 17.29s → 17.05s, tức không tốn gì. `test:coverage` tự thêm
  `-d pcov.enabled=1`.
- **Cấu hình nằm ở `~/Library/Application Support/Herd/config/php/84/zz-pcov.ini`,
  KHÔNG phải `php.ini`.** PHP của Herd không nạp `php.ini` nào
  (`Loaded Configuration File => (none)`); nó QUÉT thư mục đó. File riêng ở đấy
  sống sót qua các bản nâng cấp Herd.
- **`pcov.so` được COPY ra khỏi `/opt/homebrew/Cellar/php@8.4/8.4.23/...`.**
  Đường dẫn Cellar ghim theo phiên bản và sẽ gãy khi brew nâng PHP. Bản dùng
  thật nằm cạnh ini, trong `extensions/`.

Nếu `pecl install pcov` báo `pcre2.h not found`: `CPPFLAGS="-I$(brew --prefix pcre2)/include" pecl install pcov`.
