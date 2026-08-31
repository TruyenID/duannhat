import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import { PasswordInput } from '@/password-input';

describe('PasswordInput', () => {
  it('renders as password type by default', () => {
    render(<PasswordInput />);
    const input = document.querySelector('input');
    expect(input).toHaveAttribute('type', 'password');
  });

  it('toggles to visible text on eye button click', async () => {
    render(<PasswordInput />);
    const toggleBtn = screen.getByRole('button');
    await userEvent.click(toggleBtn);
    const input = document.querySelector('input');
    expect(input).toHaveAttribute('type', 'text');
  });

  it('toggles back to password on second click', async () => {
    render(<PasswordInput />);
    const toggleBtn = screen.getByRole('button');
    await userEvent.click(toggleBtn);
    await userEvent.click(toggleBtn);
    const input = document.querySelector('input');
    expect(input).toHaveAttribute('type', 'password');
  });

  it('accepts value and onChange', () => {
    const onChange = vi.fn();
    render(<PasswordInput value="secret" onChange={onChange} />);
    expect(document.querySelector('input')).toHaveValue('secret');
  });

  it('renders placeholder', () => {
    render(<PasswordInput placeholder="Enter password" />);
    expect(screen.getByPlaceholderText('Enter password')).toBeInTheDocument();
  });

  it('renders as disabled', () => {
    render(<PasswordInput disabled />);
    expect(document.querySelector('input')).toBeDisabled();
  });

  it('applies size variant', () => {
    render(<PasswordInput size="sm" />);
    const input = document.querySelector('input');
    expect(input?.className).toContain('h-element-sm');
  });
});
