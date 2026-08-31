<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShopOverrideSkuPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `min:0` chứ KHÔNG phải `min:0.01` (#2052).
            //
            // Giá 0 là một mức giá hợp lệ: hàng tặng, quà khuyến mãi, món kèm
            // trong combo, đổi điểm, hàng mẫu. Mọi mô hình POS chuẩn (ARTS/NRF)
            // đều cho phép dòng giá 0; thứ phải chặn là giá ÂM, vì đó là giảm
            // giá / hoàn tiền — khái niệm khác, đi đường khác.
            //
            // HQ vốn đã cho phép (`ProductStoreRequest`, `ProductSkuStoreRequest`
            // đều `min:0`), nên `min:0.01` ở đây còn làm shop và HQ nói hai luật
            // khác nhau về cùng một trường.
            //
            // Nếu sau này cần chặn nhân viên shop tự tặng hàng, chốt ở QUYỀN
            // HẠN chứ đừng chốt ở khoảng giá trị: `min:0.01` không chặn được ai
            // — hạ xuống 0,01 là lách xong.
            'selling_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
