<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BranchLogoSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        $disk = config('filesystems.default');
        $branches = Branch::whereNotNull('slug')->get();

        if ($branches->isEmpty()) {
            $this->command->warn('No branches found to seed logos.');

            return;
        }

        $count = 0;

        foreach ($branches as $branch) {
            try {
                // Download logo from LoremFlickr (square 400x400 for logos)
                $response = Http::timeout(20)->get('https://loremflickr.com/400/400/restaurant,cafe,food');

                if (! $response->successful()) {
                    $this->command->warn("  {$branch->name}: failed to download logo (HTTP {$response->status()})");

                    continue;
                }

                // Generate unique filename
                $ulid = (string) Str::ulid();
                $path = "branches/logos/{$ulid}.jpg";

                // Upload to S3/MinIO
                Storage::disk($disk)->put($path, $response->body());

                // Get public URL
                $url = Storage::disk($disk)->url($path);

                // Update branch
                $branch->update(['logo' => $url]);

                $this->command->info("  {$branch->name}: logo uploaded → {$url}");
                $count++;
            } catch (\Exception $e) {
                $this->command->error("  {$branch->name}: error uploading logo → {$e->getMessage()}");
            }
        }

        $this->command->info("BranchLogoSeeder: {$count} logos uploaded.");
    }
}
