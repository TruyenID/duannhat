<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

use RuntimeException;

/**
 * plan-053 T5.1d slice 0 (#1897) — điểm vào DESIGN §7 phía Cloud:
 * `render(definition, data, profile, locale) -> bytes`.
 *
 * Bản đối ứng của `RenderPrintTemplate` (workstation
 * `internal/service/print_renderer.go`), giữ NGUYÊN từng nhánh quyết định.
 *
 * ── Nó KHÔNG BAO GIỜ trả về một phiếu vẽ dở ─────────────────────────────
 *
 * Kind không có plan, hay definition không dùng được, là LỖI mà **người gọi**
 * trả lời bằng cách rơi về bản mặc định nhúng sẵn (TR-14). Việc của renderer
 * là trung thực về thứ nó không làm được. Một phiếu in ra thiếu nửa dưới còn
 * tệ hơn một phiếu không in ra: cái thứ hai thì thu ngân biết, cái thứ nhất
 * thì khách cầm đi.
 *
 * ── Block LẠ thì bỏ qua, block KIND KHÔNG BIẾT cũng bỏ qua ──────────────
 *
 * Definition do brand soạn và có thể mang block của một bản code mới hơn, hoặc
 * block của một kind khác. Bỏ qua nó in ra thiếu một phần; ném ra thì không in
 * được gì. Đây là bất đối xứng CÓ CHỦ ĐÍCH so với "kind không có plan": thiếu
 * một block là mất một phần phiếu, thiếu cả plan là không biết phiếu này trông
 * ra sao.
 *
 * ── Registry đã có plan cho 13/14 kind ──────────────────────────────────
 *
 * Docblock này từng ghi *"{@see PrintKindRegistry} rỗng cho tới slice 1-3, nên
 * mọi lời gọi thật ở slice này đều ném"* — đúng ở slice 0 (#1897), sai từ khi
 * các slice emitter hạ cánh, và câu cũ vẫn nằm đây tới #2061. Registry được nạp
 * ở `AppServiceProvider` bởi `BillKindPlans` · `DocsKindPlans` ·
 * `ShiftKindPlans`; Cloud TỰ phát được phiếu.
 *
 * Cái vẫn đúng, và là lý do câu "ném khi thiếu plan" tồn tại: một kind KHÔNG có
 * plan thì ném — ĐÚNG như Go ném. Thiếu một block là mất một phần phiếu, thiếu
 * cả plan là không biết phiếu này trông ra sao.
 */
final class PrintRenderer
{
    /** Bề rộng cuối cùng khi mọi nguồn khác đều im lặng — giống Go. */
    private const FALLBACK_WIDTH = 48;

    public function __construct(private readonly PrintKindRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $definition  definition ĐÃ CHUẨN HOÁ (xem DefinitionNormalizer)
     * @param  ?ReceiptTaxSummary  $taxBreakdown  ảnh chụp thuế theo mức của đơn, đọc từ
     *                                            `OrderTaxBreakdownReads` — KHÔNG tính ở đây.
     *                                            `null` ⇒ khối thuế rơi về dòng "Thuế" gộp, đúng
     *                                            như Go làm với đơn không có snapshot mức nào.
     *
     * @throws RuntimeException khi không render được — người gọi rơi về mặc định
     */
    public function render(
        array $definition,
        PrintRenderData $data,
        PrintRenderProfile $profile,
        string $locale,
        ?ReceiptTaxSummary $taxBreakdown = null,
    ): PrintRenderResult {
        $kind = $data->kind !== '' ? $data->kind : (string) ($definition['kind'] ?? '');

        if ($kind === '') {
            throw new RuntimeException('print render: definition và data đều không nói đây là kind gì');
        }

        $plan = $this->registry->planFor($kind);

        if ($plan === null) {
            throw new RuntimeException(sprintf('print render: chưa có renderer cho kind %s', $kind));
        }

        $normalized = Definition::normalizeLocale($locale);

        $context = new PrintRenderContext(
            encoder: new Escpos,
            definition: $definition,
            data: $data,
            config: $data->config,
            locale: $normalized,
            width: $this->resolveWidth($definition, $data->config, $profile, $plan->defaultWidth),
            japaneseDoc: $plan->japaneseDoc,
            labels: PrintLabels::forLocale($normalized),
            tax: TaxLabels::forLocale($normalized),
            // #1937 — renderer KHÔNG tự đi lấy snapshot. Nó không có `branchId`,
            // không có `orderId` cho tới khi VO mang, và một lượt truy vấn ở đây
            // biến hàm thuần này thành thứ không render nổi khi DB chậm. Người
            // gọi đọc `OrderTaxBreakdownReads` rồi đưa xuống — cùng kỷ luật với
            // `$data->now` (#1091): renderer nhận sự thật đã giải, không tự đoán.
            taxBreakdown: $taxBreakdown,
            // #1950 — cách cắt giấy là thuộc tính của MÁY, đi cùng profile.
            finishing: $profile->finishing,
        );

        $segments = [];
        // Offset bắt đầu từ 0, KHÔNG phải từ độ dài hiện có của encoder: chuỗi
        // khởi tạo máy in mà `new Escpos` vừa ghi thuộc về đoạn prologue.
        $offset = 0;

        $record = function (string $blockId) use ($context, &$segments, &$offset): void {
            $full = $context->encoder->bytes();

            if (strlen($full) === $offset) {
                return; // block không sinh byte nào — không có đoạn để ghi
            }

            $segments[] = new PrintRenderSegment($blockId, substr($full, $offset));
            $offset = strlen($full);
        };

        if (is_callable($plan->prologue)) {
            ($plan->prologue)($context);
        }
        // #3082 — dòng trắng đầu phiếu, TRONG đoạn prologue.
        //
        // Bếp kẹp phiếu vào thanh kẹp và thanh kẹp che mất dòng đầu, nên phần bị
        // che phải là giấy trắng chứ không phải chữ.
        //
        // Nằm trong prologue chứ không thành đoạn riêng: `$record` chỉ ghi đoạn
        // khi có byte mới, và một đoạn toàn LF không có gì để raster hoá.
        // Bản Go làm y hệt ở `print_renderer.go` — hai bên phải phát cùng byte,
        // nếu không `TestCloudDefaults_Golden_ByteParity` đỏ.
        $topFeed = (int) ($definition['top_feed'] ?? 0);
        if ($topFeed > 0) {
            $context->encoder->feed($topFeed);
        }
        $record(PrintRenderSegment::PROLOGUE);

        foreach ($this->blocksOf($definition) as $block) {
            $id = (string) ($block['id'] ?? '');
            $emitter = $id === '' ? null : $plan->emitterFor($id);

            if ($emitter === null || ! $this->isEnabled($block)) {
                continue;
            }

            // #3082 — cỡ chữ bọc NGOÀI emitter, không nằm trong từng emitter.
            //
            // Mỗi emitter tự đọc `size` thì 20+ chỗ phải nhớ làm, và cái quên sẽ
            // im lặng. Bọc ở đây thì prop áp cho mọi khối bằng một chỗ, và khối
            // chưa đặt `size` phát ra đúng byte như hôm nay.
            //
            // Trả về NORMAL_SIZE ngay sau khối: rò rỉ sang khối kế là biến một
            // lựa chọn cục bộ thành trạng thái toàn phiếu.
            $tall = ($block['size'] ?? null) === 'tall';
            if ($tall) {
                $context->encoder->size(Escpos::DOUBLE_HEIGHT);
            }
            $emitter($context, $block);
            if ($tall) {
                $context->encoder->size(Escpos::NORMAL_SIZE);
            }
            $record($id);
        }

        if (is_callable($plan->epilogue)) {
            ($plan->epilogue)($context);
            $record(PrintRenderSegment::EPILOGUE);
        }

        return new PrintRenderResult($segments, $context->encoder->bytes());
    }

    /**
     * Thang giải bề rộng, đúng thứ tự của `resolvePrintWidth` bên Go:
     * profile → config → bảng `paper` của definition → mặc định của kind → 48.
     *
     * Thứ tự này là một quyết định, không phải tuỳ tiện: máy in đứng trước
     * cấu hình quán vì nó là sự thật vật lý, còn brand đứng sau cùng vì bảng
     * `paper` là ý định thiết kế chứ không phải phần cứng.
     *
     * @param  array<string, mixed>  $definition
     */
    private function resolveWidth(
        array $definition,
        PrintJobConfig $config,
        PrintRenderProfile $profile,
        int $kindDefault,
    ): int {
        if ($profile->columns > 0) {
            return $profile->columns;
        }

        if ($config->paperWidth > 0) {
            return $config->paperWidth;
        }

        $fromPaper = $this->columnsForPaper($definition, $profile->paper);
        if ($fromPaper > 0) {
            return $fromPaper;
        }

        return $kindDefault > 0 ? $kindDefault : self::FALLBACK_WIDTH;
    }

    /** @param array<string, mixed> $definition */
    private function columnsForPaper(array $definition, string $paper): int
    {
        $paperMap = $definition['paper'] ?? null;

        if (! is_array($paperMap)) {
            return 0;
        }

        $key = match (strtolower(trim($paper))) {
            '58', '58mm' => 'columns_58mm',
            '80', '80mm' => 'columns_80mm',
            default => null,
        };

        return $key === null ? 0 : (int) ($paperMap[$key] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $block
     *
     * @see Definition::blockIsEnabled() — luật ở đó, một bản duy nhất
     */
    private function isEnabled(array $block): bool
    {
        return Definition::blockIsEnabled($block);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<array<string, mixed>>
     */
    private function blocksOf(array $definition): array
    {
        $blocks = $definition['blocks'] ?? [];

        if (! is_array($blocks)) {
            return [];
        }

        return array_values(array_filter($blocks, 'is_array'));
    }
}
