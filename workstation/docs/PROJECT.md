# ws-app - Workstation Desktop Application

## Overview

ws-app la ung dung may tram quan tri danh cho quan (nha hang, cafe, bar). App chay tren desktop (Windows, Mac, Linux) va dong vai tro **trung tam dieu phoi** tai quan:

- Ket noi va quan ly may in (receipt, kitchen, bar)
- Lam server trung gian tren LAN de tablet/dien thoai dat order nhanh
- Ket noi may goi nhan vien
- Ket noi may thu ngan (POS)
- Sync du lieu voi Omnify/DXS cloud server
- **Offline-first**: Cac tinh nang chinh van hoat dong khi mat internet

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Desktop Framework | Wails v3 (Go + Web frontend) |
| Backend Language | Go 1.25+ |
| Frontend | React 19 + TypeScript |
| UI Components | @omnifyjp/ui (Radix UI + Tailwind CSS) |
| Local Database | SQLite via modernc.org/sqlite (pure Go) |
| Real-time | WebSocket (gorilla/websocket) |
| Device Discovery | mDNS/Zeroconf |
| Printer Protocol | ESC/POS |
| Cloud Sync | Omnify/DXS Platform REST API |

## Module Name

```
github.com/dxs-platform/ws-app
```

## Target Platforms

| Platform | Architecture | Priority |
|----------|-------------|----------|
| macOS | arm64, amd64 | High |
| Windows | amd64 | High |
| Linux | amd64, arm64 | Medium |
