<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;

/**
 * `GET /downloads` (+ the `/ws-downloads` alias) — now a permanent redirect.
 *
 * The page itself moved to admin-web (Next). Laravel has no business rendering
 * HTML for this product, and the Blade version could not reach the app's own
 * ja/en/vi catalogue: it carried Japanese and Vietnamese on the same line and
 * had no English at all.
 *
 * What did NOT move: the binaries and `manifest.json`. They are still served
 * straight out of `public/downloads/workstation/` by the web server, which is
 * why this class no longer touches WorkstationDownloadCatalog — that service
 * stays in use by the expected-build feed.
 *
 * The routes stay because the old URL is in circulation: the workstation's own
 * "your build is out of date" message points at `/downloads` (see
 * `workstation/internal/handler/update.go`), and so do notes handed to shops.
 */
final class WorkstationDownloadsController extends Controller
{
    public function index(): RedirectResponse|Response
    {
        $target = trim((string) Config::get('workstation.downloads.page_url', ''));

        if ($target === '') {
            // Deliberately loud, and deliberately not a guess. A 301 to the
            // wrong host is cached by every browser that sees it, and nobody
            // would be able to tell from the outside that it was wrong.
            // Meanwhile the files are still reachable, so say where.
            return response(
                "The workstation download page has moved to Admin Web, but\n"
                ."WORKSTATION_DOWNLOADS_PAGE_URL is not configured on this server.\n\n"
                ."The release files are unaffected and can still be fetched directly:\n"
                ."  /downloads/workstation/manifest.json\n",
                503,
            )->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return redirect()->away($target, 301);
    }
}
