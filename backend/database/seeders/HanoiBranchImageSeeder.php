<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class HanoiBranchImageSeeder extends Seeder
{
    /**
     * High-quality Unsplash imagery for the Hanoi branch, stored as DIRECT CDN
     * URLs rather than downloaded + uploaded to MinIO.
     *
     * Why direct URLs: the staging stack serves customer-web over a public
     * cloudflared tunnel. A `http://localhost:5490/...` MinIO URL is both
     * unreachable from a remote browser and mixed-content-blocked on the HTTPS
     * tunnel, so an uploaded banner silently fails to render. Direct https
     * Unsplash URLs load everywhere and pass through the RebaseStorageUrl cast
     * untouched (the cast only rebases our own `branches/` object keys).
     */
    private const HANOI_IMAGES = [
        // Logo: Vietnamese coffee theme (square crop).
        'logo' => 'https://images.unsplash.com/photo-1559056199-641a0ac8b55e?w=400&h=400&fit=crop',

        // Banner: Hanoi street scene / Vietnamese architecture (wide crop).
        'banner' => 'https://images.unsplash.com/photo-1583417319070-4a69db38a482?w=1200&h=400&fit=crop',
    ];

    public function run(): void
    {
        $hanoi = Branch::where('slug', 'hanoi')->first();

        if (! $hanoi) {
            $this->command->warn('Hanoi branch not found. Run GlobalMultiTimezoneSeeder first.');

            return;
        }

        $hanoi->update([
            'logo' => self::HANOI_IMAGES['logo'],
            'img_branches' => self::HANOI_IMAGES['banner'],
        ]);

        $this->command->info("HanoiBranchImageSeeder: logo + banner set for {$hanoi->name}.");
    }
}
