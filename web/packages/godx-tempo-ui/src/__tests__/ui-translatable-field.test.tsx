import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { TranslatableField } from '@/translatable-field';
import type { UILocaleConfig, TranslatableValue } from '@/internal/ui-context';

// ─── Fixtures ──────────────────────────────────────────────────────────────────

const EN_VI_CONFIG: UILocaleConfig = {
  locales: { en: 'English', vi: 'Tiếng Việt' },
  defaultLocale: 'en',
  fallbackLocale: 'en',
};

const EN_VI_JA_CONFIG: UILocaleConfig = {
  locales: { en: 'English', vi: 'Tiếng Việt', ja: '日本語' },
  defaultLocale: 'en',
  fallbackLocale: 'en',
};

function renderField(
  config: UILocaleConfig,
  value: TranslatableValue,
  onChange = vi.fn(),
  errors?: Partial<Record<string, string>>,
) {
  return render(
    <TranslatableField config={config} value={value} onChange={onChange} errors={errors}>
      {({ value: v, onChange: oc, fallbackPlaceholder, hasError }) => (
        <input
          data-testid="field-input"
          aria-invalid={hasError || undefined}
          value={v}
          placeholder={fallbackPlaceholder ?? ''}
          onChange={(e) => oc(e.target.value)}
        />
      )}
    </TranslatableField>,
  );
}

// ─── Tests ─────────────────────────────────────────────────────────────────────

describe('TranslatableField', () => {

  // ── Rendering ──

  it('renders a tab button for each locale', () => {
    renderField(EN_VI_JA_CONFIG, {});
    expect(screen.getByTitle('English')).toBeInTheDocument();
    expect(screen.getByTitle('Tiếng Việt')).toBeInTheDocument();
    expect(screen.getByTitle('日本語')).toBeInTheDocument();
  });

  it('shows locale codes in uppercase on tab buttons', () => {
    renderField(EN_VI_CONFIG, {});
    expect(screen.getByTitle('English')).toHaveTextContent('EN');
    expect(screen.getByTitle('Tiếng Việt')).toHaveTextContent('VI');
  });

  it('activates defaultLocale tab on initial render', () => {
    renderField(EN_VI_CONFIG, {});
    const enBtn = screen.getByTitle('English');
    expect(enBtn.className).toContain('bg-primary');
  });

  it('renders the input via children render prop', () => {
    renderField(EN_VI_CONFIG, { en: 'Hello' });
    expect(screen.getByTestId('field-input')).toBeInTheDocument();
  });

  // ── Value binding ──

  it('passes the active locale value to children', () => {
    renderField(EN_VI_CONFIG, { en: 'Hello', vi: 'Xin chào' });
    expect(screen.getByTestId('field-input')).toHaveValue('Hello');
  });

  it('switches to the vi value after clicking VI tab', () => {
    renderField(EN_VI_CONFIG, { en: 'Hello', vi: 'Xin chào' });
    fireEvent.click(screen.getByTitle('Tiếng Việt'));
    expect(screen.getByTestId('field-input')).toHaveValue('Xin chào');
  });

  it('passes empty string when active locale has no value', () => {
    renderField(EN_VI_CONFIG, { en: 'Hello' });
    fireEvent.click(screen.getByTitle('Tiếng Việt'));
    expect(screen.getByTestId('field-input')).toHaveValue('');
  });

  // ── onChange ──

  it('calls onChange with updated locale map when typing', () => {
    const onChange = vi.fn();
    renderField(EN_VI_CONFIG, { en: '' }, onChange);
    fireEvent.change(screen.getByTestId('field-input'), { target: { value: 'Hello' } });
    expect(onChange).toHaveBeenCalledWith({ en: 'Hello' });
  });

  it('only updates the active locale key when typing', () => {
    const onChange = vi.fn();
    renderField(EN_VI_CONFIG, { en: 'Hello', vi: '' }, onChange);
    fireEvent.click(screen.getByTitle('Tiếng Việt'));
    fireEvent.change(screen.getByTestId('field-input'), { target: { value: 'Xin chào' } });
    expect(onChange).toHaveBeenCalledWith({ en: 'Hello', vi: 'Xin chào' });
  });

  it('preserves existing locale values when updating another locale', () => {
    const onChange = vi.fn();
    const value = { en: 'Hello', vi: 'Xin chào', ja: 'こんにちは' };
    renderField(EN_VI_JA_CONFIG, value, onChange);
    fireEvent.change(screen.getByTestId('field-input'), { target: { value: 'Hi' } });
    expect(onChange).toHaveBeenCalledWith({ en: 'Hi', vi: 'Xin chào', ja: 'こんにちは' });
  });

  // ── Locale switching ──

  it('switches active tab highlight when clicking a different locale', () => {
    renderField(EN_VI_CONFIG, {});
    const viBtn = screen.getByTitle('Tiếng Việt');
    fireEvent.click(viBtn);
    expect(viBtn.className).toContain('bg-primary');
    expect(screen.getByTitle('English').className).not.toContain('bg-primary');
  });

  // ── Dot indicator ──

  it('shows dot indicator on inactive locale tabs that have a value', () => {
    // en is active (default), vi has a value → vi should show the dot
    renderField(EN_VI_CONFIG, { en: 'Hello', vi: 'Xin chào' });
    // The dot is a <span> sibling inside the VI button
    const viBtn = screen.getByTitle('Tiếng Việt');
    const dot = viBtn.querySelector('span');
    expect(dot).toBeInTheDocument();
    expect(dot!.className).toContain('rounded-full');
  });

  it('does not show dot on the active locale tab even if it has a value', () => {
    // en is active and has a value → no dot on EN
    renderField(EN_VI_CONFIG, { en: 'Hello', vi: 'Xin chào' });
    const enBtn = screen.getByTitle('English');
    const dot = enBtn.querySelector('span');
    expect(dot).not.toBeInTheDocument();
  });

  it('does not show dot on inactive locale tabs that are empty', () => {
    // vi has no value → no dot on VI
    renderField(EN_VI_CONFIG, { en: 'Hello' });
    const viBtn = screen.getByTitle('Tiếng Việt');
    const dot = viBtn.querySelector('span');
    expect(dot).not.toBeInTheDocument();
  });

  // ── Fallback placeholder ──

  it('shows no fallback hint when on the fallback locale itself', () => {
    // en = fallbackLocale; on en tab → no hint shown
    renderField(EN_VI_CONFIG, { en: 'Hello' });
    expect(screen.queryByTestId('fallback-hint')).not.toBeInTheDocument();
  });

  it('shows fallback hint on non-fallback locale when that locale is empty', () => {
    renderField(EN_VI_CONFIG, { en: 'Hello', vi: '' });
    fireEvent.click(screen.getByTitle('Tiếng Việt'));
    expect(screen.getByTestId('fallback-hint')).toBeInTheDocument();
  });

  it('passes fallback value as placeholder when switching to empty non-fallback locale', () => {
    renderField(EN_VI_CONFIG, { en: 'Hello' });
    fireEvent.click(screen.getByTitle('Tiếng Việt'));
    expect(screen.getByTestId('field-input')).toHaveAttribute('placeholder', 'Hello');
  });

  it('hides fallback hint when non-fallback locale has a value', () => {
    // VI has a value — hint should NOT be shown
    renderField(EN_VI_CONFIG, { en: 'Hello', vi: 'Xin chào' });
    fireEvent.click(screen.getByTitle('Tiếng Việt'));
    expect(screen.queryByTestId('fallback-hint')).not.toBeInTheDocument();
  });

  // ── Error state ──

  it('sets hasError=true (aria-invalid) on the input when active locale has an error', () => {
    // EN is active, EN has an error
    renderField(EN_VI_CONFIG, { en: 'Hello' }, vi.fn(), { en: 'Required' });
    expect(screen.getByTestId('field-input')).toHaveAttribute('aria-invalid', 'true');
  });

  it('does not set aria-invalid when active locale has no error', () => {
    renderField(EN_VI_CONFIG, { en: 'Hello' }, vi.fn(), { vi: 'Too long' });
    expect(screen.getByTestId('field-input')).not.toHaveAttribute('aria-invalid');
  });

  it('sets aria-invalid when switching to locale that has an error', () => {
    renderField(EN_VI_CONFIG, { en: 'Hello', vi: 'ok' }, vi.fn(), { vi: 'Too long' });
    fireEvent.click(screen.getByTitle('Tiếng Việt'));
    expect(screen.getByTestId('field-input')).toHaveAttribute('aria-invalid', 'true');
  });

  it('shows red dot on inactive locale tab that has an error', () => {
    // EN active, VI has error — VI dot should be bg-destructive
    renderField(EN_VI_CONFIG, {}, vi.fn(), { vi: 'Too long' });
    const viBtn = screen.getByTitle('Tiếng Việt');
    const dot = viBtn.querySelector('span');
    expect(dot).toBeInTheDocument();
    expect(dot!.className).toContain('bg-destructive');
  });

  it('shows red dot on inactive locale tab with error even when the locale has no value', () => {
    // VI is empty but has an error — dot should still appear
    renderField(EN_VI_CONFIG, { en: 'Hello' }, vi.fn(), { vi: 'Required' });
    const viBtn = screen.getByTitle('Tiếng Việt');
    const dot = viBtn.querySelector('span');
    expect(dot).toBeInTheDocument();
    expect(dot!.className).toContain('bg-destructive');
  });

  it('shows blue dot (not red) on inactive locale with value but no error', () => {
    renderField(EN_VI_CONFIG, { en: 'Hello', vi: 'Xin chào' });
    const viBtn = screen.getByTitle('Tiếng Việt');
    const dot = viBtn.querySelector('span');
    expect(dot!.className).toContain('bg-primary');
    expect(dot!.className).not.toContain('bg-destructive');
  });
});
