---
title: "ADR 0002 — Đồng bộ danh tính Platform → Tempo bằng outbox + SNS/SQS, hợp đồng SCIM/CAEP"
category: contributing
tags: [architecture, adr, sso, platform, identity, provisioning, events, outbox, sns, sqs, scim, caep]
summary: Bỏ mô hình "kéo lúc đăng nhập" giữa Platform (dxs-platform/platform) và Tempo; thay bằng transactional outbox trên Platform + SNS fanout + SQS per-consumer, hợp đồng theo SCIM 2.0 (danh mục) và OpenID SSF/CAEP (thu hồi quyền). Ghi lại vì sao KHÔNG Kafka, vì sao KHÔNG tự dựng broker trên VPS, và vì sao phần khó nằm ở việc bắt cho hết đường ghi phía Platform chứ không ở transport.
related: [0001-modular-monolith, branch-isolation, api-as-boundary, pos-web-cloud-auth]
---

# ADR 0002 — Đồng bộ danh tính Platform → Tempo

- **Status**: **Accepted** (2026-08-17) — **amended 2026-08-17** (chốt chỗ ở cho
  Terraform, [#3103](https://github.com/godx-jp/godx-tempo/issues/3103)). Đang
  có hiệu lực. Mã mâu thuẫn với bản ghi này là lỗi của mã, hoặc là một ADR cần
  supersede bản ghi này.
- **Date**: 2026-08-17
- **Ratified by**: **chủ dự án (Duong), 2026-08-17.** Hai bước, cố ý tách:
  trước hết chốt **hướng đi** — kiến trúc phải theo *chuẩn quốc tế*, chấp nhận
  hạ tầng managed (SNS/SQS/Kafka) hoặc máy chủ riêng, **không chấp nhận giải
  pháp chắp vá quanh ràng buộc của XServer**; sau đó, đọc bản ghi này rồi ký
  cho **lựa chọn kỹ thuật** (outbox + SNS/SQS, hợp đồng SCIM/CAEP). Bản nháp
  giữ `Proposed` cho tới đúng chữ ký này — [ADR
  0001](0001-modular-monolith.md) §1c tồn tại vì một session agent từng đóng
  dấu `Accepted` mà không có người ký, và một luật sinh ra trong lỗ hổng đó đã
  chạy tới production.
- **Context issue**: [#3095](https://github.com/godx-jp/godx-tempo/issues/3095)
- **Supersedes**: —

## Context

Platform (**`dxs-platform/platform`** — checkout cục bộ ở `../id`, đừng suy tên
repo từ tên thư mục) là nguồn chân lý của user · tổ
chức · brand · chi nhánh · vai. Tempo giữ một **bản sao dẫn xuất**. Hôm nay bản
sao đó được cập nhật bằng bốn kênh, tất cả đều HTTP đồng bộ:

| Kênh | Chiều | Khi nào | Hỏng thì sao |
|---|---|---|---|
| OIDC code flow → `App\Sso\UserProvisioner` (3 lần gọi `/api/sso/{organizations,brands,branches}`) | Tempo kéo | **Chỉ khi có người đăng nhập** | **fail-safe** — nuốt `Throwable` thành `Log::warning`, login vẫn đi tiếp |
| `PermissionClient` → `/api/sso/me/permissions` | Tempo kéo | Mỗi `Gate::allows()` cho ability thuộc `config/authz.php`, cache 300s theo hash token | **fail-closed** — `SsoException` ⇒ deny |
| `dxs:sync-authz` / `service:sync-authz-manifest` | Tempo đẩy | Lúc deploy | Deploy đỏ |
| OIDC Back-Channel Logout | Platform đẩy | Khi logout ở IdP | best-effort, `Http::post()` đồng bộ, **không retry** |

Ba hệ quả, đều là lỗi thật chứ không phải rủi ro lý thuyết:

1. **Platform không có cách nào báo cho Tempo biết dữ liệu đã đổi.** Đo trên cây:
   `grep -rn "Http::(post|get|put|patch)"` trên ba module `Console` · `Admin` ·
   `Core` của `../id/backend/app/` trả về **rỗng**; outbound duy nhất là
   `App\Idp\Services\BackchannelLogoutNotifier`. Đổi vai một người trên Platform
   ⇒ Tempo biết ở **lần đăng nhập kế tiếp**, có thể là không bao giờ.
2. **Mirror hỏng trông y hệt mirror thành công.** `UserProvisioner` bắt mọi
   `Throwable` rồi `Log::warning`. Không có phép đo nào phân biệt "đồng bộ xong"
   với "đồng bộ chết ba tuần".
3. **Platform nằm trên đường nóng của mọi request Tempo.** `Gate::before` gọi
   Platform; Platform chậm ⇒ Tempo chậm, Platform sập quá 300s ⇒ Tempo **deny
   sạch** mọi thao tác HQ/Shop.

Một phương án đã bị loại bằng số học trước khi tới đây: **poll từng org theo
lịch không scale.** Ở 1000 org là 3 endpoint × 1000 = 3000 request mỗi tick
trong một worker bị chặn `--max-time=55`, tức ~55 req/s liên tục, mà ~99.9% trả
về "không có gì đổi". Danh mục tổ chức là dữ liệu **đổi rất ít, đọc rất nhiều**;
cơ chế phải trả tiền theo **số thay đổi**, không theo **số org**.

## Decision

### 1. Hợp đồng theo chuẩn có tên, không tự chế

| Nhu cầu | Chuẩn | Ghi chú |
|---|---|---|
| Đẩy org/user/role/branch xuống service | **SCIM 2.0** — RFC 7643 (schema) + RFC 7644 (protocol) | Cách ngành làm provisioning (Okta · Azure AD · Google Workspace) |
| Thu hồi quyền / kết thúc phiên gần realtime | **OpenID Shared Signals Framework + CAEP** (SET = RFC 8417) | Thay thế `BackchannelLogoutNotifier` tự chế |
| Kết thúc phiên khi logout | **OIDC Back-Channel Logout 1.0** | Đã có, giữ cho tới khi CAEP lên |
| Bao bì sự kiện | **CloudEvents 1.0** (CNCF) | `id` của nó **là** khoá idempotency |
| Ghi DB và phát event nguyên tử | **Transactional Outbox** | pattern, không phải sản phẩm |

**CAEP là thứ đang thiếu, không phải "queue".** Back-channel logout chỉ nói được
một câu ("user này logout"). CAEP nói được `session-revoked` ·
`token-claims-change` · `credential-change` · `assurance-level-change` — đúng
nhóm "quyền vừa đổi, đừng tin cache nữa" mà hệ này hiện không có cách nào diễn
đạt.

**Outbox KHÔNG phải mẹo để né broker** — nó bắt buộc *kể cả khi* đã có Kafka.
Không có nó thì "ghi DB xong" và "phát event xong" không nguyên tử: crash giữa
hai bước ⇒ mất event, hoặc phát event cho một thay đổi đã rollback.

### 2. Transport: SNS + SQS

```
Platform (../id)
  │  DB::transaction { đổi danh mục  +  INSERT identity_outbox }      ← nguyên tử
  │
  ├─ relay worker (cron) → publish lên SNS topic  godx-identity-events
  │     envelope : CloudEvents 1.0  (id, source, type, subject, time)
  │     payload  : SCIM 2.0 resource  |  CAEP Security Event Token (JWS)
  ▼
SNS topic  (ap-northeast-1)                    ── fanout
  ├─► SQS  tempo-identity-events    + DLQ (maxReceiveCount 5, retention 14d)
  ├─► SQS  <service-kế-tiếp>-…      ← thêm consumer KHÔNG sửa Platform
  └─► Firehose → S3                 ← lưu trữ + replay + 電子帳簿保存法

Tempo
  └─ cron + flock: <lệnh consumer> --max-time=55        (bước 3, chưa tồn tại)
       inbox unique(cloudevents.id) → apply idempotent
```

Chọn SNS+SQS vì đo được là nó **gần như đã sẵn sàng**:

- `aws/aws-sdk-php` **đã cài** ở Tempo (kéo theo `league/flysystem-aws-s3-v3`);
  thư mục `src/` của nó có đủ client `Sns` · `Sqs` · `EventBridge`.
- `config/queue.php` của **cả hai** app đã có sẵn stanza `'sqs'`; biến `AWS_*`
  đã có chỗ trong cả hai `.env.example`.
- Đã có tài khoản AWS (Amplify deploy web, secret `AMPLIFY_AWS_*`).
- Phía `../id` thiếu đúng một thứ: `composer require aws/aws-sdk-php`.

Region **`ap-northeast-1` (Tokyo)** — cùng nước với XServer và với nghĩa vụ lưu
trữ chứng từ.

Chi phí ở tải thật (vài chục thay đổi/ngày, **kể cả** ở 1000 org): nằm trọn
trong free tier của SNS và SQS. Ở 100× tải vẫn dưới 1 USD/tháng.

Muốn schema registry + archive/replay là tính năng gốc thì thay SNS bằng
**EventBridge**. Cả hai đều đúng; SNS đơn giản hơn, EventBridge "enterprise" hơn.
Quyết định đó lùi lại được — nó không đổi hình dạng outbox.

### 3. Đường nóng và đường bền là HAI đường, không phải một lựa chọn

- **SQS** cho danh mục (SCIM): bền, 14 ngày, mỗi consumer một con trỏ, DLQ.
  Độ trễ vài giây → ~60s.
- **SNS → HTTPS trực tiếp** vào Tempo cho thu hồi quyền (CAEP): dưới một giây.
  Message có chữ ký, verify bằng `Aws\Sns\MessageValidator`; SNS tự retry có
  backoff, thất bại thì redrive về DLQ.

Cấu hình chuẩn: **push cho độ trễ, queue cho độ bền.**

### 4. Repo nào làm gì

**~70% khối lượng nằm ở `../id`. Tempo chỉ là bên nghe.**

| Thành phần | Repo | Vì sao |
|---|---|---|
| Bảng `identity_outbox` + ghi vào nó trong mọi transaction sửa danh mục | **`../id`** | Chỉ nơi ghi mới biết cái gì vừa đổi |
| Relay worker → SNS | **`../id`** | producer |
| SNS topic · SQS · DLQ · IAM (Terraform, **không bấm tay console**) | **`../id` → `infra/`** | producer sở hữu topic (amendment 2026-08-17) |
| SQS consumer · bảng `identity_inbox` · apply | **`tempo`** | consumer |
| Gỡ mirror khỏi đường login, hạ `SSO_PERMISSIONS_TTL` | **`tempo`** | hệ quả phía tiêu thụ |

⚠️ `../id` là repo khác, deploy khác, và **omnify lock của nó đang STALE — không
bao giờ chạy generate trong `~/Herd/id`**.

### Amendment 2026-08-17 — chỗ ở cho Terraform

Bản gốc để ngỏ dòng hạ tầng ("chưa repo nào có · cần chốt chỗ ở"). Chủ dự án
chốt: **thư mục `infra/` trong repo Platform**, **không** tách repo riêng.

Lý do là quyền sở hữu, không phải tiện tay: Platform **publish**, nên topic
thuộc về Platform. Consumer chỉ đăng ký vào — và thêm một consumer về sau
**không sửa gì phía producer**, đúng thứ SNS fanout mua cho chúng ta. Đặt
Terraform ở repo consumer sẽ lật ngược quan hệ đó ngay từ dòng đầu tiên.

Khung đã dựng: `infra/identity-events` (root module) —
[dxs-platform/platform#799](https://github.com/dxs-platform/platform/issues/799).

**Ba thứ còn chặn `terraform apply`** — và chỉ chặn `apply`, không chặn review:

1. **State backend** chưa có. Apply với state cục bộ là cách nhanh nhất để hai
   người dựng hai bản hạ tầng khác nhau mà không ai biết.
2. **Account + region** cần xác nhận (ADR đề xuất `ap-northeast-1`).
3. **Ai chạy `apply`** — qua CI với OIDC role, hay một người có credential?

Một điều kiện nữa, không thuộc Terraform nhưng phải xong **trước khi bật luồng
thật**: **cảnh báo trên DLQ**. Một DLQ không ai nhìn chỉ là chỗ message đi chết
yên lặng — nó biến "mất event" từ sự cố ồn ào thành sự cố im lặng, đúng loại
hỏng mà cả ADR này sinh ra để chống.

## Phần khó KHÔNG phải transport — mà là bắt cho hết đường ghi

Đây là chỗ dự án sẽ mất tiền nếu làm ẩu, nên nó dài hơn phần chọn broker.

Tempo mirror năm nhóm trường (đọc từ `App\Sso\UserProvisioner`):

| Thực thể | Trường được mirror |
|---|---|
| Organization | `name` · `slug` · `is_active` · `operating_country` (từ `country`) |
| Brand | `slug` · `name` · `description` · `logo_url` · `is_active` |
| Branch | `code` · `slug` · `name` · `is_headquarters` · `is_active` · `timezone` · `currency` · `locale` · `console_brand_id` |
| Gán vai | `service_role` · `service_role_level` · `all_branches_access` · danh sách `branch_ids` |
| User | `sub` · `name` · `email` · `is_active` |

Năm nhóm đó được ghi từ **ba bề mặt khác nhau** trên Platform:

1. **`/api/admin/*`** — nhân sự Platform: `organizations` (store · update ·
   destroy · lifecycle) · `branches` (+restore) · `brands` (+restore) ·
   `organization-roles` (`PUT members/{user}/roles`) · `users` (store · update ·
   destroy · suspend · reactivate · revokeGrant · revokeSession).
2. **`/api/console/*`** — **người của chính tổ chức đó**: `organization` (update ·
   settings · closure · invite · role · destroy) · `organization-branches` ·
   `organization-brands` · `organization-teams` (+members) ·
   `organization-service-access` (grant/revoke user · team · org-roles) ·
   `invitations` (accept/decline — chấp nhận lời mời **tạo ra** một thành viên).
3. **Không qua HTTP chút nào** — `BetoyaProductionSeeder` và khối
   `ServiceUserAccess::updateOrCreate` chạy bằng `artisan tinker` trong
   `.github/workflows/deploy-xserver.yml`, tức **mỗi lần push `main`**.

**Đừng ghim số lượng endpoint vào bản ghi này.** Bản nháp đầu có ghim, và con số
sai ngay ở lượt sửa kế tiếp. Đếm tại chỗ:

```sh
cd ../id/backend
grep -rcE "Route::(post|put|patch|delete)" routes/api/admin routes/api/console
```

### Hệ quả: KHÔNG hook outbox ở tầng controller

Hai cây controller cộng một đường không-HTTP ⇒ móc vào controller là **chắc chắn
sót**, và sót thì im lặng. Chỗ móc đúng là **Eloquent observer trên sáu model
được mirror**, vì nó bắt được cả controller, cả seeder, cả tinker.

**Nhưng observer có một lỗ đã từng cắn repo này**: `DB::table(...)->update([...])`
đi thẳng xuống query builder, **không kích hoạt model event** — đúng cơ chế đã
làm `HongoShopConfigSeeder` đổi dữ liệu mà `menus.updated_at` không nhúc nhích,
khiến cuộc điều tra kết luận nhầm "deploy vô can". Cùng một lỗ sẽ nuốt event ở
đây.

**Và observer có một lỗ thứ hai, riêng của Platform**: `Organization` · `Branch` ·
`Brand` mỗi cái tồn tại **hai lần** — `App\Console\Models\X` **extends**
`App\Core\Models\X` (bản Console thêm `Billable` của Cashier). Laravel phát sự
kiện theo **lớp lúc chạy** — `HasEvents::fireModelEvent()` dùng
`"eloquent.{$event}: ".static::class` (`:225`), còn `observe()` đăng ký theo lớp
được gọi (`:194`). Nên observer gắn vào `App\Core\Models\Organization`
**không bao giờ nổ** cho một instance `App\Console\Models\Organization`, tức
toàn bộ đường ghi phía Console biến mất im lặng. Phải đăng ký trên **cả hai
lớp** của cả ba model.

Lưu ý thi công: Platform **hiện chưa có observer nào** (`grep -rn "observe("`
trên các service provider, và `#[ObservedBy]` đều trả về rỗng) — đây là pattern
mới ở repo đó, không phải mở rộng cái sẵn có.

Nên phải đủ **ba lớp**, không phải một:

1. **Observer** trên `Organization` · `Brand` · `Branch` (**mỗi cái hai lớp**,
   Core + Console) · `User` · `ServiceUserAccess` · `OrgServiceRole` → ghi
   `identity_outbox`.
2. **Arch test** cấm ghi thô bằng query builder vào sáu bảng đó. Rào này phải
   chứng minh được **cả hai chiều**: biết kêu khi ai đó thêm một
   `DB::table('branches')->update(...)`, và biết im khi không có.
3. **Reconciliation quét toàn bộ, hằng ngày** — so bản sao Tempo với thư mục
   Platform, báo lệch. Đây là thứ khiến kiến trúc **an toàn kể cả khi sót một
   đường ghi**, và là cách Okta/Stripe thật sự vận hành: event stream *kèm* đối
   soát, không bao giờ chỉ event stream.

Lớp 3 không phải phần thừa để cắt khi hết thời gian. Nó là lớp duy nhất phát
hiện được lỗi của hai lớp kia.

## Bất biến phải giữ

- **Sự kiện phải idempotent, khoá là CloudEvents `id`.** SQS là at-least-once —
  giao trùng là bình thường, không phải sự cố. Bảng `identity_inbox` có
  `unique(event_id)`; apply đã idempotent sẵn (`updateOrCreate` +
  `syncRoleScopes` xoá-dựng-lại).
- **Thứ tự không được giả định.** SNS/SQS chuẩn không bảo toàn thứ tự. Mỗi event
  mang `resource_version` (hoặc `occurred_at`); đến muộn hơn phiên bản đang có
  thì **bỏ**, đừng ghi đè. Cần thứ tự tuyệt đối thì dùng SQS FIFO theo
  `MessageGroupId = organization_id` — trả giá bằng throughput, ở tải này không
  đáng lo.
- **Job nền phải NÉM, không được nuốt.** `UserProvisioner` hiện nuốt `Throwable`
  thành `Log::warning`. Bê nguyên hành vi đó vào consumer là làm mirror hỏng
  thành **hoàn toàn vô hình** — không còn cả người dùng để phàn nàn. Consumer
  ném ⇒ SQS retry ⇒ DLQ.
- **Đừng đặt tên queue Laravel mới.** Danh sách queue nằm trong **crontab prod,
  không trong repo**; job vào queue không ai rút thì nằm im vĩnh viễn và **không
  vào `failed_jobs`**, nên không cảnh báo nào kêu. Consumer SQS là artisan
  command riêng nên né được — miễn là dòng cron được nối **cùng lượt deploy**.
- **`branch_id IS NULL` nghĩa là MỌI chi nhánh** (`all_branches_access`), không
  phải "không chi nhánh nào". Event mang phạm vi chi nhánh phải giữ đúng ngữ
  nghĩa này; tầng thông báo đã một lần vi phạm nó (#2460).
- **Vai vẫn là bản sao dẫn xuất.** ADR này đổi *cách* bản sao được cập nhật,
  **không** đổi ai sở hữu nó. Gán vai thẳng vào DB Tempo vẫn vô nghĩa.

## XServer không chặn kiến trúc này

Ràng buộc thật của XServer là **không host được tiến trình dài** (đúng lý do
Reverb chưa chạy được). Nó **không** chặn việc *tiêu thụ* từ một broker managed:

```cron
* * * * * flock -n /tmp/tempo-identity.lock \
  /opt/php-8.4/bin/php /home/famgia/apps/tempo/artisan <lệnh-consumer> --max-time=55
```

Lệnh consumer (dự kiến tên `platform:consume-identity`) **chưa tồn tại** — nó ra
đời ở bước 3, nên chỗ này để placeholder chứ không ghi tên thật: một chỉ dẫn
trỏ tới lệnh không có thật khiến người vận hành gõ rồi nhận
`Command "..." is not defined.` đúng lúc đang có sự cố.
`ArtisanCommandReferencesExistTest` cưỡng chế đúng điều này, và nó **đã bắt được
bản nháp đầu của chính ADR này**.

SQS long-polling (`WaitTimeSeconds=20`) khớp hoàn hảo với mô hình cron + flock +
`--max-time=55` mà các watcher queue hiện tại đã chạy.

⚠️ Khi bước 3 tạo lệnh thật, nhớ nối dòng cron **cùng lượt deploy** — danh sách
worker nằm trong crontab prod, không trong repo.

## Consequences

### Được

- Độ trễ lan truyền từ **"tới khi có người đăng nhập"** (có thể vô hạn) xuống
  vài giây (CAEP/HTTPS) tới ~60s (SCIM/SQS).
- Chi phí scale theo **số thay đổi**, không theo số org — 1 org hay 100.000 org
  cùng một hình dạng.
- Thêm service thứ hai = thêm một SQS subscription, **không sửa một dòng nào**
  bên `../id`.
- Consumer chậm hoặc chết không kéo theo ai; SQS giữ 14 ngày, mỗi consumer một
  con trỏ riêng.
- Mở đường cho việc lớn hơn: **gỡ Platform khỏi đường nóng của mọi request
  Tempo** — khi mirror đủ tươi, quyết định phân quyền đọc được từ bản sao cục bộ.

### Mất

- **Một nhà cung cấp hạ tầng mới trên đường sống của hệ** (AWS messaging). Trước
  đây Tempo chỉ phụ thuộc AWS cho lưu trữ; giờ danh tính cũng đi qua đó.
- **Ba bề mặt vận hành mới phải trông**: DLQ, độ trễ tiêu thụ, độ sâu outbox
  chưa relay. Không trông thì đây là một cách hỏng im lặng mới, tinh vi hơn cái
  cũ.
- **Nhất quán cuối (eventual consistency) trở thành chính thức.** Hôm nay bản
  sao cũng đã cũ, nhưng cũ một cách không ai thừa nhận; sau ADR này nó là hợp
  đồng, và mã đọc mirror phải chịu được việc trễ vài chục giây.
- **Khối lượng nằm ở repo mà session Tempo không kiểm soát.** Bước 1 và 3 sống
  ở `../id`; ADR này không tự thi hành được.

### Điều gì lật ngược lựa chọn transport

Kafka **không** được chọn vì sai công cụ, không phải vì giá: điểm thiết kế của
nó là 100k+ msg/s với replay là đường truy cập chính và nhiều team xây trên cùng
một log — lệch khoảng **tám bậc độ lớn** so với tải ở đây; MSK/Confluent cụm nhỏ
nhất cỡ vài trăm USD/tháng; và nó **không** cho sẵn per-consumer visibility
timeout · DLQ · retry backoff, những thứ SQS có sẵn còn Kafka bắt tự dựng bằng
consumer group + retry topic.

Lật ngược khi Tempo bắt đầu phát stream đơn hàng/thanh toán cho analytics
(≥100k event/ngày, replay để dựng lại read model), hoặc khi có nhiều team độc
lập tiêu thụ cùng một log. Lúc đó Kafka đúng — nhưng đó là **hệ event thứ hai**,
không thay thế hệ identity này.

## Alternatives considered

| Phương án | Vì sao loại |
|---|---|
| **Tự dựng broker (Kafka/RabbitMQ) trên một VPS** | Broker là hạ tầng có trạng thái, bán đảm bảo độ bền. Một VPS đơn = một điểm chết đơn, không multi-AZ, đầy đĩa là mất message, phải tự vá · tự backup · tự trực. Độ bền **thấp hơn cái MySQL đang chạy**. Làm cho ra hồn cần ≥3 node và người trực — đó mới là chắp vá, chỉ là chắp vá đắt tiền. |
| **Poll từng org theo lịch** | Không scale: 3000 req/tick ở 1000 org trong worker `--max-time=55`, ~99.9% vô ích. |
| **Delta feed có cursor (Tempo kéo một endpoint)** | Đúng về mặt scale (O(1) mỗi tick) và rẻ hơn hẳn, nhưng độ trễ sàn = chu kỳ poll và không đạt yêu cầu "chuẩn quốc tế" mà chủ dự án đã chốt. **Giữ lại làm phương án lùi** nếu bước 3 bị chặn — nó dùng chung y hệt bảng outbox ở bước 1, nên bước 1 không phí trong cả hai kịch bản. |
| **Second DB connection đọc thẳng schema Platform** | Hai app ở cùng một máy nên rất cám dỗ. Khoá chết Tempo vào bảng nội bộ của Platform và tước quyền migrate độc lập của Platform. |
| **Webhook point-to-point không có outbox** | Đúng thứ `BackchannelLogoutNotifier` đang làm: POST đồng bộ trong request, không retry. Đó là thứ đang hỏng — đừng nhân bản. |
| **Redis pub/sub** | Không cài được trên XServer, và pub/sub không bền: consumer offline là mất message. |

## Lộ trình — mỗi bước tự đứng được

1. **Outbox trên Platform** (`../id`): bảng + observer + arch test cấm ghi thô.
   **Chưa cần AWS.** Bước khó nhất và không thể bỏ; cũng là bước dùng chung với
   phương án lùi.
2. **Reconciliation quét toàn bộ** (Tempo): lệnh so bản sao với thư mục, báo
   lệch. Làm **trước** transport — nó là phép đo chứng minh bước 3 hoạt động.
3. **SNS + SQS + DLQ** (Terraform) + relay + consumer. Chạy **song song** với
   đường login hiện tại; chỉ tin khi bước 2 xanh liên tục.
4. **CAEP/SSF** thay `BackchannelLogoutNotifier`. Lúc này mới hạ được
   `SSO_PERMISSIONS_TTL` và mở đường gỡ Platform khỏi đường nóng.
5. **SCIM 2.0 endpoint** trên Tempo — sau cùng. Giá trị thật xuất hiện khi có
   service thứ hai, hoặc khi khách doanh nghiệp đòi cắm IdP của họ.

Bước 4 là bước duy nhất đổi hành vi thấy được cho người dùng; bước 1–3 là hạ
tầng không ai nhìn thấy nếu làm đúng.

## Cái KHÔNG làm

- Không bấm tay trên AWS console. Topic · queue · DLQ · IAM phải là code.
- Không đặt tên queue Laravel mới (xem *Bất biến* ở trên).
- Không sửa bản ghi này để phản ánh một quyết định mới. ADR đã `Accepted` thì
  viết bản mới và đánh dấu bản này `Superseded by NNNN` — một sổ quyết định bị
  sửa không còn là sổ.
