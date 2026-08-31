---
title: Setup with Docker
category: guide
tags: [setup, installation, docker, development]
summary: Pointer — the Docker setup guide is maintained at the umbrella docs/guide/setup-docker.md, because docker-compose.yml lives at the repo root and the stack it builds covers more than the backend.
related:
  - guide/setup-local.md
---

# Setup with Docker

> **Đã dời.** Bản chuẩn: **[`docs/guide/setup-docker.md`](../../../docs/guide/setup-docker.md)** ở gốc repo umbrella.

`docker-compose.yml` nằm ở **gốc umbrella**, không nằm trong `backend/`, và stack
nó dựng gồm cả `mysql`, `minio`, `mailpit` lẫn profile `admin-web` — rộng hơn
phạm vi backend. Nên tài liệu sống ở umbrella.

Chỉ được có MỘT bản. Trước đây ở đây có một bản sao riêng, hai bản trôi khỏi
nhau và bản này dạy sai (#1322) — nên nó bị rút gọn thành con trỏ. Đừng chép nội
dung ngược về đây.

## Next steps

- [Setup without Docker (Herd)](./setup-local.md) — chạy native trên macOS
- [SSO Authentication](./sso-authentication.md) — luồng đăng nhập
- [Architecture](../reference/architecture.md) — cấu trúc project
