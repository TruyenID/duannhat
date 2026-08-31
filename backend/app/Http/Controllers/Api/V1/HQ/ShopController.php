<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Requests\HQ\StoreShopRequest;
use App\Http\Requests\HQ\UpdateShopRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Services\Shop\BranchProvisioningService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * HQ shop (Branch) management — tempo-native.
 *
 * tempo is a pure godx consumer: the org tree is mirrored from godx Identity
 * into the local `branches` table during Platform provisioning. Shops that HQ
 * creates here are local-first — tempo self-mints the `console_branch_id` and
 * owns the row. There is no legacy identity gateway round-trip (removed at the Platform
 * cutover).
 */
class ShopController extends Controller
{
    use AuthorizesRequests;

    /**
     * Disk giữ ảnh logo/banner của shop — ĐỌC CONFIG, không ghi cứng (#2163).
     *
     * Trả về giá trị chứ không phải hằng số, vì tên disk là **cấu hình theo môi
     * trường**: một hằng `private const … = 's3'` khoá cứng nó vào giá trị đúng
     * cho MỘT môi trường và sai cho mọi môi trường khác.
     *
     * Nó đã sai trên production, và sai ở mức nhìn thấy được: prod không cấu
     * hình s3 (`AWS_BUCKET` rỗng), nên trước #2147 endpoint này trả `201` kèm
     * URL trỏ vào hư không với log Laravel sạch trơn; sau #2147 — vốn đúng khi
     * đọc kết quả `put()` — nó chuyển thẳng sang **500 với mọi người dùng**.
     * Không cách nào chữa ở phía đọc-kết-quả: đích ghi mới là chỗ sai.
     *
     * Vẫn giữ MỘT nguồn duy nhất (method này) chứ không rải `config(...)` ra ba
     * chỗ, vì lý do của hằng số cũ vẫn đúng: đường ghi và đường dựng URL phải
     * cùng một disk, lệch nhau là ảnh ghi một nơi đọc một nẻo.
     */
    private function branchImageDisk(): string
    {
        return (string) config('filesystems.uploads');
    }

    public function index(Request $request): JsonResponse
    {
        $brand = $request->attributes->get('brand');

        $branches = Branch::where('console_organization_id', $brand->console_organization_id)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'is_headquarters', 'is_active']);

        return response()->json([
            'data' => $branches->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'slug' => $b->slug,
                'is_headquarters' => $b->is_headquarters,
                'is_active' => $b->is_active,
            ]),
        ]);
    }

    /**
     * Create a new shop (Branch). tempo self-mints the console_branch_id — the
     * local row is authoritative for shops created here.
     */
    public function store(StoreShopRequest $request): JsonResponse
    {
        $this->authorize('create', Branch::class);

        $brand = $request->attributes->get('brand');

        // Danh tính Platform + mặc định miền do service quyết, không do body
        // request (#1666) — nhận chúng từ client là để một người tạo được chi
        // nhánh cho tổ chức khác.
        $branch = app(BranchProvisioningService::class)->create(
            $brand,
            (string) $request->user()->console_organization_id,
            $request->validated(),
        );

        return (new BranchResource($branch))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Upload a shop logo or banner image.
     *
     * Stores the file on the upload disk (see `branchImageDisk()`) under the
     * `branches/` key prefix and returns the resulting public URL. The URL is
     * saved onto Branch.logo / Branch.img_branches via the normal store/update
     * flow. Storing under `branches/` is deliberate — the RebaseStorageUrl cast
     * rebases exactly that prefix onto the live storage host, so the image
     * survives any public hostname change (staging tunnel rotation → prod CDN).
     * That guarantee holds because the cast reads its base from the SAME
     * `filesystems.uploads` disk this method writes to (#2175); it was briefly
     * void while the cast still read `filesystems.disks.s3.url`.
     *
     * Decoupled from a specific branch so the same endpoint serves the create
     * dialog (no branch id yet) and the edit dialog alike.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $this->authorize('create', Branch::class);

        $validated = $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            // #936 — `banner` is the legacy `img_branches` slot; the three
            // `banner_*` types are the per-breakpoint ones. The type only
            // decides the object-key prefix, so adding values is additive.
            'type' => ['required', 'string', 'in:logo,banner,banner_desktop,banner_tablet,banner_mobile'],
        ]);

        $extension = $request->file('file')->extension();
        $path = 'branches/'.$validated['type'].'-'.Str::uuid().'.'.$extension;

        // #2146 — ĐỌC kết quả ghi. Các disk upload khai `throw => false` (cả
        // `s3` lẫn `public`), nên `put()` trả `false` khi hỏng và KHÔNG ném,
        // KHÔNG ghi log. Bản trước bỏ qua giá trị này rồi đáp 201 kèm một URL
        // trỏ vào hư không: người dùng thấy "thành công", ảnh không bao giờ
        // hiện, Laravel log trống.
        //
        // Từ #2163 đích ghi mặc định là `public` (driver local); các nguyên
        // nhân thường gặp — quyền `storage/app/public` sau rsync, đầy đĩa,
        // `storage:link` trượt (deploy chạy nó với `|| true`) — đều KHÔNG tự
        // khỏi khi thử lại. Vì vậy 500 (hỏng phía máy chủ, cần người sửa) chứ
        // không phải 503 (tạm thời).
        $disk = $this->branchImageDisk();

        $stored = Storage::disk($disk)
            ->put($path, $request->file('file')->getContent());

        if ($stored === false) {
            Log::error('shop image upload: ghi vào disk thất bại', [
                'disk' => $disk,
                'path' => $path,
                'type' => $validated['type'],
                'hint' => 'disk khai throw=false nên lỗi ghi im lặng (#2146). Với disk public/local: kiểm quyền storage/app/public (rsync đổi owner?), dung lượng đĩa, và storage:link (deploy chạy nó với `|| true` nên trượt không đỏ). Với disk s3: kiểm AWS_BUCKET / AWS_ACCESS_KEY_ID. Disk đang dùng nằm trong field `disk` của log này.',
            ]);

            return response()->json([
                'message' => __('Could not store the uploaded image.'),
                'code' => 'IMAGE_UPLOAD_STORAGE_FAILED',
            ], 500);
        }

        return response()->json([
            'data' => [
                'url' => Storage::disk($disk)->url($path),
            ],
        ], 201);
    }

    /**
     * Update a shop. Local-only — tempo owns the branch row.
     */
    public function update(UpdateShopRequest $request): JsonResponse
    {
        $shop = $this->resolveShop($request);
        $this->authorizeShopOrganization($request, $shop);
        $this->authorize('update', $shop);

        $request->attributes->set('shop', $shop);

        $shop->fill($request->validated())->save();

        return (new BranchResource($shop->fresh()))->response();
    }

    /**
     * Soft-delete a shop. Local-only.
     */
    public function destroy(Request $request): JsonResponse
    {
        $shop = $this->resolveShop($request);
        $this->authorizeShopOrganization($request, $shop);
        $this->authorize('delete', $shop);

        $shop->delete();

        return response()->json(null, 204);
    }

    /**
     * Bulk soft-delete shops scoped to the brand's organization.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $this->authorize('delete', Branch::class);

        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid'],
        ]);

        $brand = $request->attributes->get('brand');
        $deleted = 0;
        $errors = [];

        foreach ($request->input('ids') as $id) {
            $shop = Branch::where('console_organization_id', $brand->console_organization_id)
                ->find($id);

            if (! $shop) {
                $errors[] = ['id' => $id, 'message' => 'Not found'];

                continue;
            }

            try {
                $shop->delete();
                $deleted++;
            } catch (\Exception $e) {
                $errors[] = [
                    'id' => $id,
                    'name' => $shop->name,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'deleted' => $deleted,
            'errors' => $errors,
        ]);
    }

    /**
     * Toggle the is_active flag. Local-only.
     */
    public function toggleStatus(Request $request): JsonResponse
    {
        $shop = $this->resolveShop($request);
        $this->authorizeShopOrganization($request, $shop);
        $this->authorize('update', $shop);

        $shop->is_active = ! $shop->is_active;
        $shop->save();

        return response()->json([
            'data' => [
                'id' => $shop->id,
                'is_active' => $shop->is_active,
            ],
        ]);
    }

    /**
     * Restore a soft-deleted shop. Local-only.
     */
    public function restore(Request $request): JsonResponse
    {
        $shop = Branch::withTrashed()->findOrFail($request->route('shop'));
        $this->authorizeShopOrganization($request, $shop);
        $this->authorize('update', $shop);

        $shop->restore();

        return response()->json([
            'data' => [
                'id' => $shop->id,
                'is_active' => $shop->is_active,
            ],
        ]);
    }

    private function resolveShop(Request $request): Branch
    {
        $resolved = $request->route('shop');

        return $resolved instanceof Branch ? $resolved : Branch::findOrFail($resolved);
    }

    private function authorizeShopOrganization(Request $request, Branch $shop): void
    {
        $brand = $request->attributes->get('brand');

        abort_unless(
            $brand && $shop->console_organization_id === $brand->console_organization_id,
            404,
        );
    }
}
