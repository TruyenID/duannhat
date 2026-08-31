---
name: omnify-regen
description: "Quy trình regen Omnify + các lỗi generator đã biết (trả giá bằng máu). Gọi TRƯỚC khi tạo bảng / sửa bảng DB, sửa schemas/*.yaml, thêm cột, thêm/sửa migration, hay chạy omnify:gen | omnify:diff | omnify:reset. Dùng khi người nói regen, codegen, generator, omnify, schema YAML, migration bị nuốt, hoặc khi diff sau regen trông bất thường."
---

## Codegen Workflow

```sh
npm install              # FIRST — see below
npm run omnify:gen       # = omnify generate — sinh BỔ SUNG. Đường DUY NHẤT.
npm run omnify:diff      # preview, không write
```

**`omnify reset` / `npm run omnify:reset` — CẤM TUYỆT ĐỐI** (ruling chủ dự án
2026-08-15). Sản phẩm **đã release**, đang chạy tiền thật ở nhiều quán; script
là `omnify reset -y`, cờ `-y` tự xác nhận nên không có bước hỏi lại nào chặn
tay bạn. Nó dựng lại toàn bộ chứ không sinh bổ sung. Nếu bạn thấy mình muốn
reset, thứ bạn cần là `omnify:diff` để hiểu vì sao `generate` chưa ra kết quả
mong đợi.

**`npm install` trước khi regen, không phải tuỳ chọn.** `node_modules` lệch so
với `package-lock.json` nghĩa là chạy một generator **cũ hơn** cái repo pin, và
generator cũ **ghi đè code đã đúng thành sai** — im lặng, lẫn trong một đống
file generated khác. Đã xảy ra: node_modules giữ 5.9.10 trong khi lock pin
5.9.13, regen đảo thứ tự `dropIndex`/`dropColumn` của một migration vốn đúng,
migration chết → **mọi test chạm DB đều đỏ** (migration chạy trước mỗi test).
`npx omnify version` phải khớp với lock trước khi tin bất cứ output nào.

**Giờ đã có rào, không còn phải nhớ (#1267).** `omnify:gen` / `omnify:diff` /
`omnify:reset` đều chạy `omnify:check` trước và **dừng** nếu bản đang cài khác
bản mà `package-lock.json` ghim — **cả cũ hơn lẫn mới hơn** (#1495). Quy tắc này
từng được viết ra rõ ràng ở đây và **vẫn trượt**: một session chạy `npm install`,
rồi dependency được nâng **sau đó** bởi session khác — điều kiện đổi giữa lúc đọc
quy tắc và lúc chạy lệnh.

Rào so với **lock**, không so với dải `package.json`: `^5.9.18` thoả cả 5.9.19,
mà 5.9.19 đổi hai trong năm bẫy regen liệt kê bên dưới — tức hai session regen
bằng hai bản khác nhau, **cả hai đều qua cổng**, và diff đọc được không nói lên
bản nào sinh ra nó. Dải chỉ còn là phương án dự phòng khi không đọc được lock.
Chính hướng "mới hơn" này từng lọt, nên nó được ghim bằng test:
`npm run test:omnify-check`.

**Chạy `pint --dirty` TRƯỚC khi đọc diff regen — nếu không bạn sẽ đọc sai (#1314).**
Regen ghi ~650 file backend, nhưng gần như toàn bộ là churn mà pint đảo lại ngay:
generator phát `static::CONST`, fixer `self_static_accessor` của pint đưa về
`self::`. Đọc diff trước pint thì thấy 650 file "lệch" và kết luận sai rằng code
generated đã cũ; chạy `vendor/bin/pint --dirty` xong thì còn **đúng 5 file**. Nói
cách khác `generate && pint` gần như idempotent — và chỉ khi biết vậy thì lời
khuyên "đọc kỹ diff" mới làm được trong thực tế.

**Đọc kỹ diff sau regen — đừng commit nguyên cụm.** Regen chạm nhiều repo và
thường cuốn theo cả những thứ đang nợ của người khác. `backend/docs/contributing/
omnify-architecture.md` thì **luôn revert**: nó generated nhưng nhúng đường dẫn
tuyệt đối của máy người chạy.

### 5.9.20 + 5.9.21 đổi những gì (#2371, đo 2026-08-09)

Nâng từ 5.9.18 lên 5.9.20 sinh **231 file lệch** mà KHÔNG đổi một dòng YAML nào.
Ba thay đổi, tất cả đều là generator sửa lỗi của chính nó:

1. **Tên trường FK trong TS chuyển từ tên QUAN HỆ sang tên CỘT** —
   `taxType_id` → `tax_type_id`, `customerOrder_id` → `customer_order_id`,
   `masterSection_id` → `master_section_id`. 204 file TS (admin · tms · kiosk).
   Bản cũ **sai**: API luôn serialise tên cột, nên trường generated chưa bao giờ
   khớp payload. **Quan hệ vẫn camelCase** (`masterMenu`) — chỉ FK đổi.
   Hệ quả: 4 lỗi typecheck ở admin, và cả bốn đều là **lỗi lúc chạy có sẵn** mà
   kiểu sai đang che (`it.productSku_id` luôn `undefined` nên một ternary luôn
   rẽ nhầm nhánh). Sau khi nâng thì mọi workaround viết tay đè lên tên FK trở
   thành **legacy — xoá, đừng giữ** (#2188).

2. **`$timestamps` của model khớp cột thật của migration.** Bảy pivot chuyển
   `false → true` (bảng CÓ `created_at`/`updated_at`), và `InvoiceCounter` đi
   chiều ngược — resource GỠ `created_at` vì bảng không có. **Kiểm từng cái
   bằng migration trước khi tin**, đừng cho là generator luôn đúng.

3. **Output đã tất định, và `reset` tự chạy Pint.** Hai lượt sinh liên tiếp cho
   ra migration giống hệt từng byte — migration circular-FK trước đây đảo thứ tự
   khối mỗi lượt thì nay đổi **một lần** rồi đứng yên. Nên một diff migration sau
   khi nâng là thật, không phải churn.

**5.9.21 (cùng ngày) vá NỐT BỐN LỖI** đã báo — đo lại từng cái, không tin
release note:

| vá | phép đo trước → sau |
|---|---|
| `reset` dọn thật (omnify-go#155) | `.omnify/versions/` **136 file / 44 MB → 1 file / 472 KB**; `lock.version` 139 → 1; `changes` 4 → 224 |
| `withPivot` dùng tên CỘT (#156) | `withPivot('display_order', 'tax_type')` → `…, 'tax_type_id')` |
| hết `use` trùng (#157) | `UserPolicyBase.php`: 2 → **1**, và không tái phát sau `gen` |
| pivot `$table` khớp migration (#158) | 6 model trỏ bảng không tồn tại → **11/11 khớp** |

⚠️ **Bẫy #6 CHẾT TỪ 5.9.21** — bỏ bước `git checkout -- UserPolicyBase.php`
khỏi quy trình. Ba bẫy còn lại xem bảng trên.

**Cái #158 kéo theo một hệ quả không ai đoán trước**: `$table` đúng làm bộ quét
`architecture:raw-table-reads` tra được chủ của `product_category`, và **hai chỗ
đọc thô xuyên module ở Ordering hiện ra** — nợ có sẵn, vô hình suốt vì bảng
không có chủ. R2 đỏ với ngân sách 0. Cách xử đúng (đã làm ở #2371): dời chỗ đọc
sang **adapter của module SỞ HỮU bảng** (`Catalog\Contracts\ProductCategoryLookup`),
đừng nâng ngân sách. Bài học chung: `$table` đúng chính là thứ cho phép phân
tích tĩnh quy trách nhiệm bảng cho module.

**Bộ quét đếm cả khớp trong DOCBLOCK.** Viết nguyên dạng lời gọi cũ vào comment
giải thích ("thay vì tự `DB::table('bảng_module_khác')`") sẽ **tự đếm thành một
lần đọc xuyên module** và làm R2 đỏ. Diễn đạt bằng lời, đừng chép lời gọi.

**Chưa sửa**: tên bảng pivot vẫn KHÔNG theo quy ước `joiningTable()` của Laravel
(snake_case, số ít, sắp alphabet) — thứ tự theo `pivotFor:` và số thì lúc ít lúc
nhiều. Upstream chọn "consistent" chứ không chọn "convention"; ghi ở omnify-go#158.
Hệ quả: `belongsToMany(Product::class)` vẫn phải viết tên bảng tay.

### 6.0.x — pivot của repo này KHÔNG đổi tên, và đó là ĐÚNG (#2376/#2384/#2387)

6.0.0 quảng cáo breaking change: pivot **ngầm** theo `joiningTable()` của Laravel
(snake_case, số ít, sắp alphabet), kèm migration đổi tên một lần. Kiểm trên
6.0.1 → 6.0.3, cả `reset` lẫn `gen`: **0 migration rename, 11/11 giữ tên**. Đừng
đi tìm migration rename; nó không tồn tại — và **không phải lỗi**.

**Lý do: cả 11 pivot đều được KHAI TƯỜNG MINH tên bảng.** 8 cái qua `joinTable`
ở phía owning, 3 cái qua `options.tableName` trên chính pivot. Tài liệu nói rõ
override thì convention không áp. Hỏi thẳng generator, đừng suy diễn:

```sh
npx omnify generate --migrations-only --verbose
#   Pivot override Branch:Coupon: Coupon.properties.branches.joinTable
#     = coupon_branch (Laravel convention is not applied)
#   … 11 dòng, đủ 11 pivot, kèm NGUỒN của từng override
```

**`--verbose` là lệnh đầu tiên phải chạy khi nghi generator sai về tên bảng.**
Nó in ra override đến từ file/property nào — thứ không grep ra được nếu override
nằm ở schema khác với pivot.

> ⚠️ **Bài học đắt nhất của cả loạt này, và nó là lỗi quy trình chứ không phải
> lỗi kiến thức.** Tôi mở `CouponBranch.yaml`, thấy không có `tableName`, kết
> luận "implicit", rồi khẳng định với upstream **hai lần** rằng không schema nào
> khai `ManyToMany` trỏ vào cặp đó. Sai: `Coupon.yaml:273` khai
> `joinTable: coupon_branch`. Một lệnh `grep -rn joinTable schemas/` là ra —
> tôi không chạy. Upstream phát hành **6.0.0 → 6.0.3** đuổi theo lỗi không tồn
> tại; omnify-go#159 đã đóng là invalid kèm lời xin lỗi.
>
> Tệ hơn: tôi còn dựng cả một "quy tắc đặt tên cũ" (tên-schema, cặp bị đảo, hai
> cái ngoại lệ) để giải thích các giá trị mà thật ra **do người viết tay trong
> YAML**. Đó là bịa lý thuyết trên một dữ kiện chưa kiểm.
>
> Luật rút ra — cùng họ với *"số 0 là một khẳng định, không phải mặc định"* của
> skill `issue-work`, chỉ khác là ở dạng **phủ định**: trước khi nói *"schema
> không khai X"*, phải grep X trên **toàn bộ `schemas/`**, không chỉ file đang mở.
> Một khẳng định phủ định cần bằng chứng mạnh hơn một khẳng định khẳng định.

Cái 6.0.1 THẬT SỰ đổi ở đây, cả hai đều tốt:

1. **Sửa một quan hệ trỏ vào bảng chưa bao giờ tồn tại.**
   `AllergenBaseModel::materials()` phát `belongsToMany(Material::class,
   'allergen_material_pivot')` — 0 kết quả trong mọi `Schema::create`; bảng thật
   là `material_allergens`. Đây là lỗi thật: `Material.yaml` KHAI
   `joinTable: material_allergens` ở phía owning, nhưng relation **nghịch** sinh
   ra lại bỏ qua override đó và tự tính một tên mặc định. Migration thì tôn
   trọng override, model thì không — hai nửa của cùng generator bất đồng.
   Nó không nổ vì `app/Models/Allergen.php` có bản đè viết tay — tức lỗi sống
   được đúng nhờ workaround.
2. **Đánh số lại 34 migration** (pivot chuyển lên sớm hơn nhiều: `000230` →
   `000004`). Nội dung **giống hệt từng byte**; đã đo hội tụ trên MySQL thật:
   2307 cột · 1470 dòng index · 425 FK, **0 khác biệt**. Docs phải dùng glob chứ
   đừng ghim số (#2362) — nếu không lượt này lại phá.

**Sau khi upstream vá, ĐI TÌM WORKAROUND CŨ MÀ XOÁ** (#2188). Bản vá làm bản đè
viết tay thành thừa, và giữ lại là giữ hai nguồn sự thật. Ở #2376 gỡ được hai:
`Allergen::materials()` (trùng byte với base) và `MenuSection::menus()` (workaround
cho `withPivot('tax_type')` mà omnify-go#156 đã vá). `Menu::menuSections()` thì
GIỮ nhưng viết lại thành `parent::menuSections()->orderByPivot(...)` — phần dư
thật sự chỉ là thứ tự.

Cách tìm: quét `belongsToMany(` khai tay trong `app/Models/*.php`, so từng cái
với method cùng tên ở BaseModel. Trùng ⇒ xoá. Khác ⇒ đọc xem phần khác có còn
lý do không.

⚠️ **Gỡ bản đè làm ĐỒ THỊ PHỤ THUỘC NHỎ LẠI, và ratchet sẽ ĐỎ.** `LayerCyclesTest`
bắt hạ `LARGEST_CYCLE_BUDGET` và `SCC_VIOLATING_EDGE_BUDGET` 6 → 5. Đó là tin
tốt, không phải lỗi — hạ ngưỡng, đừng nâng.

### Các lỗi generator đã biết — phần lớn *sai mà không kêu tiếng nào*

> Cố ý KHÔNG ghim số vào heading. Bản trước ghi "Bảy lỗi" rồi #3199 thêm mục
> **2b**, và một con số đọc lên thì sai còn tệ hơn không có con số — cùng án lệ
> với bảng đường deploy ở CLAUDE.md. Đếm tại chỗ.

**ĐỌC TÀI LIỆU OMNIFY TRƯỚC KHI GỌI CÁI GÌ LÀ LỖI GENERATOR.** Có MCP server
`omnify` ngay trong phiên: `omnify_guide` (topic: associations, migrations,
options, schema-format…), `omnify_get_schema`, `omnify_list_types`. Ngày
2026-07-30 đã có người mở **hai issue upstream sai** (omnify-go#148, #149) rồi
phải đóng lại — cả hai đều là hành vi **có ghi trong tài liệu**, chỉ là dùng sai:

- đổi tên property mà **không khai `renamedFrom`** thì generator hạ cấp thành
  drop+add, và tài liệu nói thẳng *"Renames need `renamedFrom`… the migration
  will lose data"*;
- property `Association` tên `X` sinh cột `X_id`, nên đặt tên property kết thúc
  bằng `_id` sẽ ra `_id_id` — đúng quy ước, không phải lỗi.

Repo upstream **có với tới được**: `omnify-jp/omnify-go` (lấy từ
`npm view @omnifyjp/omnify repository`), private nhưng `gh` chạy được và issue
đang bật. Ghi chú cũ nói 404 là sai. Nhưng với tới được **không phải** lý do để
báo bừa: đọc `omnify_guide` trước, tái hiện lại, rồi mới mở issue.

Tình trạng đối chiếu tài liệu của 6 mục dưới đây:

| Mục | Đã đối chiếu tài liệu? | Issue upstream |
|---|---|---|
| 1, 2 | **CHƯA** — kiểm trước khi tin | — |
| 3 | ✔ `omnify_guide` + `omnify_get_schema` + lịch sử upstream (#143 đã đóng, đây là phần còn lại) | **omnify-go#151** |
| 4 | ✔ `omnify-config-schema.json` nói rõ switch này *được tôn trọng* (xem mục 4) | **omnify-go#150** |
| 5 | không phải claim về lỗi — chỉ là sự thật vận hành | — |
| 6 | **CHƯA** đối chiếu `omnify_guide`, **chưa** mở issue upstream | — |
| 7 | **CHƯA** đối chiếu `omnify_guide`, **chưa** mở issue upstream | — |

**1. `Association` trên entity `kind: pivot` sinh sai tên cột.** *(kiểm lại trên
**5.9.14** — VẪN CHƯA SỬA; đừng mất công thử lại, workaround bên dưới còn cần)* Nó phát
`withPivot('<tên_association>')` chứ không phải tên cột FK — khai `taxType` thì
ra `withPivot('tax_type')` trong khi cột nó vừa tạo là `tax_type_id`. Select một
cột không tồn tại ⇒ **mọi query đi qua relation đó chết** (`no such column`),
68 test đỏ ngay khi regen (#1218). Cách né: **dựng lại relation ở model editable**
(`app/Models/*.php`), đừng gọi `parent::` — `withPivot` là cộng dồn nên kế thừa
thì tên hỏng vẫn còn.

**2. Thêm index vào `options.indexes` của entity ĐÃ TỒN TẠI thì bị nuốt.**
*(chưa kiểm lại trên 5.9.14)*
Không sinh DDL nào, nhưng vẫn ghi vào `.omnify/schemas.json` là đã áp dụng, và
`omnify diff` ngay sau đó báo "No changes detected" (#1216). Commit vào thì YAML
khai một index **không tồn tại** và từ đó không ai còn nhìn thấy lỗ hổng. Index
đi kèm một `Association` thì lại sinh đúng — nên nếu cần index, cân nhắc khai
qua association thay vì `options.indexes`.

**2b. Đổi `options.tableName` của một schema VỪA gen thì cũng bị nuốt — và xoá
file migration KHÔNG cứu được.** *(đo 2026-08-18, #3199)*

Biến thể khác của cùng cơ chế, nhưng đường đi khiến người ta yên tâm nhầm: đây
là **bảng MỚI hoàn toàn**, nên ai cũng cho là không dính bẫy #2 (vốn nói về
entity đã tồn tại).

Trình tự đã xảy ra:

1. gen lần 1 → migration ra tên số nhiều mặc định `identity_inbox_entries`
2. thêm `options.tableName: identity_inbox` vào schema, gen lại
3. **không có migration mới.** File cũ mang tên bảng SAI còn nguyên,
   `.omnify/schemas.json` đã ghi schema là "đã áp", và gen vẫn báo
   "Generation complete" bình thường

Phần đắt nhất: **xoá file migration rồi gen lại cũng không phát lại.** Thứ chặn
là STATE FILE, không phải sự tồn tại của file — nên phản xạ tự nhiên ("xoá đi
cho nó sinh lại") không những vô hiệu mà còn làm người ta tin là generator hỏng.

Cách xử — **không** dùng `reset` (bị cấm tuyệt đối):

```sh
git checkout -- .omnify/      # lùi state về trước lượt gen
npm run omnify:gen            # giờ `omnify diff` mới thấy lại `+ Added schema`
```

Luật rút ra, và nó mạnh hơn bẫy #2 gốc: **kiểm bằng NỘI DUNG migration, không
bằng "gen chạy xong" và cũng không bằng "có file mới".** Đếm file thì lượt trên
qua cổng với một bảng tên sai.

**3. Regen XOÁ ba quyết định load-bearing.** *(#1314, upstream **omnify-go#151**; là phần còn lại của #143 vốn đã đóng.* **ĐÃ SỬA Ở 5.9.20** *— đo ở #2371: regen KHÔNG còn xoá hai hằng `CREATED_AT`/`UPDATED_AT = null`, và `InvoiceCounterResourceBase` được sửa đúng chiều: nó GỠ `created_at` khỏi payload vì `invoice_counters` không có cột đó. Rào `OmnifyRegenLandminesTest` **giữ nguyên** — nó rẻ, và nó là thứ cho phép nói câu này bằng phép đo.)* Sau `pint --dirty`
chỉ còn 5 file lệch, và 3 trong số đó là những thứ **hỏng lúc chạy, không hỏng lúc
sinh code**:

| Bị xoá | Vì sao load-bearing | Hỏng thế nào |
|---|---|---|
| `InvoiceCounterBaseModel::CREATED_AT = null` | `invoice_counters` **không có** cột `created_at` | insert ghi vào cột không tồn tại |
| `OrderAdjustmentAllocationBaseModel::UPDATED_AT = null` | bảng đó **không có** `updated_at` | như trên |
| `OmnifyServiceProvider`: `Relation::morphMap(` | bị đổi thành `enforceMorphMap(`, mà `enforce` **bắt buộc** liệt kê mọi morphable (Sanctum tokenable, media, notification) | morph type ngoài map là throw |

Cái thứ ba đáng chú ý riêng: đó là một **sửa tay sống trong file "DO NOT EDIT"**,
kèm comment giải thích, và regen xoá cả comment. `tests/Feature/Architecture/
OmnifyRegenLandminesTest.php` ghim cả ba — regen xong chạy pint rồi chạy file test
đó, đỏ ở đâu thì khôi phục chỗ đó trước khi commit.

**4. `service: enable: false` bị bỏ qua.** *(5.9.18 — upstream **omnify-go#150**,
regression của #119 vốn xin đúng cái switch này)* Sinh **125 file `*ServiceBase.php`**
vào 111 module (repo chỉ commit 14 file, di sản từ hồi service codegen còn bật). Chúng
**untracked**, nên `git add -A` sau regen là commit vào cả 111 thư mục. Cùng lần
chạy đó generator lại báo 14 service editable là "orphan, không còn eligible" — tự
mâu thuẫn với việc nó vừa sinh 125 cái.

Đây KHÔNG phải dùng sai tài liệu — đã kiểm: `omnify-config-schema.json` của chính
5.9.18 (`definitions/CodegenLaravelPath/properties/enable`) ghi *"Currently honored by
the Laravel service layer; set `service.enable` to false to keep other Laravel
artifacts while emitting no services."* `omnify_guide` topic `options` thì chỉ nói
opt-out **theo từng schema** (`options.service: false`) và không nhắc switch cấp
target — guide cũ hơn config schema, nên **config schema là căn cứ**.

**KHÔNG tái hiện được nữa (kiểm 2026-08-04, #1772).** Hai lượt `omnify:gen` liên
tiếp trên 5.9.18 để lại **đúng 14 file `*ServiceBase.php`**, khớp chính xác tập đã
commit (`find ... | wc -l` = `git ls-files ... | grep -c` = 14), không file nào
untracked. Nên `git clean -fd backend/app/Omnify/Modules` **không còn là bước bắt
buộc sau mỗi regen** — nhưng vẫn đếm trước khi tin, vì chưa rõ vì sao nó thôi nổ.

**5b. State file TỤT LẠI SAU code generated — giờ có rào (#1640).** Commit
`252628e7b` commit code generated của PointReward (model, resource, migration
`2000_04_29_…`) mà **không** commit `.omnify/schemas.json`. Không gì đỏ. Hoá đơn
rơi vào người KẾ TIẾP: lượt regen của họ sinh thêm `2000_04_30_…` **trùng** với
bản `04_29`. Lần đó vô hại vì generator phát `if (! Schema::hasColumn(...))` —
vô hại **tình cờ**, không phải thiết kế; một thay đổi không idempotent (đổi kiểu
cột, drop index) sẽ ra hai migration đánh nhau.

Đây là ảnh gương của bẫy #2: cả hai làm repo nói dối về những gì đã sinh, và cả
hai chỉ lộ ra khi có người tình cờ regen.

Rào: `npm run omnify:drift` (đã nằm trong `fullSuite` của cổng merge). Nó so
**trực tiếp** tập schema + tập property giữa `schemas/**/*.yaml` và
`.omnify/schemas.json`.

**Rào hiển nhiên hơn — "`omnify diff` phải báo *No changes detected*" — KHÔNG
dùng được, và đã đo.** Lùi `.omnify/schemas.json` về đúng bản của lần drift
(221 schema thay vì 225, thiếu đúng bốn cái) thì `omnify diff` vẫn báo cây sạch;
xoá `.omnify/workspace-cache/` trước cũng không đổi gì — `omnify diff` **không
đọc file đó**. Nó CÓ phản ứng với một sửa đổi YAML, nên rào sai ấy thuyết phục ở
một phép thử rồi im lặng không phủ gì. Ghi lại vì nó là loại rào tệ hơn không có
rào: nó còn trả lời "có" cho câu hỏi "chỗ này đã được canh chưa".

**5. `.omnify/lock.json` đảo thứ tự mảng mỗi lần chạy.** Không đổi schema thì
`version` giữ nguyên (đúng) nhưng `timestamp` + thứ tự `workspaceProjectSchemas`
vẫn đổi ⇒ luôn có diff. Regen không-đổi-gì thì **revert lock.json**.

**6. Sinh `use` TRÙNG ⇒ file không parse được.** *(5.9.18, tìm ra ở #1637)* Đây là
mục **nặng nhất** trong danh sách và là mục duy nhất **không** thuộc kiểu "sai mà
không kêu tiếng nào" — nó kêu, nhưng kêu ở chỗ không ai ngờ.

Generator ghi `use App\Models\User;` **hai lần** vào cùng một file (đã thấy ở
`UserPolicyBase.php`). PHP từ chối:

```
Cannot use App\Models\User as User because the name is already in use
```

Class **không autoload nổi**, tức đây là *parse error*, không phải lỗi lúc chạy.

Cái làm nó nguy hiểm: **`pint` không sửa**. Import trùng là style hợp lệ với
fixer, chỉ là PHP không hợp lệ. Nên `generate && pint` — đúng thao tác mà chính
tài liệu này dạy là "gần như idempotent" — để lại một cây **không boot được**, và
người chạy tin rằng mình vừa làm đúng quy trình.

**ĐÃ SỬA Ở 5.9.21** (upstream omnify-go#157, đo ở #2371: 2 → 1 lần import, và
không tái phát sau `gen`). **Bỏ bước `git checkout --` file đó.** Giữ mục này
làm hồ sơ, vì rào `OmnifyRegenLandminesTest` vẫn quét import trùng trên MỌI file
generated — rào rẻ, và nó là thứ cho phép nói câu "đã sửa" bằng phép đo.

Lịch sử: tái hiện trên 5.9.18 (hai lượt liên tiếp, #1970 rồi #1979) và trên
5.9.20 kể cả khi generator tự chạy Pint trên 992 file — Pint không coi import
trùng là lỗi style. Lượt đầu chỉ đổi **hai cột nullable** của một entity đã có; lượt sau
thêm hẳn một entity mới, một enum mới và ba cột. Cả hai đều phát lại
`use App\Models\User;` lần thứ hai vào `UserPolicyBase.php` — tức nó **không phụ
thuộc vào việc bạn chạm gì**, cứ regen là dính, từ thay đổi nhỏ nhất tới thay đổi
lớn. Coi như **luôn xảy ra**: sau mỗi `omnify:gen`, `git checkout --` file đó
trước khi làm gì khác. Nó cũng không nằm trong nhóm
file liên quan tới schema mình sửa, nên rất dễ trôi qua mắt trong lúc đọc diff:
lượt đó chỉ có 14 file đổi và đây là file DUY NHẤT không dính dáng gì tới
`BranchScheduleOverride`. Cách xử lý vẫn là `git checkout --` chính file đó
(không phải sửa tay), rồi `php -l` xác nhận.

`tests/Feature/Architecture/OmnifyRegenLandminesTest.php` ghim mục này bằng một
lượt quét regex trong tiến trình (không `php -l` 1022 file — mất ~35s):
`array_diff_assoc` trên danh sách `use` của từng file generated. Bắt được cả
trường hợp import trùng nằm xen giữa các import khác.

**7. Regen GHI VÀO thư mục submodule chưa init, và khoá luôn đường init.**
*(5.9.18, trả giá ở #1772)* Trong worktree do `tal claim` dựng, submodule chưa
được checkout — thư mục `admin-web/`, `workstation-app/` chỉ là điểm mount rỗng.
Generator không biết điều đó: nó ghi thẳng **398 file TS** vào `admin-web/src/` và
**72 file Go** vào `workstation-app/internal/`.

Umbrella **không thấy gì cả** — git không nhìn vào trong một submodule chưa init,
nên `git status` sạch bong và không có dấu hiệu nào. Cái nổ đến sau, lúc cần
submodule thật:

```
fatal: destination path '.../admin-web' already exists and is not an empty directory
Failed to clone 'admin-web' a second time, aborting
```

Nghĩa là: regen TRƯỚC khi init submodule ⇒ không init được nữa. Thứ tự đúng là
`tal submodule <path>` cho mọi submodule mình sắp chạm **rồi mới** `omnify:gen`;
lỡ rồi thì dời chỗ đống file lạc đi (đừng xoá vội — đối chiếu với bản trong repo
con trước) rồi init lại.

**Luôn kiểm tra regen có sinh migration không** khi mình vừa thêm thứ đáng lẽ
phải đổi DDL. Không có file mới trong `backend/database/migrations/omnify/`
nghĩa là generator đã nuốt, không phải là "không cần đổi gì".

Regen chạm cả `backend/` (in-tree) lẫn `admin-web/` (SUBMODULE). Hai chỗ này commit
theo hai nghi thức khác nhau — gộp làm một là ra pointer trỏ vào commit không tồn
tại ở remote:

```sh
# 1. admin-web là repo riêng — commit VÀ PUSH ở đó trước
cd admin-web && git add <đường dẫn cụ thể> && git commit -m "chore: regen omnify"
git push origin <branch> && cd ..

# 2. umbrella: backend + schemas in-tree, admin-web chỉ là con trỏ
git add backend schemas admin-web
git commit -m "chore: regen omnify + bump admin-web"
```

Xem `## Monorepo — không còn nghi thức submodule` trong `CLAUDE.md` gốc.

