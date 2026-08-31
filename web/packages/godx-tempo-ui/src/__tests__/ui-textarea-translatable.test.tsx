import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { Textarea } from '@/textarea';
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

describe('Textarea — standard mode', () => {
  it('renders a standard textarea when translatable is not set', () => {
    render(<Textarea placeholder="Enter text" />);
    expect(screen.getByPlaceholderText('Enter text')).toBeInTheDocument();
  });

  it('accepts string value and onChange', () => {
    const onChange = vi.fn();
    render(<Textarea value="hello" onChange={onChange} />);
    expect(screen.getByDisplayValue('hello')).toBeInTheDocument();
  });

  it('calls onChange with the change event', () => {
    const onChange = vi.fn();
    render(<Textarea value="" onChange={onChange} />);
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'hi' } });
    expect(onChange).toHaveBeenCalledTimes(1);
  });

  it('forwards data-slot attribute', () => {
    render(<Textarea />);
    expect(screen.getByRole('textbox')).toHaveAttribute('data-slot', 'textarea');
  });
});

describe('Textarea — translatable={true} (requires AppProvider)', () => {
  it('renders locale tab buttons from AppProvider', () => {
    render(
      <WithProvider>
        <Textarea translatable value={{}} onChange={vi.fn()} />
      </WithProvider>,
    );
    expect(screen.getByTitle('English')).toBeInTheDocument();
    expect(screen.getByTitle('Tiếng Việt')).toBeInTheDocument();
  });

  it('shows correct value for active locale', () => {
    render(
      <WithProvider>
        <Textarea translatable value={{ en: 'Hello world', vi: 'Xin chào' }} onChange={vi.fn()} />
      </WithProvider>,
    );
    expect(screen.getByRole('textbox')).toHaveValue('Hello world');
  });

  it('switches to VI value when VI tab is clicked', () => {
    render(
      <WithProvider>
        <Textarea translatable value={{ en: 'Hello', vi: 'Xin chào' }} onChange={vi.fn()} />
      </WithProvider>,
    );
    fireEvent.click(screen.getByTitle('Tiếng Việt'));
    expect(screen.getByRole('textbox')).toHaveValue('Xin chào');
  });

  it('calls onChange with full TranslatableValue map when typing', () => {
    const onChange = vi.fn();
    render(
      <WithProvider>
        <Textarea translatable value={{ en: '', vi: 'Xin chào' }} onChange={onChange} />
      </WithProvider>,
    );
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Hello world' } });
    expect(onChange).toHaveBeenCalledWith({ en: 'Hello world', vi: 'Xin chào' });
  });

  it('adds data-translatable attribute on the native textarea', () => {
    render(
      <WithProvider>
        <Textarea translatable value={{}} onChange={vi.fn()} />
      </WithProvider>,
    );
    expect(screen.getByRole('textbox')).toHaveAttribute('data-translatable');
  });

  it('shows fallback placeholder on empty non-fallback locale', () => {
    render(
      <WithProvider>
        <Textarea translatable value={{ en: 'Description here' }} onChange={vi.fn()} />
      </WithProvider>,
    );
    fireEvent.click(screen.getByTitle('Tiếng Việt'));
    expect(screen.getByRole('textbox')).toHaveAttribute('placeholder', 'Description here');
  });

  it('falls back to standard textarea when no AppProvider is present', () => {
    render(<Textarea translatable value={{} as TranslatableValue} onChange={vi.fn()} />);
    expect(screen.queryByTitle('English')).not.toBeInTheDocument();
    expect(screen.getByRole('textbox')).toBeInTheDocument();
  });
});

describe('Textarea — per-field translatable config', () => {
  it('renders locale tabs from inline config without AppProvider', () => {
    render(
      <Textarea
        translatable={{ locales: { en: 'English', vi: 'Tiếng Việt' }, defaultLocale: 'en', fallbackLocale: 'en' }}
        value={{}}
        onChange={vi.fn()}
      />,
    );
    expect(screen.getByTitle('English')).toBeInTheDocument();
    expect(screen.getByTitle('Tiếng Việt')).toBeInTheDocument();
  });

  it('accepts per-field defaultLocale different from first locale', () => {
    render(
      <Textarea
        translatable={{ locales: { en: 'English', vi: 'Tiếng Việt' }, defaultLocale: 'vi', fallbackLocale: 'en' }}
        value={{ en: 'Hello', vi: 'Xin chào' }}
        onChange={vi.fn()}
      />,
    );
    expect(screen.getByTitle('Tiếng Việt').className).toContain('bg-primary');
    expect(screen.getByRole('textbox')).toHaveValue('Xin chào');
  });
});
