# Evidence TC-251..273 (sau fix F1+F3) — curl + content-check + Pest
_batch thứ 11, trùng (23 case, không có 2 case Unit cuối)_

| TC | HTTP | Đạt? | Loại |
|----|------|------|------|
| TC-251 | 200 | ✅ | OK |
| TC-252 | 200 | ✅ | OK |
| TC-253 | 200 | ✅ | OK |
| TC-254 | 200 | ✅ | OK |
| TC-255 | 200 | ✅ | OK |
| TC-256 | 201 | ✅ | OK |
| TC-257 | 422 | ✅ | OK |
| TC-258 | 422 | ✅ | F3 fixed |
| TC-259 | 201 | ✅ | F2 by-design |
| TC-260 | 200 | ✅ | OK |
| TC-261 | 404 | ✅ | F1 fixed |
| TC-262 | 200 | ✅ | OK |
| TC-263 | 200 | ✅ | OK |
| TC-264 | 204 | ✅ | OK |
| TC-265 | 200 | ✅ | OK |
| TC-266 | 200 | ✅ | OK |
| TC-267 | 200 | ✅ | OK |
| TC-268 | 200 | ✅ | OK |
| TC-269 | pass | ✅ | OK |
| TC-270 | 422 | ✅ | F2 by-design |
| TC-271 | 200 | ✅ | OK |
| TC-272 | pass | ✅ | OK |
| TC-273 | 200 | ✅ | OK |

**23/23 đạt assertion. 0 bug mới.**

By-design (F2): TC-259 + TC-270 — xem BY-DESIGN-explain.md.
