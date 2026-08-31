import { describe, it, expect } from 'vitest';
import {
  formatCurrency,
  formatCountdown,
  formatExtraDisplay,
  sanitizeAmountInput,
} from './format';

describe('formatCurrency', () => {
  it('formats JPY without dividing by 100', () => {
    expect(formatCurrency(1900, 'JPY')).toBe('￥1,900');
  });

  it('formats JPY zero amount', () => {
    expect(formatCurrency(0, 'JPY')).toBe('￥0');
  });

  it('defaults to JPY when no currency provided', () => {
    expect(formatCurrency(2400)).toBe('￥2,400');
  });

  it('formats USD with 2 decimals', () => {
    expect(formatCurrency(19.99, 'USD')).toBe('$19.99');
  });
});

describe('formatCountdown', () => {
  it('formats seconds to m:ss', () => {
    expect(formatCountdown(299)).toBe('4:59');
    expect(formatCountdown(60)).toBe('1:00');
    expect(formatCountdown(5)).toBe('0:05');
  });
});

describe('formatExtraDisplay', () => {
  it('renders bare label + line price for single-qty add', () => {
    const r = formatExtraDisplay({ label: 'Egg', price: 100, quantity: 1 });
    expect(r.label).toBe('Egg');
    expect(r.sign).toBe('+');
    expect(r.linePrice).toBe(100);
    expect(r.price).toBe('￥100');
  });

  it('prefixes "qty x label" and multiplies price when qty>1', () => {
    const r = formatExtraDisplay({ label: 'Egg', price: 100, quantity: 5 });
    expect(r.label).toBe('5 x Egg');
    expect(r.linePrice).toBe(500);
    expect(r.price).toBe('￥500');
  });

  it('defaults missing quantity to 1 (legacy payload)', () => {
    const r = formatExtraDisplay({ label: 'Cheese', price: 50 });
    expect(r.label).toBe('Cheese');
    expect(r.linePrice).toBe(50);
  });

  it('remove modifier: no qty prefix and hides ¥0 price', () => {
    const r = formatExtraDisplay({
      label: 'Sugar',
      price: 0,
      quantity: 2,
      modifier_type: 'remove',
    });
    expect(r.label).toBe('Sugar');
    expect(r.sign).toBe('−');
    expect(r.price).toBeNull();
  });

  it('remove modifier with positive price renders signed negative total', () => {
    const r = formatExtraDisplay({
      label: 'Bacon',
      price: 80,
      quantity: 2,
      modifier_type: 'remove',
    });
    expect(r.label).toBe('Bacon');
    expect(r.linePrice).toBe(160);
    expect(r.price).toBe('-￥160');
  });
});

describe('sanitizeAmountInput', () => {
  it('passes through plain half-width digits', () => {
    expect(sanitizeAmountInput('7500')).toBe('7500');
  });

  it('folds full-width numerals (Japanese keyboard) to half-width', () => {
    expect(sanitizeAmountInput('７５００')).toBe('7500');
  });

  it('strips grouping separators and currency signs', () => {
    expect(sanitizeAmountInput('1,000')).toBe('1000');
    expect(sanitizeAmountInput('¥775')).toBe('775');
    expect(sanitizeAmountInput('7 500 円')).toBe('7500');
  });

  it('drops decimals (JPY/VND are integer currencies)', () => {
    expect(sanitizeAmountInput('1000.50')).toBe('100050');
  });

  it('returns empty string when no digits present', () => {
    expect(sanitizeAmountInput('abc')).toBe('');
    expect(sanitizeAmountInput('')).toBe('');
  });
});
