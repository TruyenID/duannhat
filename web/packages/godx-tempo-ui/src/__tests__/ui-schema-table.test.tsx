import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import { SchemaTable, type SchemaTableProps } from '@/schema-table';
import { UIProvider as AppProvider } from '@/internal/ui-provider';
import type { SchemaMetadata } from '@/schema-field';

// ─── Helpers ─────────────────────────────────────────────────────────────────


function WithProvider({ children }: { children: React.ReactNode }) {
  return (
    <AppProvider locales={{ ja: "日本語", en: "English", vi: "Tiếng Việt" }} defaultLocale="ja" fallbackLocale="en">
      {children}
    </AppProvider>
  );
}

function renderTable<T extends Record<string, unknown>>(props: SchemaTableProps<T>) {
  return render(
    <WithProvider>
      <SchemaTable {...props} />
    </WithProvider>,
  );
}

// ─── Fixtures ────────────────────────────────────────────────────────────────

const ZONE_META: SchemaMetadata = {
  modelName: 'Zone',
  fields: {
    code: {
      type: 'string', required: true, nullable: false, translatable: false,
      validation: { maxLength: 50 },
      label: { ja: 'エリアコード', en: 'Zone Code', vi: 'Mã khu vực' },
    },
    name: {
      type: 'string', required: true, nullable: false, translatable: false,
      validation: { maxLength: 255 },
      label: { ja: 'エリア名', en: 'Zone Name', vi: 'Tên khu vực' },
    },
    display_order: {
      type: 'number', required: true, nullable: false, translatable: false,
      validation: {},
      label: { ja: '表示順', en: 'Display Order', vi: 'Thứ tự' },
    },
    is_active: {
      type: 'boolean', required: true, nullable: false, translatable: false,
      validation: {},
      label: { ja: '有効', en: 'Active', vi: 'Hoạt động' },
    },
    branch_id: {
      type: 'uuid', required: true, nullable: false, translatable: false,
      validation: {},
      label: { ja: '店舗', en: 'Branch', vi: 'Chi nhánh' },
      relation: { model: 'Branch' },
    },
  },
};

const PRODUCT_META: SchemaMetadata = {
  modelName: 'Product',
  fields: {
    name: {
      type: 'string', required: true, nullable: false, translatable: true,
      validation: { maxLength: 255 },
      label: { ja: '商品名', en: 'Product Name', vi: 'Tên sản phẩm' },
    },
    status: {
      type: 'enum', required: true, nullable: false, translatable: false,
      validation: {},
      label: { ja: 'ステータス', en: 'Status', vi: 'Trạng thái' },
      enum: 'ProductStatus',
      enumValues: ['draft', 'active', 'inactive'],
      enumLabels: {
        draft: { ja: '下書き', en: 'Draft', vi: 'Nháp' },
        active: { ja: '販売中', en: 'Active', vi: 'Đang bán' },
        inactive: { ja: '停止', en: 'Inactive', vi: 'Ngừng bán' },
      },
    },
    is_hidden: {
      type: 'boolean', required: true, nullable: false, translatable: false,
      validation: {},
      label: { ja: '非公開', en: 'Hidden', vi: 'Ẩn' },
    },
  },
};

const ZONES = [
  { id: 'z1', code: 'INDOOR', name: '店内', display_order: 1, is_active: true, branch_id: 'b1' },
  { id: 'z2', code: 'TERRACE', name: 'テラス', display_order: 2, is_active: true, branch_id: 'b1' },
  { id: 'z3', code: 'VIP', name: 'VIP', display_order: 3, is_active: false, branch_id: 'b1' },
];

const PRODUCTS = [
  { id: 'p1', name: { ja: 'カレー', en: 'Curry', vi: 'Ca ri' }, status: 'active', is_hidden: false },
  { id: 'p2', name: { ja: 'ラーメン', en: 'Ramen', vi: 'Mi Nhat' }, status: 'draft', is_hidden: true },
];

// =============================================================================
//  1. Basic rendering
// =============================================================================

describe('SchemaTable — basic rendering', () => {
  it('renders table with column headers from metadata', () => {
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code', 'name'] });
    expect(screen.getByText('エリアコード')).toBeInTheDocument();
    expect(screen.getByText('エリア名')).toBeInTheDocument();
  });

  it('renders data rows', () => {
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'] });
    expect(screen.getByText('INDOOR')).toBeInTheDocument();
    expect(screen.getByText('TERRACE')).toBeInTheDocument();
    // VIP zone code
    const cells = screen.getAllByRole('cell');
    expect(cells.some((c) => c.textContent === 'VIP')).toBe(true);
  });

  it('renders all non-_id fields when columns is omitted', () => {
    renderTable({ metadata: ZONE_META, data: ZONES });
    expect(screen.getByText('エリアコード')).toBeInTheDocument();
    expect(screen.getByText('エリア名')).toBeInTheDocument();
    expect(screen.getByText('表示順')).toBeInTheDocument();
    expect(screen.getByText('有効')).toBeInTheDocument();
  });

  it('renders empty state when data is empty', () => {
    renderTable({ metadata: ZONE_META, data: [], columns: ['code'] });
    expect(screen.getByText('データがありません')).toBeInTheDocument();
  });

  it('renders custom empty text', () => {
    renderTable({ metadata: ZONE_META, data: [], columns: ['code'], emptyText: 'Nothing here' });
    expect(screen.getByText('Nothing here')).toBeInTheDocument();
  });

  it('renders loading skeleton rows', () => {
    const { container } = renderTable({ metadata: ZONE_META, data: [], columns: ['code', 'name'], loading: true, loadingRows: 3 });
    const skeletons = container.querySelectorAll('.animate-pulse');
    expect(skeletons.length).toBe(6); // 3 rows × 2 columns
  });

  it('sets data-slot on wrapper', () => {
    const { container } = renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'] });
    expect(container.querySelector('[data-slot="schema-table"]')).toBeInTheDocument();
  });
});

// =============================================================================
//  2. Type-aware cell rendering
// =============================================================================

describe('SchemaTable — cell rendering by type', () => {
  it('renders boolean as dot + Yes/No', () => {
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['is_active'] });
    const yesItems = screen.getAllByText('Yes');
    const noItems = screen.getAllByText('No');
    expect(yesItems.length).toBe(2); // INDOOR + TERRACE
    expect(noItems.length).toBe(1); // VIP
  });

  it('renders enum with locale label', () => {
    renderTable({ metadata: PRODUCT_META, data: PRODUCTS, columns: ['status'] });
    expect(screen.getByText('販売中')).toBeInTheDocument(); // active → ja
    expect(screen.getByText('下書き')).toBeInTheDocument(); // draft → ja
  });

  it('renders translatable field in current locale', () => {
    renderTable({ metadata: PRODUCT_META, data: PRODUCTS, columns: ['name'] });
    expect(screen.getByText('カレー')).toBeInTheDocument(); // ja locale
    expect(screen.getByText('ラーメン')).toBeInTheDocument();
  });

  it('renders null as em dash', () => {
    const data = [{ id: '1', code: null, name: 'Test' }];
    renderTable({ metadata: ZONE_META, data: data as unknown as Record<string, unknown>[], columns: ['code'] });
    expect(screen.getByText('—')).toBeInTheDocument();
  });
});

// =============================================================================
//  3. Custom column render
// =============================================================================

describe('SchemaTable — custom render', () => {
  it('uses custom render function for a column', () => {
    renderTable({
      metadata: ZONE_META,
      data: ZONES,
      columns: [
        { field: 'is_active', render: (val) => <span data-testid="custom">{val ? 'ON' : 'OFF'}</span> },
      ],
    });
    const customs = screen.getAllByTestId('custom');
    expect(customs[0]).toHaveTextContent('ON');
    expect(customs[2]).toHaveTextContent('OFF');
  });

  it('passes row data to custom render', () => {
    renderTable({
      metadata: ZONE_META,
      data: ZONES,
      columns: [
        { field: 'code', render: (_val, row) => <span>{(row as typeof ZONES[0]).code}-{(row as typeof ZONES[0]).display_order}</span> },
      ],
    });
    expect(screen.getByText('INDOOR-1')).toBeInTheDocument();
  });

  it('respects column label override', () => {
    renderTable({
      metadata: ZONE_META,
      data: ZONES,
      columns: [{ field: 'code', label: 'Custom Header' }],
    });
    expect(screen.getByText('Custom Header')).toBeInTheDocument();
    expect(screen.queryByText('エリアコード')).not.toBeInTheDocument();
  });

  it('hides column when hidden: true', () => {
    renderTable({
      metadata: ZONE_META,
      data: ZONES,
      columns: ['code', { field: 'name', hidden: true }],
    });
    expect(screen.getByText('エリアコード')).toBeInTheDocument();
    expect(screen.queryByText('エリア名')).not.toBeInTheDocument();
  });
});

// =============================================================================
//  4. Search
// =============================================================================

describe('SchemaTable — search', () => {
  it('renders search input by default', () => {
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'] });
    expect(screen.getByPlaceholderText('検索...')).toBeInTheDocument();
  });

  it('hides search when showSearch is false', () => {
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'], showSearch: false });
    expect(screen.queryByPlaceholderText('検索...')).not.toBeInTheDocument();
  });

  it('uses custom search placeholder', () => {
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'], searchPlaceholder: 'Find zone...' });
    expect(screen.getByPlaceholderText('Find zone...')).toBeInTheDocument();
  });

  it('calls onFiltersChange with search value after debounce', async () => {
    vi.useFakeTimers();
    const onFiltersChange = vi.fn();
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'], filters: {}, onFiltersChange });

    fireEvent.change(screen.getByPlaceholderText('検索...'), { target: { value: 'VIP' } });

    expect(onFiltersChange).not.toHaveBeenCalled(); // debounced

    vi.advanceTimersByTime(300);

    expect(onFiltersChange).toHaveBeenCalledWith({ search: 'VIP', page: 1 });
    vi.useRealTimers();
  });

  it('resets page to 1 when searching', async () => {
    vi.useFakeTimers();
    const onFiltersChange = vi.fn();
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'], filters: { page: 3 }, onFiltersChange });

    fireEvent.change(screen.getByPlaceholderText('検索...'), { target: { value: 'test' } });
    vi.advanceTimersByTime(300);

    expect(onFiltersChange).toHaveBeenCalledWith(expect.objectContaining({ page: 1 }));
    vi.useRealTimers();
  });
});

// =============================================================================
//  5. Sorting
// =============================================================================

describe('SchemaTable — sorting', () => {
  it('renders sort icon only on columns listed in sortableFields', () => {
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code', 'name'], sortableFields: ['code'], filters: {}, onFiltersChange: vi.fn() });
    // code should be a button (sortable)
    const codeHeader = screen.getByText('エリアコード');
    expect(codeHeader.closest('button')).toBeInTheDocument();
    // name should NOT be a button (not in sortableFields)
    const nameHeader = screen.getByText('エリア名');
    expect(nameHeader.closest('button')).not.toBeInTheDocument();
  });

  it('does NOT show sort icons when sortableFields is omitted', () => {
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'] });
    const header = screen.getByText('エリアコード');
    expect(header.closest('button')).not.toBeInTheDocument();
  });

  it('calls onFiltersChange with sort field on click', async () => {
    const onFiltersChange = vi.fn();
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'], sortableFields: ['code'], filters: {}, onFiltersChange });

    await userEvent.click(screen.getByText('エリアコード'));
    expect(onFiltersChange).toHaveBeenCalledWith({ sort: 'code', page: 1 });
  });

  it('toggles to descending on second click', async () => {
    const onFiltersChange = vi.fn();
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'], sortableFields: ['code'], filters: { sort: 'code' }, onFiltersChange });

    await userEvent.click(screen.getByText('エリアコード'));
    expect(onFiltersChange).toHaveBeenCalledWith({ sort: '-code', page: 1 });
  });

  it('clears sort on third click', async () => {
    const onFiltersChange = vi.fn();
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'], sortableFields: ['code'], filters: { sort: '-code' }, onFiltersChange });

    await userEvent.click(screen.getByText('エリアコード'));
    // emitFilters strips undefined → sort key removed
    expect(onFiltersChange).toHaveBeenCalledWith({ page: 1 });
  });

  it('respects explicit sortable: true on column def even without sortableFields', () => {
    renderTable({
      metadata: ZONE_META,
      data: ZONES,
      columns: [{ field: 'code', sortable: true }],
    });
    const header = screen.getByText('エリアコード');
    expect(header.closest('button')).toBeInTheDocument();
  });
});

// =============================================================================
//  6. Pagination
// =============================================================================

describe('SchemaTable — pagination', () => {
  const PAG = { page: 2, perPage: 10, total: 45 };

  it('shows pagination when pagination prop is set', () => {
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'], pagination: PAG });
    expect(screen.getByText('2 / 5')).toBeInTheDocument(); // page 2 of 5
  });

  it('shows item range', () => {
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'], pagination: PAG });
    expect(screen.getByText(/11–20/)).toBeInTheDocument();
  });

  it('shows total count', () => {
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'], pagination: PAG });
    expect(screen.getByText(/45/)).toBeInTheDocument();
  });

  it('calls onFiltersChange with next page', async () => {
    const onFiltersChange = vi.fn();
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'], pagination: PAG, filters: {}, onFiltersChange });

    // Find the next page button (last button in the pagination area)
    const buttons = screen.getAllByRole('button');
    const nextButton = buttons[buttons.length - 1];
    await userEvent.click(nextButton);

    expect(onFiltersChange).toHaveBeenCalledWith(expect.objectContaining({ page: 3 }));
  });

  it('disables previous button on first page', () => {
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'], pagination: { page: 1, perPage: 10, total: 30 } });
    const buttons = screen.getAllByRole('button');
    const disabledBtns = buttons.filter((b) => (b as HTMLButtonElement).disabled);
    expect(disabledBtns.length).toBeGreaterThanOrEqual(1);
  });

  it('disables next button on last page', () => {
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'], pagination: { page: 3, perPage: 10, total: 30 } });
    const buttons = screen.getAllByRole('button');
    const lastBtn = buttons[buttons.length - 1] as HTMLButtonElement;
    expect(lastBtn.disabled).toBe(true);
  });

  it('hides pagination when showPagination is false', () => {
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'], pagination: PAG, showPagination: false });
    expect(screen.queryByText('2 / 5')).not.toBeInTheDocument();
  });
});

// =============================================================================
//  7. Row actions
// =============================================================================

describe('SchemaTable — actions', () => {
  it('renders actions column with menu trigger', () => {
    renderTable({
      metadata: ZONE_META,
      data: ZONES,
      columns: ['code'],
      actions: [{ label: 'Edit', onClick: vi.fn() }],
    });
    // Each row has an actions button
    const actionBtns = screen.getAllByRole('button', { name: 'Actions' });
    expect(actionBtns.length).toBe(3); // 3 zones
  });

  it('shows action items in dropdown', async () => {
    renderTable({
      metadata: ZONE_META,
      data: ZONES,
      columns: ['code'],
      actions: [
        { label: 'Edit', onClick: vi.fn() },
        { label: 'Delete', onClick: vi.fn(), variant: 'destructive' },
      ],
    });

    await userEvent.click(screen.getAllByRole('button', { name: 'Actions' })[0]);
    expect(screen.getByText('Edit')).toBeInTheDocument();
    expect(screen.getByText('Delete')).toBeInTheDocument();
  });

  it('calls action onClick with row data', async () => {
    const onEdit = vi.fn();
    renderTable({
      metadata: ZONE_META,
      data: ZONES,
      columns: ['code'],
      actions: [{ label: 'Edit', onClick: onEdit }],
    });

    await userEvent.click(screen.getAllByRole('button', { name: 'Actions' })[0]);
    await userEvent.click(screen.getByText('Edit'));
    expect(onEdit).toHaveBeenCalledWith(ZONES[0]);
  });

  it('hides action when hidden returns true', async () => {
    renderTable({
      metadata: ZONE_META,
      data: ZONES,
      columns: ['code'],
      actions: [
        { label: 'Edit', onClick: vi.fn() },
        { label: 'Restore', onClick: vi.fn(), hidden: (row) => (row as typeof ZONES[0]).is_active },
      ],
    });

    // First row (is_active: true) — Restore should be hidden
    await userEvent.click(screen.getAllByRole('button', { name: 'Actions' })[0]);
    expect(screen.getByText('Edit')).toBeInTheDocument();
    expect(screen.queryByText('Restore')).not.toBeInTheDocument();
  });
});

// =============================================================================
//  8. Row click
// =============================================================================

describe('SchemaTable — row click', () => {
  it('calls onRowClick when a row is clicked', async () => {
    const onRowClick = vi.fn();
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'], onRowClick });

    const rows = screen.getAllByRole('row');
    // rows[0] is header, rows[1] is first data row
    await userEvent.click(rows[1]);
    expect(onRowClick).toHaveBeenCalledWith(ZONES[0]);
  });

  it('adds cursor-pointer class when onRowClick is set', () => {
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'], onRowClick: vi.fn() });
    const rows = screen.getAllByRole('row');
    expect(rows[1].className).toContain('cursor-pointer');
  });

  it('does NOT add cursor-pointer when onRowClick is not set', () => {
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['code'] });
    const rows = screen.getAllByRole('row');
    expect(rows[1].className).not.toContain('cursor-pointer');
  });
});

// =============================================================================
//  9. Edge cases
// =============================================================================

describe('SchemaTable — edge cases', () => {
  it('handles empty columns array', () => {
    renderTable({ metadata: ZONE_META, data: ZONES, columns: [] });
    // TanStack still renders rows but with no cells
    const rows = screen.getAllByRole('row');
    // 1 header + 3 data rows (even with 0 columns)
    expect(rows.length).toBe(4);
    // No column headers
    expect(screen.queryByText('エリアコード')).not.toBeInTheDocument();
  });

  it('handles field not in metadata gracefully', () => {
    renderTable({ metadata: ZONE_META, data: ZONES, columns: ['nonexistent'] });
    // Should still render with humanized header
    expect(screen.getByText('Nonexistent')).toBeInTheDocument();
  });

  it('handles mixed string and object columns', () => {
    renderTable({
      metadata: ZONE_META,
      data: ZONES,
      columns: [
        'code',
        { field: 'name', label: 'Custom Name' },
        'is_active',
      ],
    });
    expect(screen.getByText('エリアコード')).toBeInTheDocument();
    expect(screen.getByText('Custom Name')).toBeInTheDocument();
    expect(screen.getByText('有効')).toBeInTheDocument();
  });
});

// =============================================================================
// 10. Unified filter emit
// =============================================================================

describe('SchemaTable — unified filter emit', () => {
  it('strips undefined/null/"" keys from emitted filters', async () => {
    vi.useFakeTimers();
    const onFiltersChange = vi.fn();
    renderTable({
      metadata: ZONE_META, data: ZONES, columns: ['code'],
      sortableFields: ['code'],
      filters: { search: 'old', sort: 'code' },
      onFiltersChange,
    });

    // Clear search → should remove search key entirely, not emit search: undefined
    fireEvent.change(screen.getByPlaceholderText('検索...'), { target: { value: '' } });
    vi.advanceTimersByTime(300);

    const call = onFiltersChange.mock.calls[0][0];
    expect(call).not.toHaveProperty('search');
    expect(call.sort).toBe('code');
    expect(call.page).toBe(1);
    vi.useRealTimers();
  });

  it('emits clean object for pagination change', async () => {
    const onFiltersChange = vi.fn();
    renderTable({
      metadata: ZONE_META, data: ZONES, columns: ['code'],
      pagination: { page: 1, perPage: 10, total: 50 },
      filters: { search: 'test', status: 'active' },
      onFiltersChange,
    });

    // Click next page
    const buttons = screen.getAllByRole('button');
    const nextBtn = buttons[buttons.length - 1];
    await userEvent.click(nextBtn);

    const call = onFiltersChange.mock.calls[0][0];
    expect(call.page).toBe(2);
    expect(call.search).toBe('test');
    expect(call.status).toBe('active');
    // No undefined keys
    for (const v of Object.values(call)) {
      expect(v).not.toBeUndefined();
      expect(v).not.toBeNull();
    }
  });
});

// =============================================================================
// 11. Advanced Filters (filterableFields)
// =============================================================================

describe('SchemaTable — advanced filters', () => {
  it('renders Filters button when filterableFields is set', () => {
    renderTable({
      metadata: PRODUCT_META,
      data: PRODUCTS,
      columns: ['name', 'status'],
      filterableFields: ['status'],
    });
    expect(screen.getByText('フィルター')).toBeInTheDocument();
  });

  it('does NOT render Filters button when filterableFields is empty', () => {
    renderTable({
      metadata: ZONE_META,
      data: ZONES,
      columns: ['code'],
    });
    expect(screen.queryByText('フィルター')).not.toBeInTheDocument();
  });

  it('shows filter count badge when active filters exist', () => {
    renderTable({
      metadata: PRODUCT_META,
      data: PRODUCTS,
      columns: ['name', 'status'],
      filterableFields: ['status'],
      filters: { status: 'active' },
      onFiltersChange: vi.fn(),
    });
    // Badge shows "1"
    expect(screen.getByText('1')).toBeInTheDocument();
  });

  it('calls onFiltersChange when a field filter is set', async () => {
    const onFiltersChange = vi.fn();
    renderTable({
      metadata: PRODUCT_META,
      data: PRODUCTS,
      columns: ['name', 'status'],
      filterableFields: ['is_hidden'],
      filters: {},
      onFiltersChange,
    });

    // Open filter popover
    await userEvent.click(screen.getByText('フィルター'));

    // Toggle the boolean switch for is_hidden
    const switchEl = screen.getByRole('switch');
    await userEvent.click(switchEl);

    expect(onFiltersChange).toHaveBeenCalledWith(expect.objectContaining({ is_hidden: true, page: 1 }));
  });
});

// =============================================================================
// 11. Filter Tags
// =============================================================================

describe('SchemaTable — filter tags', () => {
  it('shows filter tags for active field filters', () => {
    const { container } = renderTable({
      metadata: PRODUCT_META,
      data: PRODUCTS,
      columns: ['name', 'status'],
      filterableFields: ['status'],
      filters: { status: 'active' },
      onFiltersChange: vi.fn(),
    });
    const tagArea = container.querySelector('[data-slot="filter-tags"]');
    expect(tagArea).toBeInTheDocument();
    // Tag contains field label and enum value
    expect(tagArea!.textContent).toContain('ステータス');
    expect(tagArea!.textContent).toContain('販売中');
  });

  it('removes filter when tag X is clicked', async () => {
    const onFiltersChange = vi.fn();
    const { container } = renderTable({
      metadata: PRODUCT_META,
      data: PRODUCTS,
      columns: ['name'],
      filterableFields: ['status'],
      filters: { status: 'active' },
      onFiltersChange,
    });

    // Click X button inside the filter tag
    const tagArea = container.querySelector('[data-slot="filter-tags"]')!;
    const xButton = tagArea.querySelector('button')!;
    await userEvent.click(xButton);

    expect(onFiltersChange).toHaveBeenCalledWith(expect.objectContaining({ page: 1 }));
    const call = onFiltersChange.mock.calls[0][0];
    expect(call.status).toBeUndefined();
  });

  it('shows "Clear all" link when multiple filters active', () => {
    renderTable({
      metadata: PRODUCT_META,
      data: PRODUCTS,
      columns: ['name'],
      filterableFields: ['status', 'is_hidden'],
      filters: { status: 'active', is_hidden: true },
      onFiltersChange: vi.fn(),
    });
    expect(screen.getByText('すべてクリア')).toBeInTheDocument();
  });

  it('does NOT show filter tags when no field filters active', () => {
    const { container } = renderTable({
      metadata: PRODUCT_META,
      data: PRODUCTS,
      columns: ['name'],
      filterableFields: ['status'],
      filters: {},
      onFiltersChange: vi.fn(),
    });
    expect(container.querySelector('[data-slot="filter-tags"]')).not.toBeInTheDocument();
  });

  it('does NOT show filter tags for built-in keys (search, sort)', () => {
    const { container } = renderTable({
      metadata: PRODUCT_META,
      data: PRODUCTS,
      columns: ['name'],
      filterableFields: ['status'],
      filters: { search: 'test', sort: 'name' },
      onFiltersChange: vi.fn(),
    });
    expect(container.querySelector('[data-slot="filter-tags"]')).not.toBeInTheDocument();
  });
});

// =============================================================================
// 12. Column Visibility Toggle
// =============================================================================

describe('SchemaTable — column visibility', () => {
  it('renders Columns button when showColumnToggle is true', () => {
    renderTable({
      metadata: ZONE_META,
      data: ZONES,
      columns: ['code', 'name', 'is_active'],
      showColumnToggle: true,
    });
    expect(screen.getByText('列')).toBeInTheDocument();
  });

  it('does NOT render Columns button when showColumnToggle is false', () => {
    renderTable({
      metadata: ZONE_META,
      data: ZONES,
      columns: ['code'],
    });
    expect(screen.queryByText('列')).not.toBeInTheDocument();
  });

  it('shows all column names in the dropdown', async () => {
    renderTable({
      metadata: ZONE_META,
      data: ZONES,
      columns: ['code', 'name', 'is_active'],
      showColumnToggle: true,
    });

    await userEvent.click(screen.getByText('列'));
    // Column names appear both in table headers and in dropdown — check dropdown has them
    const allCodeTexts = screen.getAllByText('エリアコード');
    expect(allCodeTexts.length).toBeGreaterThanOrEqual(2); // header + dropdown
    const allNameTexts = screen.getAllByText('エリア名');
    expect(allNameTexts.length).toBeGreaterThanOrEqual(2);
  });
});
