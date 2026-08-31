# Kế hoạch — Chuyển từ Polling sang WebSocket

## Tại sao chuyển?

| | Polling 5s | WebSocket |
|---|---|---|
| Không có khách gọi | 2 req/s liên tục | 0 request |
| 1 khách gọi | Vẫn 2 req/s | 1 event push |
| DB query | Mỗi 5s dù không đổi | Chỉ khi có sự kiện |
| Độ trễ nhân viên thấy | 0–5s | < 1s |

Polling tốn tài nguyên cố định. WebSocket tốn tài nguyên theo sự kiện.

---

## Kiến trúc sau khi chuyển

```
Khách bấm gọi
    ↓
POST /api/v1/tms/tables/{qr_token}/call
    ↓
Backend set call_requested_at = NOW()
    ↓
Backend broadcast Event → Laravel Reverb (WebSocket server)
    ↓
TMS app đang lắng nghe channel → nhận event ngay
    ↓
invalidateQueries → refetch → UI update < 1s
```

---

## Stack

- **Backend WebSocket server**: Laravel Reverb (built-in Laravel 11+, chạy cùng máy trạm)
- **App WebSocket client**: `@laravel/echo` + `pusher-js`

---

## Các file thay đổi

### Backend (`/Users/phamduyanh1910/Documents/famgia/tempo/backend`)

#### 1. Cài Laravel Reverb

```bash
php artisan install:broadcasting
```

Chọn Reverb khi được hỏi. Lệnh này tự:
- Cài `laravel/reverb` package
- Tạo `config/broadcasting.php`
- Tạo `config/reverb.php`
- Thêm env vars vào `.env`

#### 2. `.env` — Thêm Reverb config

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=tms-app
REVERB_APP_KEY=tms-secret-key
REVERB_APP_SECRET=tms-secret
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=http
```

> `REVERB_HOST=0.0.0.0` để TMS app trên iPhone kết nối được qua LAN.

#### 3. `app/Events/TableCallRequested.php` — Event mới

```php
<?php

namespace App\Events;

use App\Models\Table;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class TableCallRequested implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Table $table) {}

    public function broadcastOn(): Channel
    {
        // Channel theo branch_id — mỗi chi nhánh nghe riêng
        return new Channel("branch.{$this->table->branch_id}.tables");
    }

    public function broadcastAs(): string
    {
        return 'table.call_requested';
    }

    public function broadcastWith(): array
    {
        return ['table_id' => $this->table->id];
    }
}
```

#### 4. `app/Events/TableCallCleared.php` — Event mới

```php
<?php

namespace App\Events;

use App\Models\Table;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class TableCallCleared implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Table $table) {}

    public function broadcastOn(): Channel
    {
        return new Channel("branch.{$this->table->branch_id}.tables");
    }

    public function broadcastAs(): string
    {
        return 'table.call_cleared';
    }

    public function broadcastWith(): array
    {
        return ['table_id' => $this->table->id];
    }
}
```

#### 5. `TmsController.php` — Dispatch event sau khi update

**`callStaff()`** — thêm broadcast:
```php
$table->update(['call_requested_at' => now()]);

broadcast(new TableCallRequested($table))->toOthers();
```

**`clearCall()`** — thêm broadcast:
```php
$table->update(['call_requested_at' => null]);

broadcast(new TableCallCleared($table))->toOthers();
```

---

### TMS App (`/Users/phamduyanh1910/Documents/famgia/godx-tempo-tmt`)

#### 6. Cài package

```bash
npm install @laravel/echo pusher-js --legacy-peer-deps
```

#### 7. `src/lib/echo.ts` — File mới, setup Laravel Echo

```ts
import Echo from '@laravel/echo';
import Pusher from 'pusher-js';

const REVERB_HOST = process.env.EXPO_PUBLIC_REVERB_HOST || '192.168.1.197';
const REVERB_PORT = Number(process.env.EXPO_PUBLIC_REVERB_PORT) || 8080;

(globalThis as Record<string, unknown>).Pusher = Pusher;

export const echo = new Echo({
  broadcaster: 'reverb',
  key: process.env.EXPO_PUBLIC_REVERB_KEY || 'tms-secret-key',
  wsHost: REVERB_HOST,
  wsPort: REVERB_PORT,
  wssPort: REVERB_PORT,
  forceTLS: false,
  enabledTransports: ['ws'],
});
```

#### 8. `.env` — Thêm Reverb config

```env
EXPO_PUBLIC_REVERB_HOST=192.168.1.197
EXPO_PUBLIC_REVERB_PORT=8080
EXPO_PUBLIC_REVERB_KEY=tms-secret-key
```

#### 9. `src/hooks/use-zones.ts` — Thêm WebSocket listener

```ts
// Giữ nguyên polling làm fallback (tăng lên 60s)
// WebSocket sẽ trigger refetch ngay khi có event

useEffect(() => {
  const branchId = device?.branch_id;
  if (!branchId) return;

  const channel = echo
    .channel(`branch.${branchId}.tables`)
    .listen('.table.call_requested', () => {
      queryClient.invalidateQueries({ queryKey: zoneKeys.list(DUMMY_SLUG) });
    })
    .listen('.table.call_cleared', () => {
      queryClient.invalidateQueries({ queryKey: zoneKeys.list(DUMMY_SLUG) });
    });

  return () => {
    channel.stopListening('.table.call_requested');
    channel.stopListening('.table.call_cleared');
    echo.leave(`branch.${branchId}.tables`);
  };
}, [device?.branch_id, queryClient]);
```

**Điều chỉnh polling:**
```ts
// Từ 5s → 60s (fallback nếu WebSocket mất kết nối)
refetchInterval: 60_000,
```

---

## Thứ tự thực hiện

1. Backend: chạy `php artisan install:broadcasting` → chọn Reverb
2. Backend: tạo `TableCallRequested.php` + `TableCallCleared.php`
3. Backend: sửa `TmsController.php` dispatch event
4. Backend: chạy Reverb server: `php artisan reverb:start`
5. App: cài `@laravel/echo` + `pusher-js`
6. App: tạo `src/lib/echo.ts`
7. App: thêm env vars vào `.env`
8. App: sửa `use-zones.ts` thêm WebSocket listener + tăng polling lên 60s

---

## Tại sao vẫn giữ polling?

WebSocket có thể mất kết nối khi:
- Máy trạm restart
- Mạng LAN bị gián đoạn
- Reverb server crash

Polling 60s làm **fallback** — dù WebSocket có chết, app vẫn tự sync lại sau tối đa 60s. Không bao giờ bỏ hoàn toàn polling.

---

## Bảo mật channel

Hiện tại dùng **public channel** (`Channel`) — ai biết `branch_id` đều nghe được.

Nếu cần bảo mật hơn → đổi sang **private channel** (`PrivateChannel`):
- Backend cần thêm route `/broadcasting/auth`
- App cần gửi device token khi subscribe
- Phức tạp hơn nhưng an toàn hơn

Với mô hình LAN nội bộ tại quán, public channel là đủ.
