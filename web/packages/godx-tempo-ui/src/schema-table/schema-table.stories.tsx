import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useState } from "react";
import { Pencil, Trash2, RotateCcw, Eye } from "lucide-react";
import { SchemaTable, type SchemaTableFilters, type SchemaTableProps } from "./schema-table";
import type { SchemaMetadata } from "../schema-field/schema-field";
import { Badge } from "../badge";
import { Button } from "../button";

// ─── Mock Metadata ───────────────────────────────────────────────────────────

const ZONE_META: SchemaMetadata = {
  modelName: "Zone",
  fields: {
    code: { type: "string", required: true, nullable: false, translatable: false, validation: { maxLength: 50 }, label: { ja: "エリアコード", en: "Zone Code", vi: "Ma khu vuc" } },
    name: { type: "string", required: true, nullable: false, translatable: false, validation: {}, label: { ja: "エリア名", en: "Zone Name", vi: "Ten khu vuc" } },
    display_order: { type: "number", required: true, nullable: false, translatable: false, validation: {}, label: { ja: "表示順", en: "Order", vi: "Thu tu" } },
    is_active: { type: "boolean", required: true, nullable: false, translatable: false, validation: {}, label: { ja: "有効", en: "Active", vi: "Hoat dong" } },
  },
};

const PRODUCT_META: SchemaMetadata = {
  modelName: "Product",
  fields: {
    name: { type: "string", required: true, nullable: false, translatable: true, validation: {}, label: { ja: "商品名", en: "Product Name", vi: "Ten SP" } },
    status: {
      type: "enum", required: true, nullable: false, translatable: false, validation: {},
      label: { ja: "ステータス", en: "Status", vi: "Trang thai" },
      enumValues: ["draft", "active", "inactive"],
      enumLabels: {
        draft: { ja: "下書き", en: "Draft", vi: "Nhap" },
        active: { ja: "販売中", en: "Active", vi: "Dang ban" },
        inactive: { ja: "停止", en: "Inactive", vi: "Ngung ban" },
      },
    },
    is_hidden: { type: "boolean", required: true, nullable: false, translatable: false, validation: {}, label: { ja: "非公開", en: "Hidden", vi: "An" } },
    created_at: { type: "datetime", required: false, nullable: true, translatable: false, validation: {}, label: { ja: "作成日", en: "Created", vi: "Tao luc" } },
  },
};

// ─── Mock Data ───────────────────────────────────────────────────────────────

const ZONES = [
  { id: "z1", code: "INDOOR", name: "店内", display_order: 1, is_active: true },
  { id: "z2", code: "TERRACE", name: "テラス", display_order: 2, is_active: true },
  { id: "z3", code: "VIP", name: "VIPルーム", display_order: 3, is_active: false },
  { id: "z4", code: "COUNTER", name: "カウンター", display_order: 4, is_active: true },
  { id: "z5", code: "PRIVATE", name: "個室", display_order: 5, is_active: true },
];

const PRODUCTS = Array.from({ length: 12 }, (_, i) => ({
  id: `p${i + 1}`,
  name: { ja: `商品${i + 1}`, en: `Product ${i + 1}`, vi: `San pham ${i + 1}` },
  status: ["draft", "active", "active", "inactive"][i % 4],
  is_hidden: i % 5 === 0,
  created_at: new Date(2026, 3, 10 - i).toISOString(),
}));

// ─── Storybook Meta ──────────────────────────────────────────────────────────

const meta: Meta<typeof SchemaTable> = {
  title: "UI/SchemaTable",
  component: SchemaTable,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component: [
          "Metadata-driven data table. Reads `{entity}Metadata` and auto-builds columns",
          "with labels, sorting, type-aware cells, pagination, search, and row actions.",
          "Built on @tanstack/react-table.",
          "",
          "**Column shorthand:** `columns={[\"code\",\"name\",\"is_active\"]}`",
          "**Custom render:** `{ field: \"status\", render: (v) => <Badge>{v}</Badge> }`",
          "**Server-side:** Sort/search/paginate emit `onFiltersChange` — caller re-fetches.",
        ].join("\n"),
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof SchemaTable>;

// =============================================================================
//  1. Basic
// =============================================================================

/** Simplest usage — just metadata + data + columns. No sorting/pagination. */
export const Basic: Story = {
  name: "Basic",
  render: () => (
    <SchemaTable
      metadata={ZONE_META}
      data={ZONES}
      columns={["code", "name", "is_active"]}
    />
  ),
};

/** Auto-columns — omit `columns` prop to render all fields from metadata. */
export const AutoColumns: Story = {
  name: "Auto Columns (all fields)",
  render: () => (
    <SchemaTable metadata={ZONE_META} data={ZONES} />
  ),
};

/** Empty state — no data. */
export const Empty: Story = {
  name: "Empty State",
  render: () => (
    <SchemaTable metadata={ZONE_META} data={[]} columns={["code", "name"]} />
  ),
};

/** Custom empty text. */
export const CustomEmptyText: Story = {
  name: "Custom Empty Text",
  render: () => (
    <SchemaTable metadata={ZONE_META} data={[]} columns={["code"]} emptyText="No zones found. Create one!" />
  ),
};

/** Loading skeleton. */
export const Loading: Story = {
  name: "Loading",
  render: () => (
    <SchemaTable metadata={ZONE_META} data={[]} columns={["code", "name", "is_active"]} loading loadingRows={4} />
  ),
};

// =============================================================================
//  2. Type-aware cell rendering
// =============================================================================

/** Boolean, enum, translatable, datetime — all rendered correctly per type. */
export const CellTypes: Story = {
  name: "Cell Types (bool, enum, translatable, datetime)",
  render: () => (
    <SchemaTable
      metadata={PRODUCT_META}
      data={PRODUCTS.slice(0, 5)}
      columns={["name", "status", "is_hidden", "created_at"]}
    />
  ),
};

// =============================================================================
//  3. Custom cell render
// =============================================================================

/** Override render for specific columns — like Ant Design's `render` in column def. */
export const CustomRender: Story = {
  name: "Custom Cell Render",
  render: () => (
    <SchemaTable
      metadata={PRODUCT_META}
      data={PRODUCTS.slice(0, 5)}
      columns={[
        "name",
        {
          field: "status",
          render: (val) => {
            const colors: Record<string, string> = { draft: "secondary", active: "default", inactive: "destructive" };
            return <Badge variant={(colors[val as string] ?? "secondary") as "default"}>{String(val)}</Badge>;
          },
        },
        { field: "is_hidden", label: "Visibility", render: (val) => val ? "Hidden" : "Visible" },
        "created_at",
      ]}
    />
  ),
};

// =============================================================================
//  4. Sorting
// =============================================================================

/** Server-side sorting — click column headers. Tri-state: asc → desc → none. */
export const Sorting: Story = {
  name: "Sorting",
  render: () => {
    const [filters, setFilters] = useState<SchemaTableFilters>({});
    return (
      <div>
        <SchemaTable
          metadata={ZONE_META}
          data={ZONES}
          columns={["code", "name", "display_order", "is_active"]}
          filters={filters}
          onFiltersChange={setFilters}
        />
        <pre className="mt-4 text-xs text-muted-foreground bg-muted p-3 rounded">
          filters: {JSON.stringify(filters)}
        </pre>
      </div>
    );
  },
};

/** Non-sortable column — disable sorting on specific columns. */
export const NonSortable: Story = {
  name: "Non-Sortable Column",
  render: () => {
    const [filters, setFilters] = useState<SchemaTableFilters>({});
    return (
      <SchemaTable
        metadata={ZONE_META}
        data={ZONES}
        columns={["code", { field: "name", sortable: false }, "is_active"]}
        filters={filters}
        onFiltersChange={setFilters}
      />
    );
  },
};

// =============================================================================
//  5. Search
// =============================================================================

/** Server-side search with debounced input. */
export const WithSearch: Story = {
  name: "Search",
  render: () => {
    const [filters, setFilters] = useState<SchemaTableFilters>({});
    return (
      <div>
        <SchemaTable
          metadata={ZONE_META}
          data={ZONES}
          columns={["code", "name", "is_active"]}
          filters={filters}
          onFiltersChange={setFilters}
        />
        <pre className="mt-4 text-xs text-muted-foreground bg-muted p-3 rounded">
          filters: {JSON.stringify(filters)}
        </pre>
      </div>
    );
  },
};

/** Custom search placeholder. */
export const CustomSearchPlaceholder: Story = {
  name: "Custom Search Placeholder",
  render: () => (
    <SchemaTable
      metadata={ZONE_META}
      data={ZONES}
      columns={["code", "name"]}
      searchPlaceholder="Find a zone..."
      filters={{}}
      onFiltersChange={() => {}}
    />
  ),
};

/** Search hidden. */
export const NoSearch: Story = {
  name: "No Search",
  render: () => (
    <SchemaTable metadata={ZONE_META} data={ZONES} columns={["code", "name"]} showSearch={false} />
  ),
};

// =============================================================================
//  6. Pagination
// =============================================================================

/** Server-side pagination with page navigation and per-page selector. */
export const Pagination: Story = {
  name: "Pagination",
  render: () => {
    const [filters, setFilters] = useState<SchemaTableFilters>({ page: 1, per_page: 5 });
    const page = filters.page ?? 1;
    const perPage = filters.per_page ?? 5;
    const paginatedData = PRODUCTS.slice((page - 1) * perPage, page * perPage);

    return (
      <div>
        <SchemaTable
          metadata={PRODUCT_META}
          data={paginatedData}
          columns={["name", "status", "created_at"]}
          pagination={{ page, perPage, total: PRODUCTS.length }}
          filters={filters}
          onFiltersChange={setFilters}
        />
        <pre className="mt-4 text-xs text-muted-foreground bg-muted p-3 rounded">
          filters: {JSON.stringify(filters)}
        </pre>
      </div>
    );
  },
};

// =============================================================================
//  7. Row Actions
// =============================================================================

/** Actions dropdown on each row — edit, view, delete. */
export const RowActions: Story = {
  name: "Row Actions",
  render: () => (
    <SchemaTable
      metadata={ZONE_META}
      data={ZONES}
      columns={["code", "name", "is_active"]}
      actions={[
        { label: "View", onClick: (row) => alert(`View: ${row.name}`), icon: Eye },
        { label: "Edit", onClick: (row) => alert(`Edit: ${row.code}`), icon: Pencil },
        { label: "Delete", onClick: (row) => alert(`Delete: ${row.id}`), icon: Trash2, variant: "destructive", separator: true },
      ]}
    />
  ),
};

/** Conditional actions — Restore only for inactive zones. */
export const ConditionalActions: Story = {
  name: "Conditional Actions",
  render: () => (
    <SchemaTable
      metadata={ZONE_META}
      data={ZONES}
      columns={["code", "name", "is_active"]}
      actions={[
        { label: "Edit", onClick: (row) => alert(`Edit: ${row.code}`), icon: Pencil },
        { label: "Deactivate", onClick: (row) => alert(`Deactivate: ${row.code}`), hidden: (row) => !row.is_active },
        { label: "Restore", onClick: (row) => alert(`Restore: ${row.code}`), icon: RotateCcw, hidden: (row) => !!row.is_active },
        { label: "Delete", onClick: (row) => alert(`Delete: ${row.code}`), icon: Trash2, variant: "destructive", separator: true },
      ]}
    />
  ),
};

// =============================================================================
//  8. Row Click
// =============================================================================

/** Clickable rows — navigate on click. */
export const RowClick: Story = {
  name: "Row Click",
  render: () => (
    <SchemaTable
      metadata={ZONE_META}
      data={ZONES}
      columns={["code", "name", "display_order"]}
      onRowClick={(row) => alert(`Clicked: ${row.name} (${row.code})`)}
    />
  ),
};

// =============================================================================
//  9. Full Example — all features combined
// =============================================================================

// =============================================================================
// 10. Advanced Filters
// =============================================================================

/** Filterable fields — enum dropdown + boolean toggle in a popover. */
export const AdvancedFilters: Story = {
  name: "Advanced Filters",
  render: () => {
    const [filters, setFilters] = useState<SchemaTableFilters>({});
    return (
      <div className="max-w-3xl">
        <SchemaTable
          metadata={PRODUCT_META}
          data={PRODUCTS.slice(0, 8)}
          columns={["name", "status", "is_hidden", "created_at"]}
          filterableFields={["status", "is_hidden"]}
          filters={filters}
          onFiltersChange={setFilters}
        />
        <pre className="mt-4 text-xs text-muted-foreground bg-muted p-3 rounded">
          {JSON.stringify(filters, null, 2)}
        </pre>
      </div>
    );
  },
};

/** Filters with custom options (e.g. relation lookups). */
export const FilterWithCustomOptions: Story = {
  name: "Filter: Custom Options",
  render: () => {
    const [filters, setFilters] = useState<SchemaTableFilters>({});
    const ZONE_FILTER_META: SchemaMetadata = {
      ...ZONE_META,
      fields: {
        ...ZONE_META.fields,
        zone_id: {
          type: "uuid", required: false, nullable: false, translatable: false, validation: {},
          label: { ja: "エリア", en: "Zone", vi: "Khu vuc" },
          relation: { model: "Zone" },
        },
      },
    };
    return (
      <SchemaTable
        metadata={ZONE_FILTER_META}
        data={ZONES}
        columns={["code", "name", "is_active"]}
        filterableFields={[
          "is_active",
          { field: "zone_id", type: "select", label: "Zone", options: [
            { value: "z1", label: "店内" }, { value: "z2", label: "テラス" }, { value: "z3", label: "VIP" },
          ]},
        ]}
        filters={filters}
        onFiltersChange={setFilters}
      />
    );
  },
};

// =============================================================================
// 11. Filter Tags
// =============================================================================

/** Active filters shown as removable tags. */
export const FilterTags: Story = {
  name: "Filter Tags",
  render: () => {
    const [filters, setFilters] = useState<SchemaTableFilters>({ status: "active", is_hidden: true });
    return (
      <div className="max-w-3xl">
        <SchemaTable
          metadata={PRODUCT_META}
          data={PRODUCTS.slice(0, 5)}
          columns={["name", "status", "is_hidden"]}
          filterableFields={["status", "is_hidden"]}
          filters={filters}
          onFiltersChange={setFilters}
        />
        <pre className="mt-4 text-xs text-muted-foreground bg-muted p-3 rounded">
          {JSON.stringify(filters, null, 2)}
        </pre>
      </div>
    );
  },
};

// =============================================================================
// 12. Column Visibility Toggle
// =============================================================================

/** Show/hide columns via dropdown toggle. */
export const ColumnToggle: Story = {
  name: "Column Visibility Toggle",
  render: () => (
    <SchemaTable
      metadata={PRODUCT_META}
      data={PRODUCTS.slice(0, 5)}
      columns={["name", "status", "is_hidden", "created_at"]}
      showColumnToggle
    />
  ),
};

// =============================================================================
// 13. Toolbar Right Slot
// =============================================================================

/** Custom buttons in the toolbar (e.g. Create, Export). */
export const ToolbarSlot: Story = {
  name: "Toolbar Right Slot",
  render: () => (
    <SchemaTable
      metadata={ZONE_META}
      data={ZONES}
      columns={["code", "name", "is_active"]}
      filterableFields={["is_active"]}
      showColumnToggle
      filters={{}}
      onFiltersChange={() => {}}
      toolbarRight={
        <div className="flex gap-2 ml-auto">
          <Button variant="outline" size="sm">Export CSV</Button>
          <Button size="sm">+ Create Zone</Button>
        </div>
      }
    />
  ),
};

// =============================================================================
// 14. Full Example (all features)
// =============================================================================

/** Complete example with search, sort, pagination, custom render, and actions. */
export const FullExample: Story = {
  name: "Full Example",
  render: () => {
    const [filters, setFilters] = useState<SchemaTableFilters>({ page: 1, per_page: 5 });
    const page = filters.page ?? 1;
    const perPage = filters.per_page ?? 5;

    // Simulate server-side filtering
    let filtered = [...PRODUCTS];
    if (filters.search) {
      const q = filters.search.toLowerCase();
      filtered = filtered.filter((p) =>
        (p.name as Record<string, string>).ja?.toLowerCase().includes(q) ||
        (p.name as Record<string, string>).en?.toLowerCase().includes(q)
      );
    }
    if (filters.sort) {
      const desc = filters.sort.startsWith("-");
      const field = desc ? filters.sort.slice(1) : filters.sort;
      filtered.sort((a, b) => {
        const av = String((a as Record<string, unknown>)[field] ?? "");
        const bv = String((b as Record<string, unknown>)[field] ?? "");
        return desc ? bv.localeCompare(av) : av.localeCompare(bv);
      });
    }
    const total = filtered.length;
    const paginated = filtered.slice((page - 1) * perPage, page * perPage);

    return (
      <div className="max-w-3xl">
        <SchemaTable
          metadata={PRODUCT_META}
          data={paginated}
          columns={[
            "name",
            {
              field: "status",
              render: (val) => {
                const v = val as string;
                const variant = v === "active" ? "default" : v === "draft" ? "secondary" : "destructive";
                return <Badge variant={variant as "default"}>{v}</Badge>;
              },
            },
            "is_hidden",
            "created_at",
          ]}
          filterableFields={["status", "is_hidden"]}
          showColumnToggle
          actions={[
            { label: "Edit", onClick: (row) => alert(`Edit: ${row.id}`), icon: Pencil },
            { label: "Delete", onClick: (row) => alert(`Delete: ${row.id}`), icon: Trash2, variant: "destructive", separator: true },
          ]}
          pagination={{ page, perPage, total }}
          filters={filters}
          onFiltersChange={setFilters}
          onRowClick={(row) => alert(`Navigate to: ${row.id}`)}
          toolbarRight={<Button size="sm">+ New Product</Button>}
        />
        <pre className="mt-4 text-xs text-muted-foreground bg-muted p-3 rounded">
          {JSON.stringify(filters, null, 2)}
        </pre>
      </div>
    );
  },
};
