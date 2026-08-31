# Evidence TC-226..250 (sau fix F1+F3) — curl + content-check + Pest
_batch thứ 10, trùng TC-101..225_

| TC | HTTP | Đạt? | Loại |
|----|------|------|------|
| TC-226 | 200 | ✅ | OK |
| TC-227 | 200 | ✅ | OK |
| TC-228 | 200 | ✅ | OK |
| TC-229 | 200 | ✅ | OK |
| TC-230 | 200 | ✅ | OK |
| TC-231 | 201 | ✅ | OK |
| TC-232 | 422 | ✅ | OK |
| TC-233 | 422 | ✅ | F3 fixed |
| TC-234 | 201 | ✅ | F2 by-design |
| TC-235 | 200 | ✅ | OK |
| TC-236 | 404 | ✅ | F1 fixed |
| TC-237 | 200 | ✅ | OK |
| TC-238 | 200 | ✅ | OK |
| TC-239 | 204 | ✅ | OK |
| TC-240 | 200 | ✅ | OK |
| TC-241 | 200 | ✅ | OK |
| TC-242 | 200 | ✅ | OK |
| TC-243 | 200 | ✅ | OK |
| TC-244 | pass | ✅ | OK |
| TC-245 | 422 | ✅ | F2 by-design |
| TC-246 | 200 | ✅ | OK |
| TC-247 | pass | ✅ | OK |
| TC-248 | 200 | ✅ | OK |
| TC-249 | 200 | ✅ | OK |
| TC-250 | 201 | ✅ | OK |

**25/25 đạt assertion. 0 bug mới.**

By-design: TC-234 (Staff create →201) + TC-245 (Staff import) = F2 flat-org. Xem BY-DESIGN-explain.md.
