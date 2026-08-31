<?php

declare(strict_types=1);

namespace App\Services\Print;

use App\Exceptions\Print\TemplateValidationException;
use App\Models\Branch;
use App\Models\Brand;
use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\Renderer\PrintKindRegistry;
use App\Services\Shop\SellerRegistrationResolver;

/**
 * plan-053 M2 (#1171) — the publish gate (DESIGN §4, TASKS T2.1).
 *
 * Runs ONCE, at publish, and never at print (TR-14). That asymmetry is the
 * whole safety model: everything that could make a slip wrong is caught in
 * front of the person who wrote it, and the print path stays unconditional —
 * a shop must never be unable to sell because a definition is bad.
 *
 * The checks, in DESIGN §4 order:
 *   1  envelope + every block id in the catalog and in this kind
 *   2  locked blocks untouched (content, props AND relative order — TR-16)
 *   3  every required block present, and enabled where the law says so (TR-17)
 *   4  a shop override stays inside the brand's `shop_editable` (TR-03)
 *   5  i18n covers 3 locales or declares `fallback` (TR-19)
 *   6  render trial across 2 papers × 3 locales × 2 text modes ({@see RenderProbe})
 *   7  logo/image sane; oversize is CLAMPED, not rejected (TR-22)
 *   8  a block the author NEWLY enabled has an emitter ({@see checkRenderable},
 *      #1949) — this list said "seven checks" for a while after the eighth
 *      landed, and the guide inherited the number
 *  plus the two absolutes: no arithmetic in a definition (TR-15) and no
 *  `source` outside the allow-list (TR-21).
 */
class TemplateValidator
{
    public function __construct(
        private readonly BlockCatalog $catalog,
        private readonly SystemTemplateDefaults $defaults,
        private readonly DefinitionMerger $merger,
        private readonly PrintKindRegistry $registry,
        private readonly RenderProbe $renderProbe,
        private readonly SellerRegistrationResolver $registrationResolver,
    ) {}

    /**
     * Validate a definition for publish.
     *
     * Returns the definition it would publish — identical to the input except
     * for [CLAMP] corrections (TR-22). Throws on anything else.
     *
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $brandShopEditable  the brand's allow-list (shop scope only)
     * @return array<string, mixed>
     *
     * @throws TemplateValidationException
     */
    public function validateForPublish(
        array $definition,
        PrintTemplateKind $kind,
        PrintTemplateScope $scope,
        ?Brand $brand = null,
        ?Branch $branch = null,
        array $brandShopEditable = [],
    ): array {
        $violations = [];

        // A BRAND definition is the WHOLE document — omitting `tax_breakdown`
        // means "I want it gone", and the answer to that has to be a 422, not
        // a silent restore. A SHOP definition is a partial OVERLAY of the few
        // fields the brand delegated, so the structural rules are checked
        // against what would actually print (overlay merged onto the layer
        // below), not against the fragment on its own.
        $subject = $scope === PrintTemplateScope::Shop
            ? $this->merger->merge($this->defaults->forKind($kind), $definition)
            : $definition;

        $violations = array_merge($violations, $this->checkEnvelope($subject));
        // Reported against what the author WROTE, so the path in the error is
        // a path they recognise.
        $violations = array_merge($violations, $this->checkNoArithmetic($definition));

        $blocks = is_array($subject['blocks'] ?? null) ? $subject['blocks'] : [];
        $violations = array_merge($violations, $this->checkBlocks($blocks, $kind));
        $violations = array_merge($violations, $this->checkLockedOrder($blocks, $kind));
        $violations = array_merge($violations, $this->checkRequired($blocks, $kind, $brand, $branch));
        $violations = array_merge($violations, $this->checkI18n($blocks));
        $violations = array_merge($violations, $this->checkRenderable($blocks, $kind));

        if ($scope === PrintTemplateScope::Shop) {
            $violations = array_merge($violations, $this->checkShopEditable($definition, $kind, $brandShopEditable));
        }

        // [CLAMP] before the render trial: an oversize logo is corrected, and
        // the trial must run on what would actually be published.
        [$definition, $clampNotes] = $this->clampImages($definition);
        $violations = array_merge($violations, $clampNotes);

        if ($violations === []) {
            // Only worth rendering something structurally sound; otherwise the
            // probe just repeats the errors already reported above.
            $violations = array_merge(
                $violations,
                $this->renderProbe->probe($this->effectiveForProbe($definition, $kind, $scope), $kind),
            );
        }

        if ($violations !== []) {
            throw new TemplateValidationException(array_values($violations));
        }

        return $definition;
    }

    /**
     * The `shop_editable` allow-list itself must reference real blocks — a
     * brand cannot delegate a field that does not exist.
     *
     * @param  list<string>  $paths
     *
     * @throws TemplateValidationException
     */
    public function validateShopEditable(array $paths, PrintTemplateKind $kind): void
    {
        $kindBlocks = $this->catalog->kindBlocks($kind);
        $violations = [];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                $violations[] = $this->violation('SHOP_EDITABLE_INVALID', 'shop_editable', 'Allow-list entries must be non-empty strings.');

                continue;
            }
            [$blockId, $prop] = array_pad(explode('.', $path, 2), 2, null);

            if (! in_array($blockId, $kindBlocks, true)) {
                $violations[] = $this->violation(
                    'SHOP_EDITABLE_UNKNOWN_BLOCK',
                    "shop_editable.{$path}",
                    "Block [{$blockId}] is not part of kind [{$kind->value}].",
                );

                continue;
            }

            // Delegating a locked block would let a shop do what HQ itself
            // cannot — the compliance blocks belong to the system (DESIGN §1).
            if ($this->catalog->mutability($blockId) === BlockCatalog::MUTABILITY_LOCKED) {
                $violations[] = $this->violation(
                    'SHOP_EDITABLE_LOCKED_BLOCK',
                    "shop_editable.{$path}",
                    "Block [{$blockId}] is locked and can never be delegated to a shop.",
                );

                continue;
            }

            $editable = $this->catalog->editableProps($blockId);
            if ($prop !== null && ! in_array($prop, $editable, true)) {
                $violations[] = $this->violation(
                    'SHOP_EDITABLE_UNKNOWN_PROP',
                    "shop_editable.{$path}",
                    "Prop [{$prop}] is not editable on block [{$blockId}].",
                );
            }
        }

        if ($violations !== []) {
            throw new TemplateValidationException($violations);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<array{code: string, path: string, message: string}>
     */
    private function checkEnvelope(array $definition): array
    {
        $violations = [];

        if (($definition['schema'] ?? null) !== $this->catalog->schema()) {
            $violations[] = $this->violation(
                'SCHEMA_MISMATCH',
                'schema',
                'Definition schema must be '.$this->catalog->schema().'.',
            );
        }

        if (! is_array($definition['blocks'] ?? null) || ! array_is_list($definition['blocks'])) {
            $violations[] = $this->violation('BLOCKS_MALFORMED', 'blocks', 'The `blocks` key must be an ordered list.');
        }

        if (array_key_exists('paper', $definition) && ! is_array($definition['paper'])) {
            $violations[] = $this->violation('PAPER_MALFORMED', 'paper', 'The `paper` key must be an object.');
        }

        return $violations;
    }

    /**
     * TR-15 — a definition PRESENTS, it never COMPUTES.
     *
     * Any `{{ … }}` placeholder is rejected outright: the renderer binds data
     * by block id and `source`, so a placeholder can only be an attempt to
     * express logic. That keeps the rule impossible to creep — there is no
     * "safe subset" of an expression language to argue about later, and no way
     * for a template to disagree with the money engine (#1154).
     *
     * @param  array<string, mixed>  $definition
     * @return list<array{code: string, path: string, message: string}>
     */
    private function checkNoArithmetic(array $definition): array
    {
        $violations = [];

        $walk = function (mixed $node, string $path) use (&$walk, &$violations): void {
            if (is_array($node)) {
                foreach ($node as $key => $child) {
                    $walk($child, $path === '' ? (string) $key : "{$path}.{$key}");
                }

                return;
            }
            if (! is_string($node)) {
                return;
            }
            if (preg_match('/\{\{.*?\}\}|\$\{.*?\}/u', $node) === 1) {
                $violations[] = $this->violation(
                    'EXPRESSION_NOT_ALLOWED',
                    $path,
                    'A template may not contain expressions or placeholders — money and tax come from the engine, never from the definition.',
                );
            }
        };

        $walk($definition, '');

        return $violations;
    }

    /**
     * @param  list<mixed>  $blocks
     * @return list<array{code: string, path: string, message: string}>
     */
    private function checkBlocks(array $blocks, PrintTemplateKind $kind): array
    {
        $violations = [];
        $kindBlocks = $this->catalog->kindBlocks($kind);
        $seen = [];

        foreach ($blocks as $index => $block) {
            if (! is_array($block) || ! isset($block['id']) || ! is_string($block['id'])) {
                $violations[] = $this->violation('BLOCK_MALFORMED', "blocks.{$index}", 'Every block needs a string `id`.');

                continue;
            }
            $id = $block['id'];
            $path = "blocks.{$id}";

            if (isset($seen[$id])) {
                $violations[] = $this->violation('BLOCK_DUPLICATED', $path, "Block [{$id}] appears more than once.");

                continue;
            }
            $seen[$id] = true;

            if (! $this->catalog->hasBlock($id)) {
                $violations[] = $this->violation('BLOCK_UNKNOWN', $path, "Block [{$id}] is not in the catalog.");

                continue;
            }
            if (! in_array($id, $kindBlocks, true)) {
                $violations[] = $this->violation(
                    'BLOCK_NOT_IN_KIND',
                    $path,
                    "Block [{$id}] is not part of kind [{$kind->value}].",
                );

                continue;
            }

            $catalogType = (string) ($this->catalog->block($id)['type'] ?? '');
            if (isset($block['type']) && $block['type'] !== $catalogType) {
                $violations[] = $this->violation(
                    'BLOCK_TYPE_MISMATCH',
                    "{$path}.type",
                    "Block [{$id}] is of type [{$catalogType}] in the catalog.",
                );
            }

            $violations = array_merge($violations, $this->checkBlockProps($id, $block, $path));
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<array{code: string, path: string, message: string}>
     */
    private function checkBlockProps(string $id, array $block, string $path): array
    {
        $violations = [];
        $editable = $this->catalog->editableProps($id);
        $isLocked = $this->catalog->mutability($id) === BlockCatalog::MUTABILITY_LOCKED;

        foreach ($block as $prop => $value) {
            if (in_array($prop, ['id', 'type'], true)) {
                continue;
            }
            if (! in_array($prop, $editable, true)) {
                $violations[] = $this->violation(
                    $isLocked || $this->catalog->isLocked($id) ? 'LOCKED_BLOCK_MODIFIED' : 'PROP_NOT_EDITABLE',
                    "{$path}.{$prop}",
                    "Prop [{$prop}] cannot be set on block [{$id}].",
                );

                continue;
            }

            $enum = $this->catalog->propEnum($id, $prop);
            if ($enum !== null) {
                foreach ((array) $value as $item) {
                    if (! in_array($item, $enum, true)) {
                        $violations[] = $this->violation(
                            'PROP_VALUE_NOT_ALLOWED',
                            "{$path}.{$prop}",
                            'Value ['.(is_scalar($item) ? (string) $item : gettype($item))."] is not allowed for [{$prop}].",
                        );
                    }
                }
            }

            if ($prop === 'source') {
                // TR-21 — a URL here would make the whole fleet fetch an
                // attacker-chosen address and pipe the bytes at a printer.
                if (! is_string($value) || ! in_array($value, $this->catalog->sources(), true)) {
                    $violations[] = $this->violation(
                        'SOURCE_NOT_ALLOWED',
                        "{$path}.source",
                        'Source must be one of the allow-listed data bindings; arbitrary URLs are never accepted.',
                    );
                }
            }

            if ($prop === 'fields') {
                foreach ((array) $value as $field) {
                    if (! in_array($field, $this->catalog->paramFields(), true)) {
                        $violations[] = $this->violation(
                            'PARAM_FIELD_NOT_ALLOWED',
                            "{$path}.fields",
                            'Field ['.(is_scalar($field) ? (string) $field : gettype($field)).'] is not an allow-listed parameter.',
                        );
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * TR-16 — a locked block may be POSITIONED by the system default only.
     * Reordering 税額 above 小計, or moving 「再発行」 below the total, changes
     * what the document means, so the relative order of locked blocks must
     * match the system default exactly.
     *
     * @param  list<mixed>  $blocks
     * @return list<array{code: string, path: string, message: string}>
     */
    private function checkLockedOrder(array $blocks, PrintTemplateKind $kind): array
    {
        $expected = $this->catalog->lockedBlockOrder($kind);

        $actual = [];
        foreach ($blocks as $block) {
            $id = is_array($block) ? ($block['id'] ?? null) : null;
            if (is_string($id) && $this->catalog->isLocked($id) && in_array($id, $expected, true)) {
                $actual[] = $id;
            }
        }

        // Compare against the expected order reduced to the blocks actually
        // present — a definition is allowed to omit an optional locked block,
        // it just may not shuffle the ones it keeps.
        $expectedPresent = array_values(array_filter($expected, fn (string $id): bool => in_array($id, $actual, true)));

        if ($actual !== $expectedPresent) {
            return [$this->violation(
                'LOCKED_BLOCK_REORDERED',
                'blocks',
                'Compliance blocks must keep their system order: '.implode(' → ', $expectedPresent).'.',
            )];
        }

        return [];
    }

    /**
     * @param  list<mixed>  $blocks
     * @return list<array{code: string, path: string, message: string}>
     */
    private function checkRequired(array $blocks, PrintTemplateKind $kind, ?Brand $brand, ?Branch $branch): array
    {
        $violations = [];

        $byId = [];
        foreach ($blocks as $block) {
            if (is_array($block) && isset($block['id']) && is_string($block['id'])) {
                $byId[$block['id']] = $block;
            }
        }

        foreach ($this->catalog->requiredBlocks($kind) as $required) {
            if (! isset($byId[$required])) {
                $violations[] = $this->violation(
                    'REQUIRED_BLOCK_MISSING',
                    "blocks.{$required}",
                    "Kind [{$kind->value}] requires block [{$required}].",
                );

                continue;
            }

            $enabled = $byId[$required]['enabled'] ?? true;

            // A locked block is unconditionally on — turning it off is the
            // 赤伝 case (TR-18): you may not print a credit note that does not
            // say it is one.
            if ($enabled === false && ! $this->catalog->isToggleable($required)) {
                $violations[] = $this->violation(
                    'LOCKED_BLOCK_DISABLED',
                    "blocks.{$required}.enabled",
                    "Block [{$required}] is locked and cannot be disabled.",
                );

                continue;
            }

            // TR-17 / #1152 — the toggle is legal only while the seller has no
            // registration number. Once there IS one, printing it is required.
            if ($enabled === false && $this->conditionHolds($this->catalog->requireEnabledWhen($required), $brand, $branch)) {
                $violations[] = $this->violation(
                    'REQUIRED_BLOCK_DISABLED',
                    "blocks.{$required}.enabled",
                    "Block [{$required}] must stay enabled: the seller has a registration number and it must be printed.",
                );
            }
        }

        return $violations;
    }

    /** Evaluate a catalog `require_enabled_when` condition. */
    private function conditionHolds(?string $condition, ?Brand $brand, ?Branch $branch): bool
    {
        if ($condition === null) {
            return false;
        }

        return match ($condition) {
            // #1152 resolution order is branch override → brand default.
            'seller_has_registration_number' => $branch !== null
                ? $this->registrationResolver->resolve($branch) !== null
                : trim((string) ($brand?->invoice_registration_number ?? '')) !== '',
            default => false,
        };
    }

    /**
     * #1949 — một block ĐÃ BẬT phải có emitter, nếu không nó im lặng biến mất.
     *
     * Catalog và registry là hai danh sách khác nhau, và không cổng nào trước
     * đây so chúng: `PrintContractParityTest` so registry PHP ↔ registry Go
     * (hai bên khớp nhau), còn `print_cloud_parity_test` so HASH của bản render
     * (hai bên cùng bỏ qua block lạ nên hash vẫn khớp).
     *
     * Nên một block có trong catalog mà không renderer nào vẽ sẽ qua hết bảy
     * kiểm, kể cả lượt render thử — lượt thử CHẠY THÀNH CÔNG, nó chỉ không vẽ
     * gì. Người phát hiện là chủ quán cầm tờ giấy thiếu một khối.
     *
     * Kiểm ở đây thay vì ở lượt render thử là có chủ đích: lượt thử chỉ biết
     * "có bytes hay không", còn chỗ này biết ĐÍCH DANH block nào, nên câu từ
     * chối nói được tên nó ra.
     *
     * ⚠️ Chỉ chặn thứ TÁC GIẢ VỪA BẬT, không chặn thứ họ thừa hưởng.
     *
     * Bản đầu của kiểm này chặn mọi block bật mà thiếu emitter, và nó **chặn
     * luôn bản mặc định hệ thống**: `discounts` / `invoice_number` là block
     * `locked`, không khai `enabled` nên mặc định BẬT, và chưa từng có emitter ở
     * bất cứ đâu. Kết quả là KHÔNG brand nào publish được gì — đúng cái bẫy
     * "định nghĩa trung thực không publish nổi" mà lỗ #4 của #1181 mô tả.
     *
     * Nên mốc so là **bản mặc định hệ thống**: chặn khi tác giả bật một block mà
     * mặc định để TẮT (hoặc không có). Nợ thừa hưởng thì để
     * `CatalogRenderableRatchetTest` đếm, không chặn ai.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function checkRenderable(array $blocks, PrintTemplateKind $kind): array
    {
        $plan = $this->registry->planFor($kind->value);

        if ($plan === null) {
            // Kind chưa có plan nào — đó là nợ ở tầng khác, và chặn publish vì
            // nó thì không giúp được ai.
            return [];
        }

        // Nợ đã biết, khai ở `print_blocks.renderable_debt`. Bỏ qua chúng là
        // CỐ Ý: chặn cả nợ thừa hưởng thì bản mặc định hệ thống cũng không
        // publish nổi (`discounts`/`invoice_number` là block locked, mặc định
        // BẬT, chưa từng có emitter ở đâu), và một luật chặn tất cả thì bị tắt.
        // Nó vẫn chặn mọi block MỚI rơi vào cùng tình trạng.
        $debt = (array) config('print_blocks.renderable_debt.'.$kind->value, []);
        $known = is_array($debt) ? array_flip($debt) : [];

        $violations = [];

        foreach ($blocks as $index => $block) {
            if (! is_array($block)) {
                continue;
            }

            $id = is_string($block['id'] ?? null) ? $block['id'] : null;

            if ($id === null || ($block['enabled'] ?? true) !== true) {
                continue;
            }

            if (isset($known[$id]) || $plan->emitterFor($id) !== null) {
                continue;
            }

            $violations[] = $this->violation(
                'BLOCK_NOT_RENDERABLE',
                "blocks.{$index}",
                "Block `{$id}` is enabled but no renderer draws it — it would vanish from the slip with no error.",
            );
        }

        return $violations;
    }

    /**
     * TR-19 — a `text` block covers all three locales, or says out loud that
     * it does not (`fallback: true`). Silence is the failure mode we are
     * closing: a Vietnamese branch printing an empty footer because HQ only
     * typed Japanese.
     *
     * @param  list<mixed>  $blocks
     * @return list<array{code: string, path: string, message: string}>
     */
    private function checkI18n(array $blocks): array
    {
        $violations = [];
        /** @var list<string> $locales */
        $locales = array_values((array) config('print_templates.locales', ['ja', 'en', 'vi']));

        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'text') {
                continue;
            }
            if (($block['enabled'] ?? true) === false) {
                continue;
            }
            $id = (string) ($block['id'] ?? '?');
            $i18n = is_array($block['i18n'] ?? null) ? $block['i18n'] : [];
            $declaresFallback = ($block['fallback'] ?? false) === true;

            $missing = array_values(array_filter(
                $locales,
                fn (string $locale): bool => ! is_string($i18n[$locale] ?? null) || $i18n[$locale] === '',
            ));

            if ($missing === [] || $declaresFallback) {
                continue;
            }

            $violations[] = $this->violation(
                'I18N_INCOMPLETE',
                "blocks.{$id}.i18n",
                'Missing locale(s): '.implode(', ', $missing).'. Provide them or set `fallback: true`.',
            );
        }

        return $violations;
    }

    /**
     * TR-03 — a shop may only touch what the brand delegated. Enforced here at
     * PUBLISH as well as in the UI (which disables the other fields), because
     * a disabled input is a courtesy, not a boundary.
     *
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $allowList
     * @return list<array{code: string, path: string, message: string}>
     */
    private function checkShopEditable(array $definition, PrintTemplateKind $kind, array $allowList): array
    {
        $base = $this->defaults->forKind($kind);
        $changed = $this->merger->changedPaths($base, $definition);
        $violations = [];

        foreach ($changed as $path) {
            if ($this->pathAllowed($path, $allowList)) {
                continue;
            }
            $violations[] = $this->violation(
                'SHOP_FIELD_NOT_EDITABLE',
                $path,
                "Field [{$path}] is not in this brand's `shop_editable` allow-list.",
            );
        }

        return $violations;
    }

    /** @param list<string> $allowList */
    private function pathAllowed(string $path, array $allowList): bool
    {
        foreach ($allowList as $allowed) {
            if (! is_string($allowed) || $allowed === '') {
                continue;
            }
            if ($path === $allowed || str_starts_with($path, $allowed.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * TR-22 [CLAMP] — an image wider than the paper is scaled to fit, not
     * rejected. A brand uploading a 4000px logo made a harmless mistake; a
     * failed publish over it would be the system being pedantic about
     * something it can simply fix. Anything the system CANNOT fix (an unknown
     * source, a bad format) is a hard reject in {@see checkBlockProps}.
     *
     * @param  array<string, mixed>  $definition
     * @return array{0: array<string, mixed>, 1: list<array{code: string, path: string, message: string}>}
     */
    private function clampImages(array $definition): array
    {
        $rules = $this->catalog->imageRules();
        $max = (int) ($rules['printable_dots_80mm'] ?? 576);
        $violations = [];

        foreach ($definition['blocks'] ?? [] as $index => $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'image') {
                continue;
            }
            $width = $block['max_width_dots'] ?? null;
            if ($width === null) {
                continue;
            }
            if (! is_int($width) && ! (is_string($width) && ctype_digit($width))) {
                $violations[] = $this->violation(
                    'IMAGE_WIDTH_INVALID',
                    "blocks.{$block['id']}.max_width_dots",
                    'Image width must be an integer number of dots.',
                );

                continue;
            }
            if ((int) $width > $max) {
                $definition['blocks'][$index]['max_width_dots'] = $max;
            }
        }

        return [$definition, $violations];
    }

    /**
     * What the render trial should actually render: a shop override is a
     * PARTIAL definition, so probing it on its own would miss overflow in the
     * blocks it inherits. Merge it onto the system default first.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function effectiveForProbe(array $definition, PrintTemplateKind $kind, PrintTemplateScope $scope): array
    {
        return $scope === PrintTemplateScope::Shop
            ? $this->merger->merge($this->defaults->forKind($kind), $definition)
            : $definition;
    }

    /** @return array{code: string, path: string, message: string} */
    private function violation(string $code, string $path, string $message): array
    {
        return ['code' => $code, 'path' => $path, 'message' => $message];
    }
}
