import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import { Switch } from '@/switch';

describe('Switch', () => {
  it('renders unchecked by default', () => {
    render(<Switch />);
    expect(screen.getByRole('switch')).not.toBeChecked();
  });

  it('renders checked when checked prop is true', () => {
    render(<Switch checked />);
    expect(screen.getByRole('switch')).toBeChecked();
  });

  it('calls onCheckedChange when toggled', async () => {
    const onChange = vi.fn();
    render(<Switch onCheckedChange={onChange} />);
    await userEvent.click(screen.getByRole('switch'));
    expect(onChange).toHaveBeenCalledWith(true);
  });

  it('toggles from on to off', async () => {
    const onChange = vi.fn();
    render(<Switch checked onCheckedChange={onChange} />);
    await userEvent.click(screen.getByRole('switch'));
    expect(onChange).toHaveBeenCalledWith(false);
  });

  it('renders as disabled', () => {
    render(<Switch disabled />);
    expect(screen.getByRole('switch')).toBeDisabled();
  });

  it('does not toggle when disabled', async () => {
    const onChange = vi.fn();
    render(<Switch disabled onCheckedChange={onChange} />);
    await userEvent.click(screen.getByRole('switch'));
    expect(onChange).not.toHaveBeenCalled();
  });

  it('sets data-slot attribute', () => {
    render(<Switch />);
    expect(screen.getByRole('switch')).toHaveAttribute('data-slot', 'switch');
  });
});
