import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import { SchemaField, type SchemaMetadata } from '@/schema-field';
import { UIProvider as AppProvider } from '@/internal/ui-provider';

// ─── Test Helpers ────────────────────────────────────────────────────────────


function WithProvider({ children }: { children: React.ReactNode }) {
  return (
    <AppProvider locales={{ ja: "日本語", en: "English", vi: "Tiếng Việt" }} defaultLocale="ja" fallbackLocale="en">
      {children}
    </AppProvider>
  );
}

function renderField(
  props: Partial<React.ComponentProps<typeof SchemaField>> & { metadata: SchemaMetadata; field: string },
) {
  const defaultProps = { value: '', onChange: vi.fn() };
  return render(
    <WithProvider>
      <SchemaField {...defaultProps} {...props} />
    </WithProvider>,
  );
}

// ─── Fixtures ────────────────────────────────────────────────────────────────

const ZONE_META: SchemaMetadata = {
  modelName: 'Zone',
  fields: {
    code: {
      type: 'string',
      required: true,
      nullable: false,
      translatable: false,
      validation: { maxLength: 50 },
      label: { ja: 'エリアコード', en: 'Zone Code', vi: 'Mã khu vực' },
      description: { ja: '一意のコード', en: 'Unique code', vi: 'Ma unique' },
    },
    name: {
      type: 'string',
      required: true,
      nullable: false,
      translatable: false,
      validation: { maxLength: 255 },
      label: { ja: 'エリア名', en: 'Zone Name', vi: 'Tên khu vực' },
    },
    description: {
      type: 'text',
      required: false,
      nullable: true,
      translatable: false,
      validation: {},
      label: { ja: '説明', en: 'Description', vi: 'Mô tả' },
    },
    display_order: {
      type: 'number',
      required: true,
      nullable: false,
      translatable: false,
      validation: { min: 0, max: 999 },
      label: { ja: '表示順', en: 'Display Order', vi: 'Thứ tự' },
    },
    is_active: {
      type: 'boolean',
      required: true,
      nullable: false,
      translatable: false,
      validation: {},
      label: { ja: '有効', en: 'Active', vi: 'Hoạt động' },
      description: { ja: '無効エリアは非表示', en: 'Inactive zones hidden', vi: 'Khu vuc vo hieu se an' },
    },
    branch_id: {
      type: 'uuid',
      required: true,
      nullable: false,
      translatable: false,
      validation: {},
      label: { ja: '店舗', en: 'Branch', vi: 'Chi nhánh' },
      relation: { model: 'Branch', table: 'branches', onDelete: 'CASCADE' },
    },
  },
};

const PRODUCT_META: SchemaMetadata = {
  modelName: 'Product',
  fields: {
    name: {
      type: 'string',
      required: true,
      nullable: false,
      translatable: true,
      validation: { maxLength: 255 },
      label: { ja: '商品名', en: 'Product Name', vi: 'Tên sản phẩm' },
      placeholder: { ja: '商品名を入力してください', en: 'Enter product name', vi: 'Nhap ten san pham' },
    },
    status: {
      type: 'enum',
      required: true,
      nullable: false,
      translatable: false,
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
  },
};

// =============================================================================
//  1. Rendering — correct component per type
// =============================================================================

describe('SchemaField — type → component mapping', () => {
  it('renders <input> for type: "string"', () => {
    renderField({ metadata: ZONE_META, field: 'code' });
    expect(screen.getByRole('textbox')).toBeInTheDocument();
  });

  it('renders <textarea> for type: "text"', () => {
    renderField({ metadata: ZONE_META, field: 'description' });
    expect(screen.getByRole('textbox').tagName).toBe('TEXTAREA');
  });

  it('renders <input type="number"> for type: "number"', () => {
    renderField({ metadata: ZONE_META, field: 'display_order', value: 0 });
    expect(screen.getByRole('spinbutton')).toBeInTheDocument();
  });

  it('renders <switch> for type: "boolean"', () => {
    renderField({ metadata: ZONE_META, field: 'is_active', value: true });
    expect(screen.getByRole('switch')).toBeInTheDocument();
  });

  it('renders <checkbox> for type: "boolean" with asCheckbox', () => {
    renderField({ metadata: ZONE_META, field: 'is_active', value: true, asCheckbox: true });
    expect(screen.getByRole('checkbox')).toBeInTheDocument();
  });

  it('renders date input for type: "date"', () => {
    const dateMeta: SchemaMetadata = {
      modelName: 'Event',
      fields: {
        event_date: { type: 'date', required: true, nullable: false, translatable: false, validation: {}, label: { en: 'Date' } },
      },
    };
    renderField({ metadata: dateMeta, field: 'event_date' });
    const input = screen.getByDisplayValue('');
    expect(input).toHaveAttribute('type', 'date');
  });

  it('renders datetime-local input for type: "datetime"', () => {
    const dtMeta: SchemaMetadata = {
      modelName: 'Order',
      fields: {
        paid_at: { type: 'datetime', required: false, nullable: true, translatable: false, validation: {}, label: { en: 'Paid At' } },
      },
    };
    renderField({ metadata: dtMeta, field: 'paid_at' });
    const input = screen.getByDisplayValue('');
    expect(input).toHaveAttribute('type', 'datetime-local');
  });

  it('renders password input for type: "password"', () => {
    const passMeta: SchemaMetadata = {
      modelName: 'User',
      fields: {
        password: { type: 'password', required: true, nullable: false, translatable: false, validation: {}, label: { en: 'Password' } },
      },
    };
    renderField({ metadata: passMeta, field: 'password' });
    const input = document.querySelector('input[type="password"]');
    expect(input).toBeInTheDocument();
  });
});

// =============================================================================
//  2. Labels
// =============================================================================

describe('SchemaField — labels', () => {
  it('renders label from metadata in current locale (ja)', () => {
    renderField({ metadata: ZONE_META, field: 'code' });
    expect(screen.getByText('エリアコード')).toBeInTheDocument();
  });

  it('shows required asterisk for required fields', () => {
    renderField({ metadata: ZONE_META, field: 'code' });
    expect(screen.getByText('*')).toBeInTheDocument();
  });

  it('does NOT show asterisk for optional fields', () => {
    renderField({ metadata: ZONE_META, field: 'description' });
    expect(screen.queryByText('*')).not.toBeInTheDocument();
  });

  it('respects label override prop', () => {
    renderField({ metadata: ZONE_META, field: 'code', label: 'Custom Label' });
    expect(screen.getByText('Custom Label')).toBeInTheDocument();
    expect(screen.queryByText('エリアコード')).not.toBeInTheDocument();
  });

  it('hides label when hideLabel is true', () => {
    renderField({ metadata: ZONE_META, field: 'code', hideLabel: true });
    expect(screen.queryByText('エリアコード')).not.toBeInTheDocument();
  });

  it('falls back to "en" label when current locale is missing', () => {
    const meta: SchemaMetadata = {
      modelName: 'Test',
      fields: {
        name: { type: 'string', required: false, nullable: false, translatable: false, validation: {}, label: { en: 'English Only' } },
      },
    };
    renderField({ metadata: meta, field: 'name' });
    expect(screen.getByText('English Only')).toBeInTheDocument();
  });

  it('humanizes field key when no label exists', () => {
    const meta: SchemaMetadata = {
      modelName: 'Test',
      fields: {
        some_field: { type: 'string', required: false, nullable: false, translatable: false, validation: {}, label: {} },
      },
    };
    renderField({ metadata: meta, field: 'some_field' });
    expect(screen.getByText('Some field')).toBeInTheDocument();
  });
});

// =============================================================================
//  3. Description
// =============================================================================

describe('SchemaField — description', () => {
  it('renders description from metadata', () => {
    renderField({ metadata: ZONE_META, field: 'code' });
    expect(screen.getByText('一意のコード')).toBeInTheDocument();
  });

  it('does not render description when field has none', () => {
    renderField({ metadata: ZONE_META, field: 'name' });
    const wrapper = screen.getByText('エリア名').closest('[data-slot="schema-field"]')!;
    const descPs = wrapper.querySelectorAll('p.text-muted-foreground');
    // no description paragraph (only label + input)
    expect(descPs.length).toBe(0);
  });

  it('hides description when hideDescription is true', () => {
    renderField({ metadata: ZONE_META, field: 'code', hideDescription: true });
    expect(screen.queryByText('一意のコード')).not.toBeInTheDocument();
  });

  it('respects description override prop', () => {
    renderField({ metadata: ZONE_META, field: 'code', description: 'Custom desc' });
    expect(screen.getByText('Custom desc')).toBeInTheDocument();
  });
});

// =============================================================================
//  4. Placeholder
// =============================================================================

describe('SchemaField — placeholder', () => {
  it('auto-derives placeholder from label in ja', () => {
    renderField({ metadata: ZONE_META, field: 'code' });
    expect(screen.getByPlaceholderText('エリアコードを入力')).toBeInTheDocument();
  });

  it('uses explicit placeholder from metadata when available', () => {
    renderField({ metadata: PRODUCT_META, field: 'name', value: { ja: '', en: '', vi: '' } });
    // The translatable input should have the ja placeholder
    const input = screen.getByRole('textbox');
    expect(input).toHaveAttribute('placeholder', '商品名を入力してください');
  });

  it('respects placeholder override prop', () => {
    renderField({ metadata: ZONE_META, field: 'code', placeholder: 'Custom PH' });
    expect(screen.getByPlaceholderText('Custom PH')).toBeInTheDocument();
  });
});

// =============================================================================
//  5. onChange
// =============================================================================

describe('SchemaField — onChange', () => {
  it('calls onChange with string for type: "string"', async () => {
    const onChange = vi.fn();
    renderField({ metadata: ZONE_META, field: 'code', value: '', onChange });
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'ABC' } });
    expect(onChange).toHaveBeenCalledWith('ABC');
  });

  it('calls onChange with number for type: "number"', () => {
    const onChange = vi.fn();
    renderField({ metadata: ZONE_META, field: 'display_order', value: 0, onChange });
    fireEvent.change(screen.getByRole('spinbutton'), { target: { value: '42' } });
    expect(onChange).toHaveBeenCalledWith(42);
  });

  it('calls onChange with empty string for cleared number', () => {
    const onChange = vi.fn();
    renderField({ metadata: ZONE_META, field: 'display_order', value: 5, onChange });
    fireEvent.change(screen.getByRole('spinbutton'), { target: { value: '' } });
    expect(onChange).toHaveBeenCalledWith('');
  });

  it('calls onChange with boolean for type: "boolean" (switch)', async () => {
    const onChange = vi.fn();
    renderField({ metadata: ZONE_META, field: 'is_active', value: false, onChange });
    await userEvent.click(screen.getByRole('switch'));
    expect(onChange).toHaveBeenCalledWith(true);
  });

  it('calls onChange with boolean for type: "boolean" (checkbox)', async () => {
    const onChange = vi.fn();
    renderField({ metadata: ZONE_META, field: 'is_active', value: false, onChange, asCheckbox: true });
    await userEvent.click(screen.getByRole('checkbox'));
    expect(onChange).toHaveBeenCalledWith(true);
  });
});

// =============================================================================
//  6. Enum (Select)
// =============================================================================

describe('SchemaField — enum', () => {
  it('renders select trigger with current value', () => {
    renderField({ metadata: PRODUCT_META, field: 'status', value: 'draft' });
    // Radix Select renders the value text
    expect(screen.getByText('下書き')).toBeInTheDocument();
  });

  // Note: Opening Radix Select in jsdom throws `hasPointerCapture is not a function`.
  // Testing dropdown open behavior requires a real browser (Playwright/Storybook interaction tests).
  // We verify the trigger renders correctly and the component doesn't crash.

  it('renders select trigger as combobox role', () => {
    renderField({ metadata: PRODUCT_META, field: 'status', value: 'draft' });
    expect(screen.getByRole('combobox')).toBeInTheDocument();
  });
});

// =============================================================================
//  7. Error display
// =============================================================================

describe('SchemaField — errors', () => {
  it('shows string error below the field', () => {
    renderField({ metadata: ZONE_META, field: 'code', error: 'Code taken' });
    expect(screen.getByText('Code taken')).toBeInTheDocument();
  });

  it('shows first item from string[] error', () => {
    renderField({ metadata: ZONE_META, field: 'code', error: ['Required', 'Too short'] });
    expect(screen.getByText('Required')).toBeInTheDocument();
    expect(screen.queryByText('Too short')).not.toBeInTheDocument();
  });

  it('applies destructive style to label on error', () => {
    renderField({ metadata: ZONE_META, field: 'code', error: 'Error' });
    const label = screen.getByText('エリアコード');
    expect(label.className).toContain('text-destructive');
  });

  it('sets aria-invalid on input when error exists', () => {
    renderField({ metadata: ZONE_META, field: 'code', error: 'Error' });
    expect(screen.getByRole('textbox')).toHaveAttribute('aria-invalid', 'true');
  });
});

// =============================================================================
//  8. Translatable fields
// =============================================================================

describe('SchemaField — translatable', () => {
  it('renders locale tab buttons for translatable field', () => {
    renderField({ metadata: PRODUCT_META, field: 'name', value: { ja: '', en: '', vi: '' } });
    expect(screen.getByTitle('日本語')).toBeInTheDocument();
    expect(screen.getByTitle('English')).toBeInTheDocument();
    expect(screen.getByTitle('Tiếng Việt')).toBeInTheDocument();
  });

  it('renders the active locale value in the input', () => {
    renderField({ metadata: PRODUCT_META, field: 'name', value: { ja: 'カレー', en: 'Curry', vi: '' } });
    // Default locale is ja, so input should show ja value
    expect(screen.getByDisplayValue('カレー')).toBeInTheDocument();
  });

  it('auto-wraps plain string as locale map (with dev warning)', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    renderField({ metadata: PRODUCT_META, field: 'name', value: 'plain string' });
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('[SchemaField]'));
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('plain string'));
    warn.mockRestore();
  });
});

// =============================================================================
//  9. Edge cases — guards
// =============================================================================

describe('SchemaField — edge cases', () => {
  it('renders nothing and warns when field does not exist', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const { container } = renderField({ metadata: ZONE_META, field: 'nonexistent' });
    expect(container.querySelector('[data-slot="schema-field"]')).toBeNull();
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('Field "nonexistent" not found'));
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('Available: code, name'));
    warn.mockRestore();
  });

  it('renders nothing and warns when metadata is null', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const { container } = render(
      <WithProvider>
        <SchemaField metadata={null as unknown as SchemaMetadata} field="x" value="" onChange={vi.fn()} />
      </WithProvider>,
    );
    expect(container.querySelector('[data-slot="schema-field"]')).toBeNull();
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('metadata prop is undefined'));
    warn.mockRestore();
  });

  it('falls back to <Input> and warns for unknown type', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const meta: SchemaMetadata = {
      modelName: 'X',
      fields: {
        weird: { type: 'custom_xyz', required: false, nullable: false, translatable: false, validation: {}, label: { en: 'Weird' } },
      },
    };
    renderField({ metadata: meta, field: 'weird' });
    expect(screen.getByRole('textbox')).toBeInTheDocument();
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('Unknown field type "custom_xyz"'));
    warn.mockRestore();
  });

  it('warns when relation field has no options', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    renderField({ metadata: ZONE_META, field: 'branch_id' });
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('no "options" prop provided'));
    warn.mockRestore();
  });

  it('warns when enum has no enumValues', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const meta: SchemaMetadata = {
      modelName: 'X',
      fields: {
        status: { type: 'enum', required: true, nullable: false, translatable: false, validation: {}, label: { en: 'Status' } },
      },
    };
    renderField({ metadata: meta, field: 'status', value: '' });
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('no enumValues'));
    warn.mockRestore();
  });
});

// =============================================================================
// 10. Disabled / ReadOnly
// =============================================================================

describe('SchemaField — disabled & readOnly', () => {
  it('disables string input when disabled prop is set', () => {
    renderField({ metadata: ZONE_META, field: 'code', disabled: true });
    expect(screen.getByRole('textbox')).toBeDisabled();
  });

  it('sets readOnly on string input', () => {
    renderField({ metadata: ZONE_META, field: 'code', readOnly: true });
    expect(screen.getByRole('textbox')).toHaveAttribute('readOnly');
  });

  it('disables switch when disabled prop is set', () => {
    renderField({ metadata: ZONE_META, field: 'is_active', value: true, disabled: true });
    expect(screen.getByRole('switch')).toBeDisabled();
  });

  it('disables number input when disabled', () => {
    renderField({ metadata: ZONE_META, field: 'display_order', value: 0, disabled: true });
    expect(screen.getByRole('spinbutton')).toBeDisabled();
  });
});

// =============================================================================
// 11. Data attributes
// =============================================================================

describe('SchemaField — data attributes', () => {
  it('sets data-slot, data-field, data-type on wrapper', () => {
    renderField({ metadata: ZONE_META, field: 'code' });
    const wrapper = document.querySelector('[data-slot="schema-field"]');
    expect(wrapper).toHaveAttribute('data-field', 'code');
    expect(wrapper).toHaveAttribute('data-type', 'string');
  });

  it('sets data-type="boolean" for boolean fields', () => {
    renderField({ metadata: ZONE_META, field: 'is_active', value: true });
    const wrapper = document.querySelector('[data-slot="schema-field"]');
    expect(wrapper).toHaveAttribute('data-type', 'boolean');
  });

  it('sets data-translatable for translatable fields', () => {
    renderField({ metadata: PRODUCT_META, field: 'name', value: { ja: '', en: '', vi: '' } });
    const wrapper = document.querySelector('[data-slot="schema-field"]');
    expect(wrapper).toHaveAttribute('data-translatable');
  });

  it('does NOT set data-translatable for non-translatable fields', () => {
    renderField({ metadata: ZONE_META, field: 'code' });
    const wrapper = document.querySelector('[data-slot="schema-field"]');
    expect(wrapper).not.toHaveAttribute('data-translatable');
  });
});
