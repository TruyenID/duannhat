<?php

use App\Http\Controllers\Web\WorkstationDownloadsController;
use Illuminate\Support\Facades\Route;

// The page itself lives in admin-web now; these two only 301 to it. Keep them:
// the old URL is printed by the workstation's out-of-date warning and handed to
// shops in writing. The FILES under /downloads/workstation/ are untouched —
// they are static, served by the web server, and never reach this router.
Route::get('/downloads', [WorkstationDownloadsController::class, 'index'])
    ->name('downloads.workstation');
// Alias if Apache DirectorySlash still wins over public/.htaccess on a host.
Route::get('/ws-downloads', [WorkstationDownloadsController::class, 'index'])
    ->name('downloads.workstation.alias');
