<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d slice 0 (#1897) — bản đối ứng PHP của `printKindPlan`
 * (workstation `internal/service/print_renderer.go`).
 *
 * Buộc các emitter của một KIND vào khổ giấy mặc định và cặp prologue/epilogue
 * đóng khung mọi phiếu của kind đó.
 *
 * Mỗi plan được dẫn xuất từ ĐÚNG MỘT formatter cũ bên Go — đó là điều làm cổng
 * parity chứng minh được theo từng kind, thay vì chứng minh chung chung.
 */
final class PrintKindPlan
{
    /**
     * @param  int  $defaultWidth  bề rộng nội dung khi người gọi không chỉ định
     * @param  array<string, callable(PrintRenderContext, array<string, mixed>): void>  $emitters
     *                                                                                             block id → hàm vẽ. CHỈ những id ở đây được vẽ; block lạ bị bỏ
     *                                                                                             qua, vì một definition cũ hơn code không được làm hỏng cả phiếu.
     * @param  (callable(PrintRenderContext): void)|null  $prologue  lề, canh lề, chuẩn bị thuế
     * @param  (callable(PrintRenderContext): void)|null  $epilogue  feed + cắt giấy
     * @param  bool  $japaneseDoc  chứng từ này LÀ chứng từ Nhật (適格簡易請求書) — #1493.
     *
     * Thuộc tính của KIND, không phải của locale. Trước #1493 các emitter rẽ
     * theo `locale == "ja"`, nên NGÔN NGỮ GIAO DIỆN của thu ngân quyết định
     * CHỨNG TỪ NÀO được in: quán Việt để tiếng Nhật ra chứng từ Nhật, quán
     * Nhật để tiếng Việt ra hoá đơn GTGT. Cloud đã ràng kind theo quốc gia
     * (`PrintTemplateKind::countries()`), nên rẽ theo kind LÀ rẽ theo quốc
     * gia.
     */
    public function __construct(
        public readonly int $defaultWidth,
        public readonly array $emitters,
        public readonly mixed $prologue = null,
        public readonly mixed $epilogue = null,
        public readonly bool $japaneseDoc = false,
    ) {}

    /**
     * Cùng plan này, nhưng là chứng từ Nhật.
     *
     * Tồn tại để `qualified_simplified_invoice` DÙNG LẠI plan của `vat_invoice`
     * thay vì chép nó ra bản thứ hai — đúng như Go (`p := vatInvoicePlan();
     * p.japaneseDoc = true`). Hai chứng từ có cùng danh sách block; khác biệt
     * Nhật–Việt nằm bên trong từng emitter.
     *
     * Nếu ai đó thấy hàm này thừa và định thay bằng một plan viết riêng: đó
     * chính là thứ nó ngăn. Hai bản chép sẽ trôi khỏi nhau, và cái trôi ra là
     * một chứng từ thuế.
     */
    public function withJapaneseDoc(): self
    {
        return new self(
            defaultWidth: $this->defaultWidth,
            emitters: $this->emitters,
            prologue: $this->prologue,
            epilogue: $this->epilogue,
            japaneseDoc: true,
        );
    }

    /** Block id mà plan này biết vẽ, đã sắp xếp — khoá đối chiếu hợp đồng. */
    public function blockIds(): array
    {
        $ids = array_keys($this->emitters);
        sort($ids);

        return $ids;
    }

    /**
     * Emitter cho một block id, hoặc null khi kind này không biết id đó.
     *
     * Trả null thay vì ném: definition do brand soạn và có thể mang block của
     * một bản code mới hơn. Bỏ qua một block lạ in ra thiếu một phần; ném ra
     * thì KHÔNG IN ĐƯỢC GÌ, và với một tờ hoá đơn đang cần thì đó là hỏng
     * nặng hơn hẳn.
     *
     * @return (callable(PrintRenderContext, array<string, mixed>): void)|null
     */
    public function emitterFor(string $blockId): ?callable
    {
        $emitter = $this->emitters[$blockId] ?? null;

        return is_callable($emitter) ? $emitter : null;
    }
}
