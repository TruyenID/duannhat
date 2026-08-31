# Backend Implementation Guide

## Laravel Cloud Backend — Workstation API

### File Structure

```
backend/
├── routes/api/workstation.php                    # Route definitions
├── app/Http/Controllers/Api/V1/Workstation/
│   ├── WorkstationSyncController.php             # pull, push, status
│   ├── WorkstationMenuController.php             # index, changes
│   ├── WorkstationOrderController.php            # store, updateStatus, recordPayment, index
│   ├── WorkstationBranchController.php           # show, staff
│   └── WorkstationDeviceController.php           # heartbeat, config
├── app/Http/Requests/Workstation/
│   ├── SyncPushRequest.php
│   ├── StoreOrderRequest.php
│   ├── UpdateOrderStatusRequest.php
│   ├── RecordPaymentRequest.php
│   └── HeartbeatRequest.php
├── app/Services/Workstation/
│   ├── WorkstationSyncService.php                # pull aggregation, push processing
│   └── WorkstationMenuService.php                # menu queries scoped to branch
├── database/migrations/
│   └── xxxx_create_sync_idempotency_keys_table.php
└── tests/Feature/Api/V1/Workstation/
    ├── WorkstationSyncTest.php
    ├── WorkstationMenuTest.php
    ├── WorkstationOrderTest.php
    ├── WorkstationBranchTest.php
    └── WorkstationDeviceTest.php
```

### Step 1: Routes

File: `routes/api/workstation.php`

```php
<?php

use App\Http\Controllers\Api\V1\Workstation\WorkstationSyncController;
use App\Http\Controllers\Api\V1\Workstation\WorkstationMenuController;
use App\Http\Controllers\Api\V1\Workstation\WorkstationOrderController;
use App\Http\Controllers\Api\V1\Workstation\WorkstationBranchController;
use App\Http\Controllers\Api\V1\Workstation\WorkstationDeviceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/workstation')->group(function () {
    // Pairing (public, no auth)
    Route::post('pair', [WorkstationDeviceController::class, 'pair']);

    // Authenticated routes (require device_token)
    Route::middleware('device.auth:workstation')->group(function () {
        // Sync
        Route::get('sync/pull', [WorkstationSyncController::class, 'pull']);
        Route::post('sync/push', [WorkstationSyncController::class, 'push']);
        Route::get('sync/status', [WorkstationSyncController::class, 'status']);

        // Menu
        Route::get('menu', [WorkstationMenuController::class, 'index']);
        Route::get('menu/changes', [WorkstationMenuController::class, 'changes']);

        // Orders
        Route::post('orders', [WorkstationOrderController::class, 'store']);
        Route::put('orders/{order}/status', [WorkstationOrderController::class, 'updateStatus']);
        Route::post('orders/{order}/payment', [WorkstationOrderController::class, 'recordPayment']);
        Route::get('orders', [WorkstationOrderController::class, 'index']);

        // Branch
        Route::get('branch', [WorkstationBranchController::class, 'show']);
        Route::get('branch/staff', [WorkstationBranchController::class, 'staff']);

        // Device
        Route::post('heartbeat', [WorkstationDeviceController::class, 'heartbeat']);
        Route::get('config', [WorkstationDeviceController::class, 'config']);
    });
});
```

Wire in `routes/api.php`:
```php
if (file_exists(__DIR__.'/api/workstation.php')) {
    require __DIR__.'/api/workstation.php';
}
```

### Step 2: Idempotency Table

Migration:
```php
Schema::create('sync_idempotency_keys', function (Blueprint $table) {
    $table->id();
    $table->string('idempotency_key', 255)->unique();
    $table->foreignUuid('device_id')->constrained('devices');
    $table->string('entity_type', 50);
    $table->uuid('entity_id')->nullable();
    $table->string('response_status', 10); // 'ok' or 'error'
    $table->json('response_payload')->nullable();
    $table->timestamps();
    $table->index('device_id');
    $table->index('created_at');
});
```

Scheduled cleanup:
```php
// Console kernel
Schedule::command('model:prune', ['--model' => SyncIdempotencyKey::class])->daily();
```

### Step 3: Controller Pattern

Moi controller lay device tu request context:

```php
class WorkstationSyncController extends Controller
{
    public function pull(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = $device->branch_id;
        $since = $request->query('since');

        // Query data scoped to branch
        // ...
    }
}
```

### Step 4: Sync Push Processing

```php
class WorkstationSyncService
{
    public function processPush(Device $device, array $operations): array
    {
        $results = [];
        
        foreach ($operations as $op) {
            // Check idempotency
            $existing = SyncIdempotencyKey::where('idempotency_key', $op['idempotency_key'])->first();
            if ($existing && $existing->response_status === 'ok') {
                $results[] = json_decode($existing->response_payload, true);
                continue;
            }

            try {
                $result = match ($op['operation']) {
                    'create' => $this->createOrder($device, $op),
                    'status_update' => $this->updateOrderStatus($device, $op),
                    'payment' => $this->recordPayment($device, $op),
                    default => throw new \InvalidArgumentException("Unknown operation: {$op['operation']}"),
                };
                
                // Store idempotency
                SyncIdempotencyKey::create([
                    'idempotency_key' => $op['idempotency_key'],
                    'device_id' => $device->id,
                    'entity_type' => $op['entity_type'],
                    'entity_id' => $result['cloud_id'] ?? null,
                    'response_status' => 'ok',
                    'response_payload' => json_encode($result),
                ]);
                
                $results[] = $result;
            } catch (\Throwable $e) {
                $errorResult = [
                    'idempotency_key' => $op['idempotency_key'],
                    'status' => 'error',
                    'error_code' => $this->classifyError($e),
                    'error_message' => $e->getMessage(),
                ];
                $results[] = $errorResult;
            }
        }

        return $results;
    }
}
```

### Step 5: Testing

Follow Pest pattern:
```php
it('pulls menu data for paired workstation', function () {
    $device = Device::factory()->workstation()->active()->create();
    $product = Product::factory()->for($device->branch)->create();

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$device->device_token}",
    ])->getJson('/api/v1/workstation/sync/pull');

    $response->assertOk()
        ->assertJsonStructure(['data' => ['server_time', 'branch', 'menus']]);
});

it('rejects non-workstation device types', function () {
    $device = Device::factory()->tms()->active()->create();

    $this->withHeaders([
        'Authorization' => "Bearer {$device->device_token}",
    ])->getJson('/api/v1/workstation/sync/pull')
        ->assertForbidden();
});

it('handles idempotent order push', function () {
    $device = Device::factory()->workstation()->active()->create();
    $idempotencyKey = Str::uuid()->toString();

    // First push
    $response1 = $this->withHeaders([
        'Authorization' => "Bearer {$device->device_token}",
    ])->postJson('/api/v1/workstation/sync/push', [
        'operations' => [['idempotency_key' => $idempotencyKey, ...]],
    ]);

    // Second push (same key)
    $response2 = $this->withHeaders([
        'Authorization' => "Bearer {$device->device_token}",
    ])->postJson('/api/v1/workstation/sync/push', [
        'operations' => [['idempotency_key' => $idempotencyKey, ...]],
    ]);

    // Should return same result, not create duplicate
    expect($response1->json('data.results.0.cloud_id'))
        ->toBe($response2->json('data.results.0.cloud_id'));
});
```

### Reference Files

- `backend/app/Http/Middleware/AuthenticateDevice.php` — device auth middleware
- `backend/routes/api/tms.php` — TMS route pattern to follow
- `backend/app/Http/Controllers/Api/V1/Tms/TmsController.php` — TMS controller pattern
- `backend/app/Omnify/Modules/CustomerOrder/` — Order models
- `backend/app/Omnify/Modules/Menu/` — Menu models
- `backend/app/Services/DeviceService.php` — Device service
