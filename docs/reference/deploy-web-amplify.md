---
title: Deploy web app lên Amplify — hồ sơ trước khi gộp monorepo
category: reference
tags: [deploy, amplify, admin-web, customer-web, pos-web, monorepo]
summary: "App id Amplify, domain production, secret, IAM và cơ chế trigger của 3 web app — ghi lại NGUYÊN VẸN trước khi archive repo con, để dựng lại đường deploy sau khi gộp monorepo."
related:
  - workstation-cloud-api
---

# Deploy web app lên Amplify

> **Trạng thái 2026-08-12 (#2393): ba web app đã nối lại đường deploy.**
> `admin-web-deploy.yml` · `customer-web-deploy.yml` · `pos-web-deploy.yml` nằm
> ở `.github/workflows/` (không còn ở `workflows-parked/`), và hai secret
> `AMPLIFY_AWS_ACCESS_KEY_ID` / `AMPLIFY_AWS_SECRET_ACCESS_KEY` đã có trong
> repo umbrella từ 2026-06-11. Bằng chứng chạy thật: job `Amplify release (pos)`
> xanh 3m45s trên PR #2496.
>
> Phần **chưa** có bằng chứng: lượt deploy thử của `admin` và `customer` — xem
> mục cuối trang.

Trang này ra đời vì một lý do rất cụ thể: **đường deploy của web app từng sống
trong repo con, và repo con bị archive** (#2306). Archive xong thì workflow ở đó
không chạy được nữa và cấu hình chỉ còn đọc được qua lịch sử. Mọi thứ cần để
dựng lại đường deploy nằm ở đây.

Bốn workflow trong `.github/workflows-parked/` còn lại là hồ sơ của các app
CHƯA nối lại (tms · kiosk · kds · workstation) — thư mục đó **không phải**
`workflows/` nên GitHub không chạy, và đó là chủ ý.

## Ba app Amplify

| App | Amplify app id | URL Amplify | Domain production | Repo nguồn (trước khi gộp) |
|---|---|---|---|---|
| admin-web | `d3cqu96a6b470f` | `https://main.d3cqu96a6b470f.amplifyapp.com` | — (dùng URL Amplify) | `godx-jp/godx-tempo-admin-web` |
| customer-web | `d3bw22hyw76201` | `https://main.d3bw22hyw76201.amplifyapp.com` | **`https://menu.vietorigin.jp`** | `godx-jp/godx-tempo-customer-web` |
| pos-web | `d3nuz12zp9crpd` | `https://main.d3nuz12zp9crpd.amplifyapp.com` | — (dùng URL Amplify) | `godx-jp/godx-tempo-pos-web` |

Tài khoản AWS: **famgia**, region **ap-northeast-1** (Tokyo).
Backend production (không phải Amplify): `https://tempo.godx.jp` — deploy bằng
`deploy-xserver.yml` ở umbrella, kích bằng tag `v*`.

## Cơ chế trigger — và cái bẫy nằm ở đây

**`autoBuild` của Amplify đang TẮT.** Push `main` KHÔNG tự build. Đường deploy
duy nhất là GitHub Actions trong repo con:

```
push main (repo con) → .github/workflows/deploy.yml
  → aws amplify start-job --app-id <APP_ID> --branch-name main --job-type RELEASE
  → chờ get-job tới SUCCEED → curl kiểm https://main.<APP_ID>.amplifyapp.com/
```

**Nhưng Actions chỉ RA LỆNH build — source code thì Amplify tự kéo từ repo mà
app Amplify đang gắn, tức repo con.** Nên gộp monorepo không chỉ là chuyển
workflow: phải **trỏ lại app Amplify sang repo umbrella**
(`aws amplify update-app --repository …`, hoặc reconnect trong console) và đặt
`appRoot`/monorepo path về `web/admin` · `web/customer` · `web/pos`. Không làm
bước này thì workflow ở umbrella vẫn chạy xanh mà Amplify build lại **bản cũ
trong repo đã archive**.

## Secret + quyền

| Thứ | Giá trị |
|---|---|
| Secret (repo con) | `AMPLIFY_AWS_ACCESS_KEY_ID`, `AMPLIFY_AWS_SECRET_ACCESS_KEY` |
| IAM user | `gha-godx-tempo-amplify` — chỉ `amplify:StartJob` + `amplify:GetJob` (+ `GetApp` cho bước kiểm URL) |
| Runner | `[self-hosted, Linux, X64]` — org không có Actions billing cho runner hosted |

Giá trị secret **không đọc lại được từ GitHub**; muốn chạy deploy từ umbrella thì
phải nhập lại 2 secret đó vào repo umbrella (người có quyền làm).

## Rào tự kiểm đáng giữ khi dựng lại

`deploy.yml` của admin-web chặn deploy nếu biến môi trường Amplify
`NEXT_PUBLIC_CUSTOMER_WEB_URL` khác `https://menu.vietorigin.jp` — đây là hàng
rào chống đúng sự cố đã xảy ra: QR đặt món tại bàn từng trỏ về
`main.d3bw22hyw76201.amplifyapp.com` thay vì domain chính thức
(godx-tempo-admin-web#55, godx-tempo-customer-web#64). Dựng lại đường deploy thì
**giữ nguyên rào này**.

## Repo cũ đã archive — tra cứu khi cần

Code đã thành in-tree (`web/admin`, `web/customer`); history KHÔNG kéo theo (cố
ý — repo umbrella không phình). Repo cũ archive read-only, vẫn tra được:

| App | Repo archive | Ref lúc gộp |
|---|---|---|
| admin-web | `godx-jp/godx-tempo-admin-web` | `2a4f351e65603b7196473614045c4a42bd30ac3b` (dev == main) |
| customer-web | `godx-jp/godx-tempo-customer-web` | `7a440e3217a1be9257a2a7cb847c826c968fb854` (dev == main) |

```sh
gh api repos/godx-jp/godx-tempo-admin-web/commits/<sha>          # một commit
gh api repos/godx-jp/godx-tempo-admin-web/commits --paginate     # duyệt lịch sử
gh browse -R godx-jp/godx-tempo-admin-web -c <sha>               # mở trên web
```

## pos-web + workstation-app (gộp đợt 2, #2312)

| App | Repo archive | Ref lúc gộp | Đường phát hành CŨ |
|---|---|---|---|
| pos-web | `godx-jp/godx-tempo-pos-web` | `0ae78ef5e7ecd41d4972dfe56a529b5874f2e477` | Amplify `d3nuz12zp9crpd` — `deploy.yml` push `main` |
| workstation-app | `godx-jp/godx-tempo-workstation-app` | `512cbc95047e6c0ce5b00f5e35aaec84a651a3ee` | **`ci.yml` nghe tag `v*`** → lint/test/build → `gh release create` với **5 binary** (quán tải về cài) |

**Workstation KHÁC ba web app**: nó không dùng Amplify. Đường phát hành là
GitHub Release trong chính repo con — archive repo = **không cắt được release
mới cho quán** cho tới khi dựng lại job đó ở umbrella (workflow nguyên văn ở
`.github/workflows-parked/workstation-app-ci.yml`). Bản đã phát hành vẫn tải
được từ repo archived.

Ràng buộc khi dựng lại ở umbrella: job release phải build từ `workstation`,
và `make posweb` lấy bundle từ `POSWEB_SRC ?= ../../web/pos` (đã chỉnh theo cấu
trúc mới).

## 3 app cuối (gộp đợt 3, #2325)

| App | Repo archive | Ref lúc gộp | Đường phát hành |
|---|---|---|---|
| tms-app → `app/tms` | `godx-jp/godx-tempo-tmt` | `5c7a71f49c3022aeab4e9cb0d2807f7d146050e1` | Expo Go (không có binary dựng sẵn) |
| godx-kiosk → `app/kiosk` | `godx-jp/godx-tempo-kiosk-app` | `116b372323433cb3c9c8d17a8a8fd0604e4d02b2` | EAS build (APK/iOS) cài lên tablet |
| godx-kds → `app/kds` | `godx-jp/godx-tempo-kds` | `de784aabe426975c262ed09ccb49e2a050a12b24` | `pnpm build` → nginx trong Docker, cổng 5460 |

Không app nào trong ba cái này gắn Amplify. Workflow test của chúng đã park ở
`.github/workflows-parked/` (tms-app-deps-guard, tms-app-tests, godx-kiosk-tests,
godx-kds-tests) — **459 test ở ba repo này chưa từng có cổng nào ở umbrella
(#1199)**, nên nối chúng vào CI là việc đáng làm ngay sau khi gộp.

Sau đợt này `.gitmodules` **không còn** — repo là monorepo hoàn toàn.

## Việc còn lại

1. Nhập 2 secret Amplify vào repo umbrella.
2. Trỏ 3 app Amplify sang repo umbrella + đặt monorepo appRoot.
3. Chuyển 4 workflow từ `.github/workflows-parked/` sang `.github/workflows/`,
   thêm `paths:` filter (`web/admin/**`, `web/customer/**`, `web/pos/**`) và
   `working-directory` tương ứng.
4. Chạy thử một deploy, thấy xanh, rồi mới bỏ hẳn hồ sơ này.

Theo dõi ở #2306.
