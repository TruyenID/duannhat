<?php

/**
 * File Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\File\Models\FileBaseModel;
use App\Traits\AuditsActivity;
use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * File — centralized file management with temp/permanent flow.
 */
class File extends FileBaseModel
{
    use AuditsActivity;
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): FileFactory
    {
        return FileFactory::new();
    }

    /**
     * Create a new Eloquent Collection instance.
     *
     * Workaround for abstract BaseModel + Laravel 13 HasCollection.
     *
     * @param  array<int, Model>  $models
     */
    public function newCollection(array $models = []): Collection
    {
        return new Collection($models);
    }

    /**
     * Get the public URL for this file.
     *
     * ALWAYS absolute. The `local` disk's driver returns a host-less
     * `/storage/...` path, which the browser then resolves against whatever
     * origin is currently open — customer-web (`menu.vietorigin.jp`), not the
     * backend that actually serves the file. Every such image 404s while the
     * rows uploaded under an absolute-URL disk load fine, so the breakage looks
     * random and does not reproduce locally (local MinIO always yields a host).
     *
     * `files.disk` is stored PER ROW — it records whatever
     * `config('filesystems.default')` was at upload time — so a production
     * storage-config change leaves both kinds of row behind permanently. This
     * is the single funnel for all 38 getUrl() call sites (admin, customer-web,
     * kiosk, workstation replica), which is why the host is repaired here
     * rather than by migrating the `disk` column.
     */
    public function getUrl(): ?string
    {
        $disk = (string) $this->disk;
        $configuration = config("filesystems.disks.{$disk}");

        if (! is_array($configuration)) {
            return null;
        }

        if (($configuration['driver'] ?? null) === 's3'
            && (! filled($configuration['region'] ?? null) || ! filled($configuration['bucket'] ?? null))) {
            return null;
        }

        try {
            $url = Storage::disk($disk)->url($this->path);
        } catch (InvalidArgumentException) {
            return null;
        }

        return str_starts_with($url, '/')
            ? rtrim((string) config('app.url'), '/').$url
            : $url;
    }

    /**
     * Get the full filesystem path.
     */
    public function getFullPath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }

    /**
     * Check if the file is temporary.
     */
    public function isTemporary(): bool
    {
        return $this->status === FileStatusEnum::Temporary;
    }
}
