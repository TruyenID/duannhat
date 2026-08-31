import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { Slider } from '@/slider';

describe('Slider', () => {
  it('renders slider element', () => {
    render(<Slider defaultValue={[50]} />);
    expect(screen.getByRole('slider')).toBeInTheDocument();
  });

  it('renders with correct value', () => {
    render(<Slider value={[75]} />);
    const slider = screen.getByRole('slider');
    expect(slider).toHaveAttribute('aria-valuenow', '75');
  });

  it('renders with min and max', () => {
    render(<Slider value={[5]} min={0} max={10} />);
    const slider = screen.getByRole('slider');
    expect(slider).toHaveAttribute('aria-valuemin', '0');
    expect(slider).toHaveAttribute('aria-valuemax', '10');
  });

  it('renders as disabled', () => {
    render(<Slider disabled defaultValue={[50]} />);
    // Radix Slider uses data-disabled attribute, not native disabled
    expect(screen.getByRole('slider')).toHaveAttribute('data-disabled');
  });

  it('sets data-slot attribute', () => {
    const { container } = render(<Slider defaultValue={[50]} />);
    expect(container.querySelector('[data-slot="slider"]')).toBeInTheDocument();
  });

  it('applies custom className', () => {
    const { container } = render(<Slider defaultValue={[50]} className="w-64" />);
    const root = container.querySelector('[data-slot="slider"]');
    expect(root?.className).toContain('w-64');
  });
});
