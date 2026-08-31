import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import { Checkbox } from '@/checkbox';

describe('Checkbox', () => {
  it('renders unchecked by default', () => {
    render(<Checkbox />);
    expect(screen.getByRole('checkbox')).not.toBeChecked();
  });

  it('renders checked when checked prop is true', () => {
    render(<Checkbox checked />);
    expect(screen.getByRole('checkbox')).toBeChecked();
  });

  it('calls onCheckedChange when clicked', async () => {
    const onChange = vi.fn();
    render(<Checkbox onCheckedChange={onChange} />);
    await userEvent.click(screen.getByRole('checkbox'));
    expect(onChange).toHaveBeenCalledWith(true);
  });

  it('toggles from checked to unchecked', async () => {
    const onChange = vi.fn();
    render(<Checkbox checked onCheckedChange={onChange} />);
    await userEvent.click(screen.getByRole('checkbox'));
    expect(onChange).toHaveBeenCalledWith(false);
  });

  it('renders as disabled', () => {
    render(<Checkbox disabled />);
    expect(screen.getByRole('checkbox')).toBeDisabled();
  });

  it('does not call onCheckedChange when disabled', async () => {
    const onChange = vi.fn();
    render(<Checkbox disabled onCheckedChange={onChange} />);
    await userEvent.click(screen.getByRole('checkbox'));
    expect(onChange).not.toHaveBeenCalled();
  });

  it('sets data-slot attribute', () => {
    render(<Checkbox />);
    expect(screen.getByRole('checkbox')).toHaveAttribute('data-slot', 'checkbox');
  });

  it('applies custom className', () => {
    render(<Checkbox className="custom-class" />);
    expect(screen.getByRole('checkbox').className).toContain('custom-class');
  });
});
