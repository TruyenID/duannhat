<?php

declare(strict_types=1);

use App\Services\Print\Renderer\PrintRenderItem;
use App\Services\Print\Renderer\PrintRenderTopping;
use Carbon\CarbonImmutable;

/**
 * plan-053 T5.1d (#1925).
 *
 * Hai hành vi đáng ghim, và cả hai đều là chỗ hỏng IM LẶNG nếu port sai:
 * cách một dòng bị coi là void, và `null` khác `0.0` ở mức thuế.
 */
it('#1925 null KHÁC 0.0 ở taxRate — chưa đóng dấu không phải 非課税', function () {
    // Gộp hai cái này là lỗi #1128 vừa chặn ở đường pull thuế: một dòng chưa
    // được đóng dấu mức sẽ hiện ra như 非課税 và không ai nhìn thấy.
    $chuaDongDau = new PrintRenderItem(menuItemName: 'Bento', quantity: 1, unitPrice: 1000);
    $mienThue = new PrintRenderItem(menuItemName: 'Sách', quantity: 1, unitPrice: 500, taxRate: 0.0);

    expect($chuaDongDau->taxRate)->toBeNull()
        ->and($mienThue->taxRate)->toBe(0.0);
});

it('#1925 void nhận diện qua HAI đường, không phải một', function (PrintRenderItem $item, bool $voided) {
    // Go kiểm `VoidedAt != nil` HOẶC `Status == voided`. Giữ cả hai: một dòng
    // void qua đường trạng thái mà chưa kịp đóng dấu thời điểm vẫn phải biến
    // mất khỏi giấy.
    expect($item->isVoided())->toBe($voided);
})->with([
    'còn sống' => [new PrintRenderItem(menuItemName: 'A', quantity: 1, unitPrice: 100), false],
    'có VoidedAt' => [new PrintRenderItem(menuItemName: 'A', quantity: 1, unitPrice: 100, voidedAt: CarbonImmutable::parse('2026-08-05T00:00:00Z')), true],
    'chỉ có Status' => [new PrintRenderItem(menuItemName: 'A', quantity: 1, unitPrice: 100, status: 'voided'), true],
    'trạng thái khác' => [new PrintRenderItem(menuItemName: 'A', quantity: 1, unitPrice: 100, status: 'served'), false],
]);

it('#1925 cơ sở chịu thuế ưu tiên toppingSubtotal ĐÃ CHỐT', function () {
    // Thứ tự ưu tiên chép từ `itemTaxableSubtotal`: con số đã chốt thắng phép
    // cộng lại, vì nó là thứ engine đã dùng khi tính tiền.
    $item = new PrintRenderItem(
        menuItemName: 'Ramen',
        quantity: 2,
        unitPrice: 1000,
        toppings: [new PrintRenderTopping(name: 'Trứng', quantity: 1, unitPrice: 999)],
        toppingSubtotal: 300,
    );

    expect($item->taxableSubtotal())->toBe(2300);
});

it('#1925 toppingSubtotal = 0 ⇒ CỘNG LẠI từ toppings', function () {
    $item = new PrintRenderItem(
        menuItemName: 'Ramen',
        quantity: 2,
        unitPrice: 1000,
        toppings: [
            new PrintRenderTopping(name: 'Trứng', quantity: 1, unitPrice: 100),
            new PrintRenderTopping(name: 'Măng', quantity: 2, unitPrice: 50),
        ],
    );

    expect($item->taxableSubtotal())->toBe(2000 + 100 + 100);
});
