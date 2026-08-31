---
title: Documentation Standards
category: contributing
tags: [documentation, writing-standards, diataxis, template, formatting, review-checklist]
summary: Pointer — the documentation standard is maintained once for the whole monorepo at docs/contributing/documentation.md.
related: [api-development]
---

# Documentation Standards

> **Đã dời.** Bản chuẩn: **[`docs/contributing/documentation.md`](../../../docs/contributing/documentation.md)** ở gốc repo umbrella.

Chuẩn viết tài liệu áp cho **mọi** bộ doc trong monorepo — `docs/`,
`backend/docs/`, và `docs/` của từng app trong cây — nên nó chỉ được có một bản.

Trước đây ở đây có bản thứ hai, và hai bản đã trôi khỏi nhau theo hai hướng khác
nhau (#1322): bản này thiếu hẳn luật **frontmatter YAML** — trong khi chính các
doc trong `backend/docs/` đều đang có frontmatter — còn bản umbrella thì thiếu
phần mẫu theo từng loại. Nay bản umbrella có cả hai; mẫu nằm ở
[`docs/contributing/templates/`](../../../docs/contributing/templates/).

Chỗ khác biệt duy nhất còn lại là **chỉ mục**: `backend/CLAUDE.md` yêu cầu thêm
mỗi doc mới vào [`backend/docs/README.md`](../README.md), không phải vào chỉ mục
của umbrella.
