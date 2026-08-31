<?php

namespace App\Services;

use App\Models\File;
use App\Omnify\Enums\FileStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FileUploadService
{
    /**
     * Upload a file to temp storage and return the File model with a UUID.
     */
    public function uploadTemp(
        UploadedFile $uploadedFile,
        string $organizationId,
        string $collection = 'default',
        string $disk = 'local',
    ): File {
        $path = $uploadedFile->store(
            "uploads/temp/{$organizationId}",
            $disk,
        );

        // #2149 — `store()` trả `string|false`. Mọi disk khai `throw => false`
        // nên ghi hỏng KHÔNG ném và KHÔNG log; `false` rơi thẳng vào
        // `File::create(['path' => …])`, mà `files.path` là NOT NULL nên nó
        // bind thành chuỗi rỗng và INSERT **thành công**. Kết quả: một hàng
        // `files` trỏ vào hư không, `getUrl()` phát ra URL 404 trông hợp lệ, và
        // hai đường HTTP (`/files/upload`, ảnh đánh giá) đều đáp 201.
        //
        // Ném chứ không trả `null`: caller khai kiểu trả về là `File`, và một
        // hàng không có byte là thứ tồn tại lâu hơn cả request hỏng.
        if ($path === false) {
            Log::error('file upload: ghi vào disk thất bại', [
                'disk' => $disk,
                'organization_id' => $organizationId,
                'collection' => $collection,
                'original_name' => $uploadedFile->getClientOriginalName(),
                'hint' => 'disk khai throw=false nên lỗi ghi im lặng (#2149)',
            ]);

            throw new RuntimeException('file upload: could not store the uploaded file');
        }

        return File::create([
            'organization_id' => $organizationId,
            'collection' => $collection,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $uploadedFile->getClientOriginalName(),
            'mime_type' => $uploadedFile->getMimeType(),
            'size' => $uploadedFile->getSize(),
            'status' => FileStatusEnum::Temporary,
            'expires_at' => now()->addHours(12),
        ]);
    }

    /**
     * Make a single file permanent, optionally moving it to a new directory.
     */
    /**
     * #1772/#1797 — giữ vĩnh viễn một tập File theo ID.
     *
     * Tồn tại để module KHÁC không phải chạm `App\Models\File`. `File` thuộc
     * module Platform; `MembershipTierBackgroundService` (Loyalty) trước đây tự
     * `File::query()->whereIn(...)`, và deptrac bắt đúng cạnh đó — một service
     * nghiệp vụ đọc model của module khác.
     *
     * Nhận ID chứ không nhận model: nếu nhận model thì bên gọi vẫn phải tự truy
     * vấn, tức vẫn phải import `File`, và cạnh vi phạm không mất đi.
     *
     * ID không tồn tại thì bỏ qua — bản đồ hạng có thể trỏ tới File đã bị xoá,
     * và đó không phải lỗi cần ném.
     *
     * @param  list<string>  $ids
     */
    /**
     * #1772 — URL công khai của một tập File theo ID.
     *
     * Cùng lý do `makePermanentByIds`: module khác không được chạm
     * `App\Models\File`. Trả về map `id => url|null` — id không tồn tại thì
     * vắng mặt, để bên gọi tự quyết hiển thị gì.
     *
     * URL giải LÚC ĐỌC chứ không lưu sẵn: `File::getUrl()` dựng lại đường dẫn từ
     * cấu hình disk hiện hành, nên đổi disk không làm chết ảnh cũ.
     *
     * @param  list<string>  $ids
     * @return array<string, string|null>
     */
    public function urlsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return File::query()
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (File $file): array => [(string) $file->id => $file->getUrl()])
            ->all();
    }

    public function makePermanentByIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        File::query()
            ->whereIn('id', $ids)
            ->get()
            ->each(fn (File $file) => $this->makePermanent($file));
    }

    public function makePermanent(File $file, ?string $newDirectory = null): File
    {
        if ($file->isPermanent()) {
            return $file;
        }

        if ($newDirectory) {
            $newPath = $newDirectory.'/'.basename($file->path);

            // #2149 — `move()` trả `false` khi hỏng (throw=false). Ghi `path`
            // mới mà byte chưa dời là làm hàng trỏ vào chỗ trống VÀ bỏ mồ côi
            // byte cũ — hỏng hai đầu cùng lúc.
            //
            // Chưa caller production nào truyền `$newDirectory`, nên đây là gia
            // cố chứ không phải sửa lỗi đang cắn. Để nguyên một quả mìn đã biết
            // trong file vừa sửa thì tệ hơn.
            if (Storage::disk($file->disk)->move($file->path, $newPath) === false) {
                Log::error('file makePermanent: dời file thất bại', [
                    'disk' => $file->disk,
                    'from' => $file->path,
                    'to' => $newPath,
                    'file_id' => $file->id,
                ]);

                throw new RuntimeException('file makePermanent: could not move the stored file');
            }

            $file->path = $newPath;
        }

        $file->status = FileStatusEnum::Permanent;
        $file->expires_at = null;
        $file->save();

        return $file;
    }

    /**
     * Attach an array of temp file IDs to a morphable model.
     *
     * @param  array<int, string>  $fileIds
     */
    public function attachToModel(
        Model $model,
        array $fileIds,
        string $collection = 'default',
    ): int {
        $attached = File::whereIn('id', $fileIds)
            ->where('status', FileStatusEnum::Temporary)
            ->where('organization_id', $model->organization_id)
            ->update([
                'fileable_type' => $model->getMorphClass(),
                'fileable_id' => $model->getKey(),
                'collection' => $collection,
                'status' => FileStatusEnum::Permanent,
                'expires_at' => null,
            ]);

        // #2149 — số hàng khớp từng bị VỨT, và 0 hàng khớp KHÔNG phải exception,
        // nên `DB::transaction` bao ngoài commit bình thường. Id hết hạn (12h),
        // sai tổ chức, hay đã permanent đều lặng lẽ ra 0 — đánh giá được tạo,
        // API trả 201, không ảnh nào dính vào.
        //
        // Cảnh báo chứ không ném: gắn được MỘT PHẦN là trạng thái mơ hồ, và ném
        // ở đây sẽ cuốn theo cả bản ghi cha đã hợp lệ. Trả về số lượng để caller
        // quyết, và ghi log để nó không bao giờ im.
        $requested = count(array_unique($fileIds));
        if ($requested > 0 && $attached < $requested) {
            Log::warning('file attach: không gắn đủ số file yêu cầu', [
                'requested' => $requested,
                'attached' => $attached,
                'fileable_type' => $model->getMorphClass(),
                'fileable_id' => $model->getKey(),
                'hint' => 'id hết hạn / sai tổ chức / đã permanent (#2149)',
            ]);
        }

        return $attached;
    }

    /**
     * Delete a file record and its physical file from storage.
     */
    public function delete(File $file): bool
    {
        // #2149 — kết quả xoá từng bị VỨT, và chữ ký `: bool` chỉ nói về hàng DB.
        // Flysystem coi xoá một file không tồn tại là THÀNH CÔNG, nên `false` ở
        // đây là hỏng thật chứ không phải "đã xoá rồi".
        //
        // Không xoá hàng DB khi byte chưa xoá được: hàng biến mất mà byte ở lại
        // là mồ côi VĨNH VIỄN — không còn bản ghi nào để lần dọn sau tìm ra. Giữ
        // hàng lại thì `cleanupExpired()` và thao tác xoá lần sau vẫn với tới nó.
        if (Storage::disk($file->disk)->delete($file->path) === false) {
            Log::error('file delete: xoá khỏi disk thất bại — GIỮ hàng DB lại', [
                'disk' => $file->disk,
                'path' => $file->path,
                'file_id' => $file->id,
            ]);

            throw new RuntimeException('file delete: could not remove the stored file');
        }

        return $file->forceDelete();
    }

    /**
     * Revert files back to temporary status with a fresh expires_at.
     *
     * Used as a "trash bin" when removing files from a parent model — instead
     * of soft-deleting and orphaning the physical file, the rows revert to
     * TEMPORARY status. The existing cleanupExpired() job sweeps both the DB
     * row AND the physical file in MinIO once expires_at passes.
     *
     * Within the recovery window the same file can be re-attached to any
     * model by including its id in another syncFiles() call.
     *
     * @param  array<int, string>  $fileIds
     */
    public function revertToTemp(array $fileIds, int $hoursUntilExpiry = 24): int
    {
        if (empty($fileIds)) {
            return 0;
        }

        return File::whereIn('id', $fileIds)->update([
            'fileable_type' => null,
            'fileable_id' => null,
            'status' => FileStatusEnum::Temporary,
            'expires_at' => now()->addHours($hoursUntilExpiry),
        ]);
    }

    /**
     * Replace a model's `gallery` collection with exactly $fileIds (#1666).
     *
     * Lifted verbatim out of `ProductImageController::sync` and
     * `ProductSkuImageController::sync`, which held the SAME twelve lines —
     * same transaction, same 24h recovery window. Two copies of a rule about
     * when an image becomes deletable is two places for that window to drift.
     *
     * The transaction is the point: step 1 detaches the dropped files (setting
     * `fileable_id = NULL`), and step 2 relies on that having happened —
     * `syncFiles()`'s own soft-delete step is a no-op precisely because the
     * removed rows already left the morphMany scope. Committing step 1 without
     * step 2 leaves the gallery EMPTY while those files sit in a 24h recovery
     * window: a product silently loses its images and they are physically
     * swept a day later.
     *
     * @param  Model  $owner  any model using the HasFiles trait
     * @param  array<int, string>  $fileIds  the complete new gallery, in order
     */
    public function syncGallery(Model $owner, array $fileIds): void
    {
        DB::transaction(function () use ($owner, $fileIds): void {
            // 1. Gallery files being dropped → back to TEMPORARY with a 24h
            //    expiry. `cleanupExpired()` sweeps them physically after that.
            $removedIds = $owner->files()
                ->where('collection', 'gallery')
                ->whereNotIn('id', $fileIds)
                ->pluck('id')
                ->all();

            $this->revertToTemp($removedIds, 24);

            // 2. Attach + reorder what remains.
            $owner->syncFiles($fileIds, 'gallery');
        });
    }

    /**
     * Do all of $fileIds belong to $organizationId?
     *
     * Returns a bool rather than aborting: whether "no" means 403, 404 or a
     * validation error is the caller's decision. Guards a real hijack — without
     * it a user could attach another org's temp uploads to their own product.
     *
     * @param  array<int, string>  $fileIds
     */
    public function filesBelongToOrganization(array $fileIds, string $organizationId): bool
    {
        if (empty($fileIds)) {
            return true;
        }

        return File::whereIn('id', $fileIds)
            ->where('organization_id', $organizationId)
            ->count() === count($fileIds);
    }

    /**
     * Clean up all expired temporary files.
     *
     * @return int Number of files cleaned up
     */
    public function cleanupExpired(): int
    {
        $count = 0;

        File::expired()->chunkById(100, function ($files) use (&$count) {
            foreach ($files as $file) {
                $this->delete($file);
                $count++;
            }
        });

        return $count;
    }
}
