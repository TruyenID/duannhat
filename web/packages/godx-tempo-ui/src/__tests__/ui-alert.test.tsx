import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { Alert, AlertTitle, AlertDescription } from '@/alert';

describe('Alert', () => {
  // ── Rendering ──
  it('renders with default props', () => {
    render(<Alert>Hello</Alert>);
    expect(screen.getByRole('alert')).toHaveTextContent('Hello');
  });

  it('renders title and description', () => {
    render(
      <Alert>
        <AlertTitle>Title</AlertTitle>
        <AlertDescription>Description text</AlertDescription>
      </Alert>
    );
    expect(screen.getByText('Title')).toBeInTheDocument();
    expect(screen.getByText('Description text')).toBeInTheDocument();
  });

  // ── Variant prop ──
  it('applies default variant', () => {
    render(<Alert>Default</Alert>);
    const alert = screen.getByRole('alert');
    expect(alert.className).toContain('bg-card');
  });

  it('applies destructive variant (legacy)', () => {
    render(<Alert variant="destructive">Error</Alert>);
    const alert = screen.getByRole('alert');
    expect(alert.className).toContain('text-destructive');
  });

  it('applies soft variant', () => {
    render(<Alert variant="soft">Soft</Alert>);
    const alert = screen.getByRole('alert');
    expect(alert.className).toContain('bg-primary/10');
    expect(alert.className).toContain('text-primary');
  });

  // ── Color prop ──
  it('applies color="success"', () => {
    render(<Alert color="success">Success</Alert>);
    const alert = screen.getByRole('alert');
    expect(alert.className).toContain('text-success');
  });

  it('applies color="warning"', () => {
    render(<Alert color="warning">Warning</Alert>);
    const alert = screen.getByRole('alert');
    expect(alert.className).toContain('text-warning');
  });

  it('applies color="info"', () => {
    render(<Alert color="info">Info</Alert>);
    const alert = screen.getByRole('alert');
    expect(alert.className).toContain('text-info');
  });

  it('applies color="destructive"', () => {
    render(<Alert color="destructive">Error</Alert>);
    const alert = screen.getByRole('alert');
    expect(alert.className).toContain('text-destructive');
  });

  // ── Variant × Color combinations ──
  it('combines variant="soft" with color="success"', () => {
    render(<Alert variant="soft" color="success">Done</Alert>);
    const alert = screen.getByRole('alert');
    expect(alert.className).toContain('bg-success/10');
    expect(alert.className).toContain('text-success');
    expect(alert.className).toContain('border-success/20');
  });

  it('combines variant="soft" with color="warning"', () => {
    render(<Alert variant="soft" color="warning">Caution</Alert>);
    const alert = screen.getByRole('alert');
    expect(alert.className).toContain('bg-warning/10');
    expect(alert.className).toContain('text-warning');
  });

  it('combines variant="soft" with color="destructive"', () => {
    render(<Alert variant="soft" color="destructive">Critical</Alert>);
    const alert = screen.getByRole('alert');
    expect(alert.className).toContain('bg-destructive/10');
    expect(alert.className).toContain('text-destructive');
  });

  it('combines variant="soft" with color="info"', () => {
    render(<Alert variant="soft" color="info">Tip</Alert>);
    const alert = screen.getByRole('alert');
    expect(alert.className).toContain('bg-info/10');
    expect(alert.className).toContain('text-info');
  });

  // ── className override ──
  it('merges custom className', () => {
    render(<Alert className="my-alert">Custom</Alert>);
    expect(screen.getByRole('alert').className).toContain('my-alert');
  });

  // ── Sub-components ──
  it('AlertTitle renders with data-slot', () => {
    render(<AlertTitle>My Title</AlertTitle>);
    const title = screen.getByText('My Title');
    expect(title).toHaveAttribute('data-slot', 'alert-title');
    expect(title.className).toContain('font-medium');
  });

  it('AlertDescription renders with data-slot', () => {
    render(<AlertDescription>My Desc</AlertDescription>);
    const desc = screen.getByText('My Desc');
    expect(desc).toHaveAttribute('data-slot', 'alert-description');
    expect(desc.className).toContain('text-muted-foreground');
  });
});
