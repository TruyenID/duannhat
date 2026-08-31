import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import { ColorPicker } from '@/color-picker';

describe('ColorPicker', () => {
  it('renders trigger button', () => {
    render(<ColorPicker value="#3B82F6" />);
    const buttons = screen.getAllByRole('button');
    expect(buttons.length).toBeGreaterThanOrEqual(1);
  });

  it('displays hex value on trigger', () => {
    render(<ColorPicker value="#FF0000" />);
    expect(screen.getByText('#FF0000')).toBeInTheDocument();
  });

  it('renders as disabled', () => {
    render(<ColorPicker value="#000000" disabled />);
    const trigger = screen.getAllByRole('button')[0];
    expect(trigger).toBeDisabled();
  });

  it('opens popover when clicked', async () => {
    render(<ColorPicker value="#000000" />);
    const trigger = screen.getAllByRole('button')[0];
    await userEvent.click(trigger);
    // After opening, there should be more buttons (preset swatches)
    const allButtons = screen.getAllByRole('button');
    expect(allButtons.length).toBeGreaterThan(1);
  });

  it('calls onChange when a preset is clicked', async () => {
    const onChange = vi.fn();
    render(<ColorPicker value="#000000" onChange={onChange} />);
    const trigger = screen.getAllByRole('button')[0];
    await userEvent.click(trigger);
    // Click first preset swatch (skip trigger button)
    const allButtons = screen.getAllByRole('button');
    const presetBtn = allButtons[1];
    await userEvent.click(presetBtn);
    expect(onChange).toHaveBeenCalled();
  });

  it('applies custom className', () => {
    const { container } = render(<ColorPicker value="#000" className="my-picker" />);
    expect(container.querySelector('.my-picker')).toBeInTheDocument();
  });
});
