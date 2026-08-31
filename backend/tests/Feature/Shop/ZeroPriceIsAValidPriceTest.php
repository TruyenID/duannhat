<?php

declare(strict_types=1);

use App\Http\Requests\ShopOverrideSkuPriceRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

/**
 * #2052 — giá 0 là MỘT MỨC GIÁ, không phải "chưa nhập".
 *
 * ## Vì sao đây là luật, không phải sở thích
 *
 * Hàng tặng, quà khuyến mãi, món kèm trong combo, đổi điểm, hàng mẫu — mọi mô
 * hình POS chuẩn (ARTS/NRF) đều cho dòng giá 0. Thứ phải chặn là giá **ÂM**:
 * đó là giảm giá hoặc hoàn tiền, khái niệm khác và đi đường khác (sổ
 * `order_conditions`, không phải bảng giá).
 *
 * ## Luật này TỰ MỌC LẠI, nên phải có rào
 *
 * Hai lý do, cả hai đều đã xảy ra trong repo này:
 *
 * 1. **`0` là falsy trong JS.** `if (!price)` và `price <= 0` là phản xạ tự
 *    nhiên của người viết tiếp, và nó lặng lẽ biến "miễn phí" thành "chưa
 *    nhập" (#2024, và lại lần nữa ở `sku-table.tsx` — #2052).
 * 2. **`min:0.01` trông như một luật.** Nó không phải: nó không chặn được ai
 *    tặng hàng (hạ xuống 0,01 là lách xong), chỉ chặn đúng trường hợp hợp lệ.
 *    Kiểm soát "ai được hạ giá" thuộc QUYỀN HẠN, không thuộc khoảng giá trị.
 *
 * ## Bất biến mạnh hơn: MỌI bề mặt nhập giá phải nói cùng một luật
 *
 * #2052 nổ ra chính vì shop và HQ nói khác nhau — HQ `min:0`, shop `min:0.01`.
 * Một người dựng combo tặng ở HQ thì được, làm đúng việc đó ở shop thì ăn 422
 * và không hiểu vì sao. Test cuối quét toàn bộ FormRequest có trường giá và
 * bắt bất kỳ ai đặt sàn khác 0.
 */
function validatesPrice(string $requestClass, mixed $value): bool
{
    $rules = (new $requestClass)->rules();
    $key = array_key_exists('selling_price', $rules) ? 'selling_price' : 'skus.*.selling_price';

    if (! array_key_exists($key, $rules)) {
        return false;
    }

    $payload = $key === 'selling_price'
        ? ['selling_price' => $value]
        : ['skus' => [['selling_price' => $value]]];

    return ! Validator::make($payload, [$key => $rules[$key]])->fails();
}

/**
 * Chỉ `ShopOverrideSkuPriceRequest` khởi tạo trần được. `ProductSkuStoreRequest`
 * và `ProductSkuUpdateRequest` ném `HttpException: Unauthenticated` vì `rules()`
 * của chúng cần ngữ cảnh người dùng đã xác thực.
 *
 * KHÔNG giả lập auth để lách: một test phải dàn dựng nửa framework mới chạy được
 * thì phần lớn thứ nó chứng minh là về dàn dựng. Hai lớp đó được phủ bởi phép
 * quét TOÀN BỘ `Http/Requests` ở test cuối file — phép quét ấy mạnh hơn, vì nó
 * cũng phủ cả bề mặt thứ tư mà người sau sẽ thêm.
 */
dataset('bề mặt nhập giá', [
    'shop override giá SKU trong menu' => [ShopOverrideSkuPriceRequest::class],
]);

it('chấp nhận giá 0 — hàng tặng là chuyện thật', function (string $class) {
    expect(validatesPrice($class, 0))->toBeTrue("{$class} từ chối giá 0");
    expect(validatesPrice($class, '0'))->toBeTrue("{$class} từ chối chuỗi '0'");
    expect(validatesPrice($class, 0.0))->toBeTrue("{$class} từ chối 0.0");
})->with('bề mặt nhập giá');

it('từ chối giá ÂM — đó là giảm giá, đi đường khác', function (string $class) {
    expect(validatesPrice($class, -1))->toBeFalse("{$class} nhận giá âm");
    expect(validatesPrice($class, -0.01))->toBeFalse("{$class} nhận giá âm nhỏ");
})->with('bề mặt nhập giá');

it('shop override BẮT BUỘC có giá — bỏ trống không phải là 0', function () {
    // Phân biệt hai chuyện dễ lẫn: "món này miễn phí" (0) và "tôi chưa nhập"
    // (thiếu trường). Gộp chúng lại chính là cách `0` bị coi là chưa nhập.
    $rules = (new ShopOverrideSkuPriceRequest)->rules();

    expect(Validator::make([], $rules)->fails())->toBeTrue('bỏ trống mà vẫn qua');
    expect(validatesPrice(ShopOverrideSkuPriceRequest::class, 'miễn phí'))->toBeFalse('chuỗi chữ mà vẫn qua');
});

it('BẤT BIẾN: không FormRequest nào đặt sàn giá khác 0', function () {
    // #2052 nổ ra vì shop nói `min:0.01` còn HQ nói `min:0`. Một người dựng
    // combo tặng ở HQ thì được, làm đúng việc đó ở shop thì ăn 422 và không
    // hiểu vì sao. Rào quét toàn bộ, không chỉ ba lớp ở trên — vì bề mặt thứ
    // tư sẽ do người khác thêm.
    $offenders = [];

    foreach (File::allFiles(app_path('Http/Requests')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());

        // Bắt `min:0.01`, `min:1`, `gt:0`… trên bất kỳ trường nào tên *price*.
        if (preg_match_all('/[\'"]([a-z_.*]*price[a-z_]*)[\'"]\s*=>\s*(\[[^\]]*\]|[\'"][^\'"]*[\'"])/i', $source, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                [$whole, $field, $rule] = $match;
                if (preg_match('/min:(0\.0*[1-9]\d*|[1-9]\d*)|gt:\s*0/', $rule, $bad)) {
                    $offenders[] = sprintf(
                        '  %s — %s: %s',
                        str_replace(base_path().'/', '', $file->getPathname()),
                        $field,
                        $bad[0],
                    );
                }
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['Có FormRequest đặt sàn giá LỚN HƠN 0:', ''],
        $offenders,
        [
            '',
            'Giá 0 là một mức giá hợp lệ — hàng tặng, quà khuyến mãi, món kèm',
            'combo, đổi điểm. Dùng `min:0`. Thứ phải chặn là giá ÂM.',
            '',
            'Nếu cần chặn ai đó hạ giá, chốt ở QUYỀN HẠN chứ đừng chốt ở khoảng',
            'giá trị: `min:0.01` không chặn được ai — hạ xuống 0,01 là lách xong.',
        ],
    )));
});

it('rào CÒN CHẠY — khuôn bắt được min:0.01 và không bắt nhầm min:0', function () {
    // Chống xanh giả: một regex hỏng cho 0 phát hiện và đọc y hệt "đã sạch".
    $bad = "'selling_price' => ['required', 'numeric', 'min:0.01'],";
    $ok = "'selling_price' => ['required', 'numeric', 'min:0'],";

    $pattern = '/[\'"]([a-z_.*]*price[a-z_]*)[\'"]\s*=>\s*(\[[^\]]*\]|[\'"][^\'"]*[\'"])/i';

    preg_match($pattern, $bad, $mBad);
    preg_match($pattern, $ok, $mOk);

    expect(preg_match('/min:(0\.0*[1-9]\d*|[1-9]\d*)|gt:\s*0/', $mBad[2] ?? ''))->toBe(1);
    expect(preg_match('/min:(0\.0*[1-9]\d*|[1-9]\d*)|gt:\s*0/', $mOk[2] ?? ''))->toBe(0);
});
