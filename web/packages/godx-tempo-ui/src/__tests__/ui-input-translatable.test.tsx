import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { Input } from '@/input';
import { UIProvider as AppProvider } from '@/internal/ui-provider';
import type { TranslatableValue } from '@/internal/ui-context';

// ─── Helpers ──────────────────────────────────────────────────────────────────


function WithProvider({ children }: { children: React.ReactNode }) {
  return (
    <AppProvider locales={{ ja: "日本語", en: "English", vi: "Tiếng Việt" }} defaultLocale="en" fallbackLocale="en">
      {children}
    </AppProvider>
  );
}

// ─── Tests ─────────────────────────────────────────────────────────────────────

describe('Input — standard mode', () => {
  it('renders a standard input when translatable is not set', () => {
    render(<Input placeholder="Enter text" />);
    expect(screen.getByPlaceholderText('Enter text')).toBeInTheDocument();
  });

  it('accepts string value and onChange', () => {
    const onChange = vi.fn();
    render(<Input value="hello" onChange={onChange} />);
    expect(screen.getByDisplayValue('hello')).toBeInTheDocument();
  });

  it('calls onChange with the change event', () => {
    const onChange = vi.fn();
    render(<Input value="" onChange={onChange} />);
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'hi' } });
    expect(onChange).toHaveBeenCalledTimes(1);
  });

  it('applies size variant class', () => {
    render(<Input size="sm" />);
    expect(screen.getByRole('textbox').className).toContain('h-element-sm');
  });

  it('forwards data-slot attribute', () => {
    render(<Input />);
    expect(screen.getByRole('textbox')).toHaveAttribute('data-slot', 'input');
  });
});

describe('Input — translatable={true} (requires AppProvider)', () => {
  it('renders locale tab buttons from AppProvider', () => {
    render(
      <WithProvider>
        <Input translatable value={{}} onChange={vi.fn()} />
      </WithProvider>,
    );
    expect(screen.getByTitle('English')).toBeInTheDocument();
    expect(screen.getByTitle('Tiếng Việt')).toBeInTheDocument();
    expect(screen.getByTitle('日本語')).toBeInTheDocument();
  });

  it('defaults to EN tab active', () => {
    render(
      <WithProvider>
        <Input translatable value={{}} onChange={vi.fn()} />
      </WithProvider>,
    );
    expect(screen.getByTitle('English').className).toContain('bg-primary');
  });

  it('shows correct value for the active locale', () => {
    render(
      <WithProvider>
        <Input translatable value={{ en: 'Hello', vi: 'Xin chào' }} onChange={vi.fn()} />
      </WithProvider>,
    );
    expect(screen.getByRole('textbox')).toHaveValue('Hello');
  });

  it('switches to VI value when VI tab is clicked', () => {
    render(
      <WithProvider>
        <Input translatable value={{ en: 'Hello', vi: 'Xin chào' }} onChange={vi.fn()} />
      </WithProvider>,
    );
    fireEvent.click(screen.getByTitle('Tiếng Việt'));
    expect(screen.getByRole('textbox')).toHaveValue('Xin chào');
  });

  it('calls onChange with full TranslatableValue map when typing', () => {
    const onChange = vi.fn();
    render(
      <WithProvider>
        <Input translatable value={{ en: '', vi: 'Xin chào' }} onChange={onChange} />
      </WithProvider>,
    );
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Hello' } });
    expect(onChange).toHaveBeenCalledWith({ en: 'Hello', vi: 'Xin chào' });
  });

  it('adds data-translatable attribute on the native input', () => {
    render(
      <WithProvider>
        <Input translatable value={{}} onChange={vi.fn()} />
      </WithProvider>,
    );
    expect(screen.getByRole('textbox')).toHaveAttribute('data-translatable');
  });

  it('shows fallback placeholder when switching to an empty non-fallback locale', () => {
    render(
      <WithProvider>
        <Input translatable value={{ en: 'Hello' }} onChange={vi.fn()} />
      </WithProvider>,
    );
    fireEvent.click(screen.getByTitle('Tiếng Việt'));
    expect(screen.getByRole('textbox')).toHaveAttribute('placeholder', 'Hello');
  });

  it('falls back to standard input when no AppProvider is present', () => {
    // No AppProvider — should render a plain input without locale tabs
    render(<Input translatable value={{} as TranslatableValue} onChange={vi.fn()} />);
    expect(screen.queryByTitle('English')).not.toBeInTheDocument();
    expect(screen.getByRole('textbox')).toBeInTheDocument();
  });
});

describe('Input — per-field translatable config', () => {
  it('renders locale tabs from inline config without AppProvider', () => {
    render(
      <Input
        translatable={{ locales: { en: 'English', vi: 'Tiếng Việt' }, defaultLocale: 'en', fallbackLocale: 'en' }}
        value={{}}
        onChange={vi.fn()}
      />,
    );
    expect(screen.getByTitle('English')).toBeInTheDocument();
    expect(screen.getByTitle('Tiếng Việt')).toBeInTheDocument();
  });

  it('merges inline locales with AppProvider when both are present', () => {
    // Inline config overrides provider — only shows inline locales
    render(
      <WithProvider>
        <Input
          translatable={{ locales: { en: 'English', vi: 'Tiếng Việt' }, defaultLocale: 'en', fallbackLocale: 'en' }}
          value={{}}
          onChange={vi.fn()}
        />
      </WithProvider>,
    );
    expect(screen.getByTitle('English')).toBeInTheDocument();
    expect(screen.getByTitle('Tiếng Việt')).toBeInTheDocument();
    expect(screen.queryByTitle('日本語')).not.toBeInTheDocument();
  });

  it('accepts per-field defaultLocale', () => {
    render(
      <Input
        translatable={{ locales: { en: 'English', vi: 'Tiếng Việt' }, defaultLocale: 'vi', fallbackLocale: 'en' }}
        value={{ en: 'Hello', vi: 'Xin chào' }}
        onChange={vi.fn()}
      />,
    );
    // VI tab should be active (default)
    expect(screen.getByTitle('Tiếng Việt').className).toContain('bg-primary');
    expect(screen.getByRole('textbox')).toHaveValue('Xin chào');
  });

  it('handles empty locales object gracefully (renders standard input)', () => {
    render(
      <Input
        translatable={{ locales: {} }}
        value={{} as TranslatableValue}
        onChange={vi.fn()}
      />,
    );
    // No locale tabs — no config → falls back to standard input
    expect(screen.queryByTitle('English')).not.toBeInTheDocument();
    expect(screen.getByRole('textbox')).toBeInTheDocument();
  });
});
