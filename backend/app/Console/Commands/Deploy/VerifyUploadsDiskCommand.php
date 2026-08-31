<?php

declare(strict_types=1);

namespace App\Console\Commands\Deploy;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * Fail the deploy if `filesystems.uploads` does not name a real disk (#2184).
 *
 * THE FAILURE THIS CATCHES. `UPLOADS_DISK=` in `.env` — the key PRESENT with an
 * EMPTY value — resolves `config('filesystems.uploads')` to `""`, and
 * `Storage::disk('')` throws `InvalidArgumentException`. Every upload then
 * returns 500. Nothing before this check notices: the app boots, the caches
 * build, the deploy reports success.
 *
 * WHY IT CANNOT BE A TEST. The suite runs against the test environment; this
 * asserts on the `.env` that was actually deployed, on the machine it was
 * deployed to. Only a deploy-time check can see that file.
 *
 * The two failures are reported separately on purpose. "Empty" points at the
 * `.env` key; "not a disk" points at `filesystems.disks`. One message covering
 * both would send an operator to the wrong file half the time.
 */
final class VerifyUploadsDiskCommand extends Command
{
    protected $signature = 'deploy:verify-uploads-disk';

    protected $description = 'Assert filesystems.uploads names a configured disk on the deployed .env';

    public function handle(): int
    {
        $disk = (string) config('filesystems.uploads');

        if ($disk === '') {
            throw new RuntimeException(
                'filesystems.uploads is EMPTY — check UPLOADS_DISK in .env (key present with a blank value?). '
                .'Storage::disk("") throws, so every upload would 500.'
            );
        }

        if (! array_key_exists($disk, (array) config('filesystems.disks'))) {
            throw new RuntimeException(sprintf(
                'filesystems.uploads = [%s] does not exist in filesystems.disks.',
                $disk,
            ));
        }

        $this->info(sprintf('filesystems.uploads = [%s] — configured.', $disk));

        return self::SUCCESS;
    }
}
