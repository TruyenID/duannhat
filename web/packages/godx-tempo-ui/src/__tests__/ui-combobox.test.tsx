import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { Combobox } from '@/combobox';

const OPTIONS = [
  { value: 'apple', label: 'Apple' },
  { value: 'banana', label: 'Banana' },
  { value: 'cherry', label: 'Cherry' },
];

describe('Combobox', () => {
  it('renders with placeholder', () => {
    render(<Combobox options={OPTIONS} placeholder="Select fruit..." />);
    expect(screen.getByText('Select fruit...')).toBeInTheDocument();
  });

  it('renders selected value label', () => {
    render(<Combobox options={OPTIONS} value="banana" />);
    expect(screen.getByText('Banana')).toBeInTheDocument();
  });

  it('renders trigger as combobox role', () => {
    render(<Combobox options={OPTIONS} />);
    expect(screen.getByRole('combobox')).toBeInTheDocument();
  });

  it('renders as disabled', () => {
    render(<Combobox options={OPTIONS} disabled />);
    expect(screen.getByRole('combobox')).toBeDisabled();
  });

  it('renders with empty options array', () => {
    render(<Combobox options={[]} placeholder="No options" />);
    expect(screen.getByText('No options')).toBeInTheDocument();
  });

  it('applies custom className', () => {
    const { container } = render(<Combobox options={OPTIONS} className="my-combo" />);
    expect(container.querySelector('.my-combo')).toBeInTheDocument();
  });

  // Note: Testing dropdown open/search/select in jsdom is unreliable
  // due to Radix Popover + cmdk internals. These interactions are
  // better tested via Storybook interaction tests or Playwright.
});
