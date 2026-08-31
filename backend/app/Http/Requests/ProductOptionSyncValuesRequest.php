<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for `PUT /hq/{brandSlug}/product-options/{option}/sync-values`.
 *
 * Single batch endpoint that replaces N per-row PUT/POST/DELETE calls when
 * the user edits an option's values panel. The FE buffers all edits locally
 * and submits one diff on "Xong".
 */
class ProductOptionSyncValuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            // Optional rename of the option's display name. Slug (key) is never
            // editable via this endpoint — it's referenced by SKUs.
            'name' => ['nullable', 'string', 'max:120'],

            // Authoritative list. Any existing value whose id is missing is
            // soft-deleted; rows with an id are renamed; rows without an id
            // are inserted. Array order is the new display order — the
            // service re-numbers `position` 1..N based on this array.
            // #2488 — optimistic concurrency. FE gửi danh sách id giá trị mà
            // bản nháp được hydrate từ đó. Vì values là "danh sách toàn quyền"
            // (thiếu id nào là XOÁ id đó), một bản nháp hydrate từ dữ liệu cũ
            // sẽ xoá giá trị nó chưa từng nhìn thấy — hai tab admin cùng mở là
            // kịch bản thật trên production. Nếu tập id đang sống khác tập FE
            // biết, service từ chối 409 thay vì ghi đè im lặng. Nullable để
            // client cũ / gọi tay không gãy.
            'known_value_ids' => ['nullable', 'array'],
            'known_value_ids.*' => ['string', 'uuid'],

            'values' => ['required', 'array', 'min:1'],
            'values.*.id' => ['nullable', 'string', 'uuid'],
            'values.*.value' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/'],
            'values.*.label' => ['required', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'values.required' => 'At least one option value is required.',
            'values.min' => 'At least one option value is required.',
            'values.*.value.regex' => 'The slug may only contain lowercase letters, digits, underscores, and hyphens.',
            'values.*.label.required' => 'Each value must have a label.',
        ];
    }
}
