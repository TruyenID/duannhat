# web/customer — web cho KHÁCH (Next.js)

Khách quét QR ở bàn hoặc mở link của quán: xem menu, đặt món, gọi nhân viên,
thanh toán. Gọi thẳng Cloud backend qua Internet — **không** đi qua workstation.

Nằm trong monorepo TempoFast tại `web/customer/`. Luật khi sửa code:
`AGENTS.md` cùng thư mục. Kiến trúc toàn hệ: `CLAUDE.md` ở gốc repo.

## Chạy

```sh
cd web/customer
pnpm install          # app này có lockfile RIÊNG — `pnpm install` ở gốc KHÔNG cài nó
pnpm dev              # → http://localhost:5450
pnpm build
pnpm test             # node --test trên lib/**/*.test.ts + messages/**/*.test.ts
```

Hoặc từ gốc repo: `pnpm dev:customer`. Backend phải chạy riêng
(`docker compose up -d` → `:5400`).

**Cổng là 5450**, không phải 3000 — `next dev -p 5450 -H 0.0.0.0`. `-H 0.0.0.0`
để mở được từ điện thoại trong cùng LAN lúc thử QR.

## Bố cục

```
app/[locale]/        route theo ngôn ngữ: menus · menuorder · dine-in · order ·
                     checkout · booking · account · login …
app/api/             route handler phía Next
lib/                 gọi API, tiện ích — có test node:test đi kèm
messages/            ja · en · vi, kèm test PARITY giữa ba file
```

`messages/` có ba bài test tự canh, không chỉ là file dịch:
`messages.parity.test.ts` (ba ngôn ngữ phải cùng bộ khoá),
`no-account-enumeration.test.ts` (thông điệp đăng nhập không được tiết lộ email
nào tồn tại), `no-config-jargon.test.ts` (không rò thuật ngữ cấu hình ra mặt
khách). Thêm khoá mà quên một ngôn ngữ là đỏ.

## Ngôn ngữ

ja (mặc định) · en · vi. Route mang locale ở đoạn đầu (`/[locale]/…`).

## Lưu ý

- **Không phải workspace member**: `pnpm-workspace.yaml` ở gốc chỉ liệt
  `packages/*`. Phải `pnpm install` bên trong app, hoặc `pnpm install:all` ở gốc.
- **Không có script `typecheck` riêng** — `pnpm typecheck` ở gốc chạy
  `tsc --noEmit` giúp app này.
- Deploy hiện **ĐỨT** cho tới khi trỏ lại Amplify sang monorepo:
  `docs/reference/deploy-web-amplify.md`.
