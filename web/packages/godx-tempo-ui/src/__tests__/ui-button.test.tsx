import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { Button } from '@/button';

describe('Button', () => {
  // ── Rendering ──
  it('renders with default props', () => {
    render(<Button>Click me</Button>);
    expect(screen.getByRole('button')).toHaveTextContent('Click me');
  });

  it('renders as disabled', () => {
    render(<Button disabled>Disabled</Button>);
    expect(screen.getByRole('button')).toBeDisabled();
  });

  // ── Variant prop ──
  it('applies default variant (solid primary)', () => {
    render(<Button>Default</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('bg-primary');
    expect(btn.className).toContain('text-primary-foreground');
  });

  it('applies destructive variant (legacy backward compat)', () => {
    render(<Button variant="destructive">Delete</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('bg-destructive');
  });

  it('applies secondary variant', () => {
    render(<Button variant="secondary">Secondary</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('bg-secondary');
  });

  it('applies outline variant', () => {
    render(<Button variant="outline">Outline</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('border');
    expect(btn.className).toContain('bg-background');
  });

  it('applies ghost variant', () => {
    render(<Button variant="ghost">Ghost</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('hover:bg-accent');
  });

  it('applies link variant', () => {
    render(<Button variant="link">Link</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('underline-offset-4');
  });

  it('applies soft variant', () => {
    render(<Button variant="soft">Soft</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('bg-primary/10');
    expect(btn.className).toContain('text-primary');
  });

  // ── Color prop ──
  it('applies color="destructive" with default variant', () => {
    render(<Button color="destructive">Delete</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('bg-destructive');
  });

  it('applies color="success" with default variant', () => {
    render(<Button color="success">Approve</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('bg-success');
    expect(btn.className).toContain('text-success-foreground');
  });

  it('applies color="warning" with default variant', () => {
    render(<Button color="warning">Warn</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('bg-warning');
  });

  it('applies color="info" with default variant', () => {
    render(<Button color="info">Info</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('bg-info');
  });

  // ── Variant × Color combinations ──
  it('combines variant="outline" with color="destructive"', () => {
    render(<Button variant="outline" color="destructive">Reject</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('border-destructive/50');
    expect(btn.className).toContain('text-destructive');
  });

  it('combines variant="soft" with color="success"', () => {
    render(<Button variant="soft" color="success">Done</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('bg-success/10');
    expect(btn.className).toContain('text-success');
  });

  it('combines variant="ghost" with color="warning"', () => {
    render(<Button variant="ghost" color="warning">Caution</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('text-warning');
    expect(btn.className).toContain('hover:bg-warning/10');
  });

  it('combines variant="link" with color="info"', () => {
    render(<Button variant="link" color="info">Learn more</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('text-info');
    expect(btn.className).toContain('hover:underline');
  });

  // ── Size prop ──
  it('applies size="xs"', () => {
    render(<Button size="xs">Tiny</Button>);
    expect(screen.getByRole('button').className).toContain('h-element-xs');
  });

  it('applies size="sm"', () => {
    render(<Button size="sm">Small</Button>);
    expect(screen.getByRole('button').className).toContain('h-element-sm');
  });

  it('applies default size', () => {
    render(<Button>Default</Button>);
    expect(screen.getByRole('button').className).toContain('h-element');
  });

  it('applies size="lg"', () => {
    render(<Button size="lg">Large</Button>);
    expect(screen.getByRole('button').className).toContain('h-element-lg');
  });

  it('applies size="xl"', () => {
    render(<Button size="xl">XL</Button>);
    expect(screen.getByRole('button').className).toContain('h-element-xl');
  });

  it('applies size="icon"', () => {
    render(<Button size="icon">+</Button>);
    expect(screen.getByRole('button').className).toContain('size-element');
  });

  // ── Block prop ──
  it('applies block (full width)', () => {
    render(<Button block>Full Width</Button>);
    expect(screen.getByRole('button').className).toContain('w-full');
  });

  it('does not apply w-full without block', () => {
    render(<Button>Normal</Button>);
    expect(screen.getByRole('button').className).not.toContain('w-full');
  });

  // ── className override ──
  it('merges custom className', () => {
    render(<Button className="my-custom-class">Custom</Button>);
    expect(screen.getByRole('button').className).toContain('my-custom-class');
  });
});
