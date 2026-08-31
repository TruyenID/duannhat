import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import { Rating } from '@/rating';

describe('Rating', () => {
  it('renders 5 star buttons by default', () => {
    render(<Rating />);
    const buttons = screen.getAllByRole('button');
    expect(buttons.length).toBe(5);
  });

  it('renders custom max stars', () => {
    render(<Rating max={3} />);
    const buttons = screen.getAllByRole('button');
    expect(buttons.length).toBe(3);
  });

  it('displays numeric value', () => {
    render(<Rating value={3} />);
    expect(screen.getByText('3')).toBeInTheDocument();
  });

  it('calls onChange when star is clicked', async () => {
    const onChange = vi.fn();
    render(<Rating onChange={onChange} />);
    const buttons = screen.getAllByRole('button');
    await userEvent.click(buttons[2]); // 3rd star
    expect(onChange).toHaveBeenCalledWith(3);
  });

  it('does not call onChange when readonly', async () => {
    const onChange = vi.fn();
    render(<Rating readonly onChange={onChange} />);
    const buttons = screen.getAllByRole('button');
    await userEvent.click(buttons[0]);
    expect(onChange).not.toHaveBeenCalled();
  });

  it('does not show numeric value when value is 0', () => {
    render(<Rating value={0} />);
    // Rating hides numeric display when value is 0
    expect(screen.queryByText('0')).not.toBeInTheDocument();
  });
});
