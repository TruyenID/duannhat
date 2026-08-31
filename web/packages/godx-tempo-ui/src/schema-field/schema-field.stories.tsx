import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useState } from "react";
import { SchemaField, type SchemaMetadata } from "./schema-field";

// ─── Mock Metadata ───────────────────────────────────────────────────────────
// Inline fixtures so stories are self-contained and don't break when schemas
// regenerate. Shape mirrors Omnify-generated `{entity}Metadata`.

const ZONE_METADATA: SchemaMetadata = {
  modelName: "Zone",
  fields: {
    code: {
      type: "string",
      required: true,
      nullable: false,
      translatable: false,
      validation: { maxLength: 50 },
      label: { ja: "エリアコード", en: "Zone Code", vi: "Mã khu vực" },
      description: {
        ja: "店舗内で一意のコード。英数字とハイフンのみ。",
        en: "Unique code per branch. Alphanumeric and hyphens only.",
        vi: "Mã unique trong shop. Chỉ chữ, số, gạch ngang.",
      },
    },
    name: {
      type: "string",
      required: true,
      nullable: false,
      translatable: false,
      validation: { maxLength: 255 },
      label: { ja: "エリア名", en: "Zone Name", vi: "Tên khu vực" },
    },
    description: {
      type: "text",
      required: false,
      nullable: true,
      translatable: false,
      validation: {},
      label: { ja: "説明", en: "Description", vi: "Mô tả" },
    },
    display_order: {
      type: "number",
      required: true,
      nullable: false,
      translatable: false,
      validation: { min: 0, max: 999 },
      label: { ja: "表示順", en: "Display Order", vi: "Thứ tự hiển thị" },
    },
    is_active: {
      type: "boolean",
      required: true,
      nullable: false,
      translatable: false,
      validation: {},
      label: { ja: "有効", en: "Active", vi: "Hoạt động" },
      description: {
        ja: "無効なエリアはテーブル管理画面に表示されません。",
        en: "Inactive zones are hidden from the table management screen.",
        vi: "Khu vực vô hiệu sẽ ẩn khỏi màn hình quản lý bàn.",
      },
    },
    branch_id: {
      type: "uuid",
      required: true,
      nullable: false,
      translatable: false,
      validation: {},
      label: { ja: "店舗", en: "Branch", vi: "Chi nhánh" },
      relation: { model: "Branch", table: "branches", onDelete: "CASCADE" },
    },
  },
  translatableFields: [],
  enumFields: [],
  relationFields: ["branch_id"],
};

const PRODUCT_METADATA: SchemaMetadata = {
  modelName: "Product",
  fields: {
    name: {
      type: "string",
      required: true,
      nullable: false,
      translatable: true,
      validation: { maxLength: 255 },
      label: { ja: "商品名", en: "Product Name", vi: "Tên sản phẩm" },
    },
    description: {
      type: "text",
      required: false,
      nullable: true,
      translatable: true,
      validation: {},
      label: { ja: "説明", en: "Description", vi: "Mô tả" },
    },
    slug: {
      type: "string",
      required: true,
      nullable: false,
      translatable: false,
      validation: { maxLength: 100 },
      label: { ja: "スラッグ", en: "Slug", vi: "Slug" },
    },
    status: {
      type: "enum",
      required: true,
      nullable: false,
      translatable: false,
      validation: {},
      label: { ja: "ステータス", en: "Status", vi: "Trạng thái" },
      enum: "ProductStatus",
      enumValues: ["draft", "pending", "approved", "active", "inactive", "rejected"],
      enumLabels: {
        draft: { ja: "下書き", en: "Draft", vi: "Nháp" },
        pending: { ja: "申請中", en: "Pending", vi: "Chờ duyệt" },
        approved: { ja: "承認済", en: "Approved", vi: "Đã duyệt" },
        active: { ja: "販売中", en: "Active", vi: "Đang bán" },
        inactive: { ja: "停止", en: "Inactive", vi: "Ngừng bán" },
        rejected: { ja: "却下", en: "Rejected", vi: "Từ chối" },
      },
    },
    is_hidden: {
      type: "boolean",
      required: true,
      nullable: false,
      translatable: false,
      validation: {},
      label: { ja: "非公開", en: "Hidden", vi: "Ẩn" },
    },
    product_type_id: {
      type: "uuid",
      required: true,
      nullable: false,
      translatable: false,
      validation: {},
      label: { ja: "商品タイプ", en: "Product Type", vi: "Loại sản phẩm" },
      relation: { model: "ProductType", table: "product_types" },
    },
  },
  translatableFields: ["name", "description"],
  enumFields: ["status"],
  relationFields: ["product_type_id"],
};

// ─── Mock Lookup Data ────────────────────────────────────────────────────────

const BRANCH_OPTIONS = [
  { value: "b1", label: "本社" },
  { value: "b2", label: "渋谷店" },
  { value: "b3", label: "新宿店" },
  { value: "b4", label: "池袋店" },
];

const PRODUCT_TYPE_OPTIONS = [
  { value: "pt1", label: "フード" },
  { value: "pt2", label: "ドリンク" },
  { value: "pt3", label: "デザート" },
];

// ─── Storybook Meta ──────────────────────────────────────────────────────────

const meta: Meta<typeof SchemaField> = {
  title: "UI/SchemaField",
  component: SchemaField,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component: [
          "Metadata-driven form field. Reads Omnify-generated `{entity}Metadata` and",
          "automatically renders the correct input component (Input, Textarea, Switch,",
          "Select, Combobox) with proper label, placeholder, validation, and error display.",
          "",
          "**Type mapping:**",
          "",
          "| `type` | `translatable` | Component |",
          "|--------|---------------|-----------|",
          "| `string` | `false` | `<Input>` |",
          "| `string` | `true` | `<Input translatable>` |",
          "| `text` | `false` | `<Textarea>` |",
          "| `text` | `true` | `<Textarea translatable>` |",
          "| `number` | — | `<Input type=\"number\">` |",
          "| `boolean` | — | `<Switch>` |",
          "| `enum` | — | `<Select>` |",
          "| `uuid` (relation) | — | `<Combobox>` |",
          "| `date` | — | `<Input type=\"date\">` |",
          "| `datetime` | — | `<Input type=\"datetime-local\">` |",
        ].join("\n"),
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof SchemaField>;

// ─── Helpers ─────────────────────────────────────────────────────────────────

/** Controlled wrapper — manages state so stories are interactive. */
function Controlled({
  metadata,
  field,
  initialValue,
  ...props
}: Omit<React.ComponentProps<typeof SchemaField>, "value" | "onChange"> & {
  initialValue?: unknown;
}) {
  const [value, setValue] = useState<unknown>(initialValue ?? "");
  return (
    <div className="w-80">
      <SchemaField
        metadata={metadata}
        field={field}
        value={value}
        onChange={(v) => setValue(v)}
        {...props}
      />
      <pre className="mt-3 text-xs text-muted-foreground bg-muted p-2 rounded overflow-auto">
        {JSON.stringify(value, null, 2)}
      </pre>
    </div>
  );
}

// =============================================================================
//  1. Basic Field Types
// =============================================================================

/** Standard string field with label, placeholder (auto-derived), and maxLength. */
export const StringField: Story = {
  name: "String",
  render: () => (
    <Controlled metadata={ZONE_METADATA} field="code" initialValue="" />
  ),
};

/** Multi-line text area for longer content. */
export const TextField: Story = {
  name: "Text (Textarea)",
  render: () => (
    <Controlled metadata={ZONE_METADATA} field="description" initialValue="" />
  ),
};

/** Number input with min/max from validation. */
export const NumberField: Story = {
  name: "Number",
  render: () => (
    <Controlled metadata={ZONE_METADATA} field="display_order" initialValue={0} />
  ),
};

/** Boolean toggle rendered as Switch with inline label. */
export const BooleanField: Story = {
  name: "Boolean (Switch)",
  render: () => (
    <Controlled metadata={ZONE_METADATA} field="is_active" initialValue={true} />
  ),
};

/** Enum dropdown with options and labels auto-derived from metadata. */
export const EnumField: Story = {
  name: "Enum (Select)",
  render: () => (
    <Controlled metadata={PRODUCT_METADATA} field="status" initialValue="draft" />
  ),
};

/** Relation (FK) field rendered as searchable Combobox. Requires `options` prop. */
export const RelationField: Story = {
  name: "Relation (Combobox)",
  render: () => (
    <Controlled
      metadata={ZONE_METADATA}
      field="branch_id"
      initialValue=""
      options={BRANCH_OPTIONS}
    />
  ),
};

/** Date field using native date input. */
export const DateField: Story = {
  name: "Date",
  render: () => {
    const dateMeta: SchemaMetadata = {
      modelName: "Event",
      fields: {
        event_date: {
          type: "date",
          required: true,
          nullable: false,
          translatable: false,
          validation: {},
          label: { ja: "開催日", en: "Event Date", vi: "Ngay su kien" },
        },
      },
    };
    return <Controlled metadata={dateMeta} field="event_date" initialValue="" />;
  },
};

/** Datetime field using native datetime-local input. */
export const DatetimeField: Story = {
  name: "Datetime",
  render: () => {
    const dtMeta: SchemaMetadata = {
      modelName: "Order",
      fields: {
        paid_at: {
          type: "datetime",
          required: false,
          nullable: true,
          translatable: false,
          validation: {},
          label: { ja: "会計日時", en: "Paid At", vi: "Thanh toan luc" },
        },
      },
    };
    return <Controlled metadata={dtMeta} field="paid_at" initialValue="" />;
  },
};

// =============================================================================
//  2. Translatable Fields
// =============================================================================

/** String field with `translatable: true` — renders locale tab switcher. */
export const TranslatableString: Story = {
  name: "Translatable String",
  render: () => (
    <Controlled
      metadata={PRODUCT_METADATA}
      field="name"
      initialValue={{ ja: "カレーライス", en: "", vi: "" }}
    />
  ),
};

/** Text field with `translatable: true` — renders locale tabs on Textarea. */
export const TranslatableText: Story = {
  name: "Translatable Text",
  render: () => (
    <Controlled
      metadata={PRODUCT_METADATA}
      field="description"
      initialValue={{ ja: "スパイシーなカレーライス", en: "", vi: "" }}
    />
  ),
};

// =============================================================================
//  3. Error States
// =============================================================================

/** Single error string — rendered below the field in red. */
export const ErrorString: Story = {
  name: "Error: String",
  render: () => {
    const [value, setValue] = useState<unknown>("");
    return (
      <div className="w-80">
        <SchemaField
          metadata={ZONE_METADATA}
          field="code"
          value={value}
          onChange={setValue}
          error="This code is already taken."
        />
      </div>
    );
  },
};

/** Error as `string[]` — first item is displayed (Laravel 422 format). */
export const ErrorArray: Story = {
  name: "Error: Array (Laravel 422)",
  render: () => {
    const [value, setValue] = useState<unknown>("");
    return (
      <div className="w-80">
        <SchemaField
          metadata={ZONE_METADATA}
          field="name"
          value={value}
          onChange={setValue}
          error={["The name field is required.", "Name must be at least 2 characters."]}
        />
      </div>
    );
  },
};

/** Per-locale errors on a translatable field — red dot on affected locale tabs. */
export const ErrorTranslatable: Story = {
  name: "Error: Per-Locale (Translatable)",
  render: () => {
    const [value, setValue] = useState<Record<string, string>>({ ja: "", en: "Curry", vi: "" });
    return (
      <div className="w-80">
        <SchemaField
          metadata={PRODUCT_METADATA}
          field="name"
          value={value}
          onChange={(v) => setValue(v as Record<string, string>)}
          error={{ ja: "必須です", en: "", vi: "Bat buoc" }}
        />
      </div>
    );
  },
};

// =============================================================================
//  4. Field States
// =============================================================================

/** Disabled field — input is greyed out and non-interactive. */
export const Disabled: Story = {
  name: "Disabled",
  render: () => (
    <div className="w-80">
      <SchemaField
        metadata={ZONE_METADATA}
        field="name"
        value="VIP Room"
        onChange={() => {}}
        disabled
      />
    </div>
  ),
};

/** Read-only field — visible but not editable. */
export const ReadOnly: Story = {
  name: "Read-Only",
  render: () => (
    <div className="w-80">
      <SchemaField
        metadata={ZONE_METADATA}
        field="code"
        value="INDOOR"
        onChange={() => {}}
        readOnly
      />
    </div>
  ),
};

/** Label hidden — useful inside table cells or custom layouts. */
export const HiddenLabel: Story = {
  name: "Hidden Label",
  render: () => (
    <Controlled metadata={ZONE_METADATA} field="name" initialValue="" hideLabel />
  ),
};

/** With description — shows help text from metadata below the input. */
export const WithDescription: Story = {
  name: "With Description",
  render: () => (
    <Controlled metadata={ZONE_METADATA} field="code" initialValue="" />
  ),
};

// =============================================================================
//  5. Overrides
// =============================================================================

/** Override label, placeholder, and description from the component props. */
export const PropOverrides: Story = {
  name: "Label/Placeholder Override",
  render: () => (
    <Controlled
      metadata={ZONE_METADATA}
      field="code"
      initialValue=""
      label="Custom Label"
      placeholder="Custom placeholder..."
      description="Custom description text."
    />
  ),
};

/** Custom enum options — override the auto-derived options from metadata. */
export const CustomEnumOptions: Story = {
  name: "Custom Enum Options",
  render: () => (
    <Controlled
      metadata={PRODUCT_METADATA}
      field="status"
      initialValue="active"
      options={[
        { value: "active", label: "Active (Sale)" },
        { value: "inactive", label: "Inactive (Hidden)" },
      ]}
    />
  ),
};

// =============================================================================
//  6. Edge Cases
// =============================================================================

/** Field key does not exist in metadata — renders nothing + dev warning in console. */
export const MissingField: Story = {
  name: "Edge: Missing Field",
  render: () => (
    <div className="w-80">
      <p className="text-xs text-muted-foreground mb-2">
        Check browser console for [SchemaField] warning.
      </p>
      <SchemaField
        metadata={ZONE_METADATA}
        field="nonexistent_field"
        value=""
        onChange={() => {}}
      />
      <p className="text-xs text-muted-foreground mt-2">Nothing renders above.</p>
    </div>
  ),
};

/** Relation field without `options` prop — renders disabled Combobox + dev warning. */
export const RelationNoOptions: Story = {
  name: "Edge: Relation Without Options",
  render: () => (
    <div className="w-80">
      <p className="text-xs text-muted-foreground mb-2">
        No options prop provided. Check console for warning.
      </p>
      <SchemaField
        metadata={ZONE_METADATA}
        field="branch_id"
        value=""
        onChange={() => {}}
      />
    </div>
  ),
};

/** Translatable field receives a plain string instead of locale map — auto-wraps + dev warning. */
export const TranslatableStringValue: Story = {
  name: "Edge: Translatable with String Value",
  render: () => {
    const [value, setValue] = useState<unknown>("Plain string value");
    return (
      <div className="w-80">
        <p className="text-xs text-muted-foreground mb-2">
          Value is a plain string, not {"Record<Locale, string>"}. Check console.
        </p>
        <SchemaField
          metadata={PRODUCT_METADATA}
          field="name"
          value={value}
          onChange={setValue}
        />
        <pre className="mt-3 text-xs text-muted-foreground bg-muted p-2 rounded overflow-auto">
          {JSON.stringify(value, null, 2)}
        </pre>
      </div>
    );
  },
};

/** Unknown field type — falls back to plain Input + dev warning. */
export const UnknownType: Story = {
  name: "Edge: Unknown Type",
  render: () => {
    const customMeta: SchemaMetadata = {
      modelName: "Custom",
      fields: {
        weird: {
          type: "custom_type_xyz",
          required: false,
          nullable: true,
          translatable: false,
          validation: {},
          label: { ja: "カスタム", en: "Custom Field", vi: "Truong custom" },
        },
      },
    };
    return (
      <div className="w-80">
        <p className="text-xs text-muted-foreground mb-2">
          Type &quot;custom_type_xyz&quot; is unknown. Falls back to Input.
        </p>
        <Controlled metadata={customMeta} field="weird" initialValue="" />
      </div>
    );
  },
};

// =============================================================================
//  7. Additional Field Types
// =============================================================================

// ── File Upload metadata fixture ──
const FILE_META: SchemaMetadata = {
  modelName: "Document",
  fields: {
    attachment: {
      type: "file",
      required: true,
      nullable: false,
      translatable: false,
      validation: { accept: "image/*,.pdf", maxSize: 5_000_000, maxFiles: 3 },
      label: { ja: "添付ファイル", en: "Attachment", vi: "Tep dinh kem" },
      description: { ja: "画像またはPDF、最大5MB", en: "Images or PDF, max 5MB", vi: "Hinh anh hoac PDF, toi da 5MB" },
    },
    avatar: {
      type: "file",
      required: false,
      nullable: true,
      translatable: false,
      validation: { accept: "image/*", maxSize: 2_000_000, maxFiles: 1 },
      label: { ja: "プロフィール画像", en: "Profile Picture", vi: "Anh dai dien" },
    },
    gallery: {
      type: "file",
      required: false,
      nullable: true,
      translatable: false,
      validation: { accept: "image/*", maxSize: 5_000_000, maxFiles: 6 },
      label: { ja: "商品画像", en: "Product Images", vi: "Anh san pham" },
    },
    resume: {
      type: "file",
      required: false,
      nullable: true,
      translatable: false,
      validation: { accept: ".pdf,.doc,.docx", maxSize: 10_000_000, maxFiles: 1 },
      label: { ja: "履歴書", en: "Resume", vi: "So yeu ly lich" },
    },
  },
};

/** File upload — default dropzone (large drop area with file list). */
export const FileDropzone: Story = {
  name: "File: Dropzone (default)",
  render: () => {
    const [value, setValue] = useState<unknown>([]);
    return (
      <div className="w-96">
        <SchemaField metadata={FILE_META} field="attachment" value={value} onChange={setValue} />
      </div>
    );
  },
};

/** File upload — compact button with filename. Good for tight form layouts. */
export const FileCompact: Story = {
  name: "File: Compact",
  render: () => {
    const [value, setValue] = useState<unknown>([]);
    return (
      <div className="w-96">
        <SchemaField metadata={FILE_META} field="attachment" value={value} onChange={setValue} fileVariant="compact" />
      </div>
    );
  },
};

/** File upload — avatar circle for profile pictures. */
export const FileAvatar: Story = {
  name: "File: Avatar",
  render: () => {
    const [value, setValue] = useState<unknown>([]);
    return (
      <div className="w-96">
        <SchemaField metadata={FILE_META} field="avatar" value={value} onChange={setValue} fileVariant="avatar" />
      </div>
    );
  },
};

/** File upload — gallery grid for multiple images. */
export const FileGallery: Story = {
  name: "File: Gallery",
  render: () => {
    const [value, setValue] = useState<unknown>([]);
    return (
      <div className="w-96">
        <SchemaField metadata={FILE_META} field="gallery" value={value} onChange={setValue} fileVariant="gallery" />
      </div>
    );
  },
};

/** File upload — inline minimal single-line. */
export const FileInline: Story = {
  name: "File: Inline",
  render: () => {
    const [value, setValue] = useState<unknown>([]);
    return (
      <div className="w-96">
        <SchemaField metadata={FILE_META} field="resume" value={value} onChange={setValue} fileVariant="inline" />
      </div>
    );
  },
};

/** Color picker field. */
export const ColorField: Story = {
  name: "Color Picker",
  render: () => {
    const colorMeta: SchemaMetadata = {
      modelName: "Theme",
      fields: {
        primary_color: {
          type: "color",
          required: true,
          nullable: false,
          translatable: false,
          validation: {},
          label: { ja: "メインカラー", en: "Primary Color", vi: "Mau chinh" },
        },
      },
    };
    return <Controlled metadata={colorMeta} field="primary_color" initialValue="#3B82F6" />;
  },
};

/** Password field with hidden text and toggle visibility. */
export const PasswordField: Story = {
  name: "Password",
  render: () => {
    const passMeta: SchemaMetadata = {
      modelName: "User",
      fields: {
        password: {
          type: "password",
          required: true,
          nullable: false,
          translatable: false,
          validation: { minLength: 8, maxLength: 128 },
          label: { ja: "パスワード", en: "Password", vi: "Mat khau" },
        },
      },
    };
    return <Controlled metadata={passMeta} field="password" initialValue="" />;
  },
};

/** Star rating field. */
export const RatingField: Story = {
  name: "Rating",
  render: () => {
    const ratingMeta: SchemaMetadata = {
      modelName: "Review",
      fields: {
        score: {
          type: "rating",
          required: true,
          nullable: false,
          translatable: false,
          validation: { max: 5 },
          label: { ja: "評価", en: "Rating", vi: "Danh gia" },
        },
      },
    };
    return <Controlled metadata={ratingMeta} field="score" initialValue={3} />;
  },
};

/** Slider field with min/max range. */
export const SliderField: Story = {
  name: "Slider",
  render: () => {
    const sliderMeta: SchemaMetadata = {
      modelName: "Settings",
      fields: {
        volume: {
          type: "slider",
          required: false,
          nullable: false,
          translatable: false,
          validation: { min: 0, max: 100 },
          label: { ja: "音量", en: "Volume", vi: "Am luong" },
          description: { ja: "0〜100の範囲", en: "Range 0-100", vi: "Tu 0 den 100" },
        },
      },
    };
    return <Controlled metadata={sliderMeta} field="volume" initialValue={50} />;
  },
};

/** Checkbox field (explicit type, different from boolean/switch). */
export const CheckboxField: Story = {
  name: "Checkbox",
  render: () => {
    const cbMeta: SchemaMetadata = {
      modelName: "Consent",
      fields: {
        agree_terms: {
          type: "checkbox",
          required: true,
          nullable: false,
          translatable: false,
          validation: {},
          label: { ja: "利用規約に同意", en: "Agree to Terms", vi: "Dong y dieu khoan" },
        },
      },
    };
    return <Controlled metadata={cbMeta} field="agree_terms" initialValue={false} />;
  },
};

/** Boolean rendered as checkbox via `asCheckbox` prop instead of default Switch. */
export const BooleanAsCheckbox: Story = {
  name: "Boolean as Checkbox",
  render: () => (
    <Controlled metadata={ZONE_METADATA} field="is_active" initialValue={true} asCheckbox />
  ),
};

// =============================================================================
//  8. Translatable Placeholder (per-locale)
// =============================================================================

/** Translatable field with explicit per-locale placeholders in metadata. */
export const TranslatablePlaceholder: Story = {
  name: "Translatable: Per-Locale Placeholder",
  render: () => {
    const meta: SchemaMetadata = {
      modelName: "Article",
      fields: {
        title: {
          type: "string",
          required: true,
          nullable: false,
          translatable: true,
          validation: { maxLength: 200 },
          label: { ja: "タイトル", en: "Title", vi: "Tieu de" },
          placeholder: {
            ja: "記事のタイトルを入力してください",
            en: "Enter the article title",
            vi: "Nhap tieu de bai viet",
          },
        },
      },
    };
    return (
      <Controlled
        metadata={meta}
        field="title"
        initialValue={{ ja: "", en: "", vi: "" }}
      />
    );
  },
};

/** Translatable field without explicit placeholder — auto-derives from label per locale. */
export const TranslatableAutoPlaceholder: Story = {
  name: "Translatable: Auto Placeholder",
  render: () => (
    <Controlled
      metadata={PRODUCT_METADATA}
      field="name"
      initialValue={{ ja: "", en: "", vi: "" }}
    />
  ),
};

// =============================================================================
//  9. Full Form Examples
// =============================================================================

/** Complete Zone form built entirely with SchemaField — no manual Input/Select wiring. */
export const FullZoneForm: Story = {
  name: "Full Form: Zone",
  render: () => {
    const [form, setForm] = useState({
      code: "",
      name: "",
      description: "",
      display_order: 0,
      is_active: true,
      branch_id: "",
    });
    const [errors, setErrors] = useState<Record<string, string>>({});

    function update(field: string, value: unknown) {
      setForm((prev) => ({ ...prev, [field]: value }));
      setErrors((prev) => { const next = { ...prev }; delete next[field]; return next; });
    }

    function handleSubmit() {
      const newErrors: Record<string, string> = {};
      if (!form.code) newErrors.code = "Code is required.";
      if (!form.name) newErrors.name = "Name is required.";
      if (!form.branch_id) newErrors.branch_id = "Branch is required.";
      setErrors(newErrors);
      if (Object.keys(newErrors).length === 0) {
        alert("Submit: " + JSON.stringify(form, null, 2));
      }
    }

    return (
      <div className="w-96 space-y-4">
        <h3 className="text-lg font-semibold">Create Zone</h3>
        <SchemaField metadata={ZONE_METADATA} field="branch_id" value={form.branch_id} onChange={(v) => update("branch_id", v)} options={BRANCH_OPTIONS} error={errors.branch_id} />
        <SchemaField metadata={ZONE_METADATA} field="code" value={form.code} onChange={(v) => update("code", v)} error={errors.code} />
        <SchemaField metadata={ZONE_METADATA} field="name" value={form.name} onChange={(v) => update("name", v)} error={errors.name} />
        <SchemaField metadata={ZONE_METADATA} field="description" value={form.description} onChange={(v) => update("description", v)} />
        <SchemaField metadata={ZONE_METADATA} field="display_order" value={form.display_order} onChange={(v) => update("display_order", v)} />
        <SchemaField metadata={ZONE_METADATA} field="is_active" value={form.is_active} onChange={(v) => update("is_active", v)} />
        <button type="button" onClick={handleSubmit} className="w-full h-9 bg-primary text-primary-foreground rounded-md text-sm font-medium">
          Create Zone
        </button>
        <pre className="text-xs text-muted-foreground bg-muted p-3 rounded overflow-auto">
          {JSON.stringify(form, null, 2)}
        </pre>
      </div>
    );
  },
};

/** Complete Product form with translatable fields, enum, and relation. */
export const FullProductForm: Story = {
  name: "Full Form: Product (Translatable)",
  render: () => {
    const [form, setForm] = useState<Record<string, unknown>>({
      name: { ja: "", en: "", vi: "" },
      description: { ja: "", en: "", vi: "" },
      slug: "",
      status: "draft",
      is_hidden: false,
      product_type_id: "",
    });

    function update(field: string, value: unknown) {
      setForm((prev) => ({ ...prev, [field]: value }));
    }

    return (
      <div className="w-96 space-y-4">
        <h3 className="text-lg font-semibold">Create Product</h3>
        <SchemaField metadata={PRODUCT_METADATA} field="name" value={form.name} onChange={(v) => update("name", v)} />
        <SchemaField metadata={PRODUCT_METADATA} field="slug" value={form.slug} onChange={(v) => update("slug", v)} />
        <SchemaField metadata={PRODUCT_METADATA} field="description" value={form.description} onChange={(v) => update("description", v)} />
        <SchemaField metadata={PRODUCT_METADATA} field="status" value={form.status} onChange={(v) => update("status", v)} />
        <SchemaField metadata={PRODUCT_METADATA} field="product_type_id" value={form.product_type_id} onChange={(v) => update("product_type_id", v)} options={PRODUCT_TYPE_OPTIONS} />
        <SchemaField metadata={PRODUCT_METADATA} field="is_hidden" value={form.is_hidden} onChange={(v) => update("is_hidden", v)} />
        <pre className="text-xs text-muted-foreground bg-muted p-3 rounded overflow-auto max-h-60">
          {JSON.stringify(form, null, 2)}
        </pre>
      </div>
    );
  },
};
