# Technology Stack

## Go Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| `github.com/wailsapp/wails/v3` | v3.0.0-alpha.74 | Desktop app framework |
| `modernc.org/sqlite` | latest | Pure Go SQLite (no CGO) |
| `github.com/gorilla/websocket` | v1.5+ | WebSocket server |
| `github.com/grandcat/zeroconf` | latest | mDNS/Zeroconf discovery |
| `golang.org/x/text` | latest | Shift_JIS encoding cho printer |
| `github.com/google/uuid` | latest | UUID generation |

## Frontend Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| `react` | 19.x | UI framework |
| `react-dom` | 19.x | React DOM renderer |
| `typescript` | 5.x | Type safety |
| `vite` | 7.x | Build tool |
| `@omnifyjp/ui` | latest | Shared UI components (Radix + Tailwind) |
| `tailwindcss` | 4.x | Utility-first CSS |
| `@tanstack/react-query` | 5.x | Server state management |
| `lucide-react` | latest | Icons |
| `react-router` | 7.x | Client-side routing |

## Dev Dependencies

| Tool | Purpose |
|------|---------|
| `wails3` CLI | Dev server, build, generate bindings |
| `pnpm` | Frontend package manager |
| `make` | Build automation |

## Build Matrix

| Target | GOOS | GOARCH | Output |
|--------|------|--------|--------|
| macOS Apple Silicon | darwin | arm64 | ws-app-darwin-arm64 |
| macOS Intel | darwin | amd64 | ws-app-darwin-amd64 |
| Linux x64 | linux | amd64 | ws-app-linux-amd64 |
| Linux ARM | linux | arm64 | ws-app-linux-arm64 |
| Windows x64 | windows | amd64 | ws-app-windows-amd64.exe |

## Why These Choices

### Wails v3 (not Electron, not Tauri)
- Go backend aligns with existing godx tooling
- Smaller binary than Electron (~20MB vs ~150MB)
- Native OS access for printer/USB devices
- React frontend reuses @omnifyjp/ui components
- Alpha risk accepted for newer features (multi-window, system tray)

### modernc.org/sqlite (not mattn/go-sqlite3)
- Pure Go = no CGO dependency
- Cross-compilation works out of the box (`GOOS=windows go build`)
- mattn/go-sqlite3 requires C compiler per target platform

### ESC/POS (not CUPS/IPP)
- Direct control over print output
- Works on all receipt/thermal printers
- No driver installation needed
- Standard in POS/restaurant industry

### @omnifyjp/ui (not Ant Design)
- Shared across omnify ecosystem
- Built on Radix UI (accessible, unstyled primitives)
- Tailwind CSS for consistent styling
- Components already packaged and tested
