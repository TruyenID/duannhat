import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { Badge } from '@/badge';

describe('Badge', () => {
  // ── Rendering ──
  it('renders with default props', () => {
    render(<Badge>New</Badge>);
    expect(screen.getByText('New')).toBeInTheDocument();
  });

  // ── Variant prop ──
  it('applies default variant (solid primary)', () => {
    render(<Badge>Default</Badge>);
    const badge = screen.getByText('Default');
    expect(badge.className).toContain('bg-primary');
    expect(badge.className).toContain('text-primary-foreground');
  });

  it('applies destructive variant (legacy)', () => {
    render(<Badge variant="destructive">Error</Badge>);
    const badge = screen.getByText('Error');
    expect(badge.className).toContain('bg-destructive');
  });

  it('applies secondary variant', () => {
    render(<Badge variant="secondary">Draft</Badge>);
    const badge = screen.getByText('Draft');
    expect(badge.className).toContain('bg-secondary');
  });

  it('applies outline variant', () => {
    render(<Badge variant="outline">v1.0</Badge>);
    const badge = screen.getByText('v1.0');
    expect(badge.className).toContain('text-foreground');
  });

  it('applies soft variant', () => {
    render(<Badge variant="soft">Soft</Badge>);
    const badge = screen.getByText('Soft');
    expect(badge.className).toContain('bg-primary/10');
    expect(badge.className).toContain('text-primary');
  });

  // ── Color prop ──
  it('applies color="success"', () => {
    render(<Badge color="success">Done</Badge>);
    const badge = screen.getByText('Done');
    expect(badge.className).toContain('bg-success');
    expect(badge.className).toContain('text-success-foreground');
  });

  it('applies color="warning"', () => {
    render(<Badge color="warning">Pending</Badge>);
    const badge = screen.getByText('Pending');
    expect(badge.className).toContain('bg-warning');
  });

  it('applies color="info"', () => {
    render(<Badge color="info">Info</Badge>);
    const badge = screen.getByText('Info');
    expect(badge.className).toContain('bg-info');
  });

  it('applies color="destructive"', () => {
    render(<Badge color="destructive">Failed</Badge>);
    const badge = screen.getByText('Failed');
    expect(badge.className).toContain('bg-destructive');
  });

  // ── Variant × Color combinations ──
  it('combines variant="soft" with color="success"', () => {
    render(<Badge variant="soft" color="success">Approved</Badge>);
    const badge = screen.getByText('Approved');
    expect(badge.className).toContain('bg-success/10');
    expect(badge.className).toContain('text-success');
  });

  it('combines variant="outline" with color="warning"', () => {
    render(<Badge variant="outline" color="warning">Review</Badge>);
    const badge = screen.getByText('Review');
    expect(badge.className).toContain('border-warning/50');
    expect(badge.className).toContain('text-warning');
  });

  it('combines variant="soft" with color="destructive"', () => {
    render(<Badge variant="soft" color="destructive">Rejected</Badge>);
    const badge = screen.getByText('Rejected');
    expect(badge.className).toContain('bg-destructive/10');
    expect(badge.className).toContain('text-destructive');
  });

  it('combines variant="outline" with color="info"', () => {
    render(<Badge variant="outline" color="info">Beta</Badge>);
    const badge = screen.getByText('Beta');
    expect(badge.className).toContain('border-info/50');
    expect(badge.className).toContain('text-info');
  });

  // ── className override ──
  it('merges custom className', () => {
    render(<Badge className="my-class">Custom</Badge>);
    expect(screen.getByText('Custom').className).toContain('my-class');
  });
});
