<?php

declare(strict_types=1);

use App\Mcp\Servers\TempoServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP (Streamable HTTP) — MCP Service Contract
|--------------------------------------------------------------------------
| Platform-issued access tokens protect the MCP surface exactly like REST.
*/

Mcp::web('/mcp', TempoServer::class)
    ->middleware('sso.auth')
    ->name('mcp.tempo');
