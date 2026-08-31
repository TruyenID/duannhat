import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import { FileUpload } from '@/file-upload';

// ─── Helpers ─────────────────────────────────────────────────────────────────

function createFile(name: string, size: number, type: string): File {
  const content = new Array(size).fill('a').join('');
  return new File([content], name, { type });
}

// =============================================================================
//  1. Dropzone variant (default)
// =============================================================================

describe('FileUpload — dropzone (default)', () => {
  it('renders the drop zone with default text', () => {
    render(<FileUpload />);
    expect(screen.getByText('Click to upload')).toBeInTheDocument();
    expect(screen.getByText(/or drag and drop/)).toBeInTheDocument();
  });

  it('renders custom placeholder and hint', () => {
    render(<FileUpload placeholder="Drop here" hint="any file" />);
    expect(screen.getByText('Drop here')).toBeInTheDocument();
    expect(screen.getByText(/any file/)).toBeInTheDocument();
  });

  it('shows accepted formats in the description', () => {
    render(<FileUpload accept="image/*,.pdf" />);
    expect(screen.getByText(/image\/\*,.pdf/)).toBeInTheDocument();
  });

  it('shows max file count in the description', () => {
    render(<FileUpload maxFiles={3} />);
    expect(screen.getByText(/3 file/)).toBeInTheDocument();
  });

  it('adds files via hidden input change', () => {
    const onChange = vi.fn();
    render(<FileUpload onChange={onChange} />);
    const input = document.querySelector('input[type="file"]') as HTMLInputElement;
    const file = createFile('test.png', 100, 'image/png');
    fireEvent.change(input, { target: { files: [file] } });
    expect(onChange).toHaveBeenCalledWith([file]);
  });

  it('appends to existing files', () => {
    const onChange = vi.fn();
    const existing = createFile('old.png', 100, 'image/png');
    render(<FileUpload value={[existing]} onChange={onChange} />);
    const input = document.querySelector('input[type="file"]') as HTMLInputElement;
    const newFile = createFile('new.png', 100, 'image/png');
    fireEvent.change(input, { target: { files: [newFile] } });
    expect(onChange).toHaveBeenCalledWith([existing, newFile]);
  });

  it('shows preview list when files exist', () => {
    const file = createFile('photo.jpg', 100, 'image/jpeg');
    render(<FileUpload value={[file]} />);
    expect(screen.getByText('photo.jpg')).toBeInTheDocument();
  });

  it('hides preview list when showPreview is false', () => {
    const file = createFile('photo.jpg', 100, 'image/jpeg');
    render(<FileUpload value={[file]} showPreview={false} />);
    expect(screen.queryByText('photo.jpg')).not.toBeInTheDocument();
  });

  it('does not respond to input change when disabled', () => {
    const onChange = vi.fn();
    render(<FileUpload onChange={onChange} disabled />);
    const input = document.querySelector('input[type="file"]') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [createFile('x.png', 100, 'image/png')] } });
    expect(onChange).not.toHaveBeenCalled();
  });
});

// =============================================================================
//  2. Validation
// =============================================================================

describe('FileUpload — validation', () => {
  it('rejects files exceeding maxSize', () => {
    const onChange = vi.fn();
    render(<FileUpload onChange={onChange} maxSize={1000} />);
    const input = document.querySelector('input[type="file"]') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [createFile('big.pdf', 2000, 'application/pdf')] } });
    expect(onChange).not.toHaveBeenCalled();
    expect(screen.getByRole('alert')).toHaveTextContent(/exceeds/i);
  });

  it('rejects files exceeding maxFiles', () => {
    const onChange = vi.fn();
    const existing = createFile('one.png', 100, 'image/png');
    render(<FileUpload value={[existing]} onChange={onChange} maxFiles={1} />);
    const input = document.querySelector('input[type="file"]') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [createFile('two.png', 100, 'image/png')] } });
    expect(onChange).not.toHaveBeenCalled();
    expect(screen.getByRole('alert')).toHaveTextContent(/maximum/i);
  });
});

// =============================================================================
//  3. Remove file
// =============================================================================

describe('FileUpload — remove', () => {
  it('removes file when remove button is clicked', async () => {
    const onChange = vi.fn();
    const file = createFile('doc.pdf', 100, 'application/pdf');
    render(<FileUpload value={[file]} onChange={onChange} />);
    const removeBtn = screen.getByRole('button', { name: /remove doc\.pdf/i });
    await userEvent.click(removeBtn);
    expect(onChange).toHaveBeenCalledWith([]);
  });

  it('removes correct file from multi-file list', async () => {
    const onChange = vi.fn();
    const files = [
      createFile('a.pdf', 100, 'application/pdf'),
      createFile('b.pdf', 200, 'application/pdf'),
      createFile('c.pdf', 300, 'application/pdf'),
    ];
    render(<FileUpload value={files} onChange={onChange} />);
    const removeBtn = screen.getByRole('button', { name: /remove b\.pdf/i });
    await userEvent.click(removeBtn);
    expect(onChange).toHaveBeenCalledWith([files[0], files[2]]);
  });
});

// =============================================================================
//  4. Compact variant
// =============================================================================

describe('FileUpload — compact', () => {
  it('renders button with "Choose file" text', () => {
    render(<FileUpload variant="compact" />);
    expect(screen.getByRole('button', { name: /choose file/i })).toBeInTheDocument();
  });

  it('shows "No file chosen" when empty', () => {
    render(<FileUpload variant="compact" />);
    expect(screen.getByText('No file chosen')).toBeInTheDocument();
  });

  it('shows filename when file is selected', () => {
    const file = createFile('report.pdf', 100, 'application/pdf');
    render(<FileUpload variant="compact" value={[file]} />);
    // Filename appears either in the inline text or in the preview list
    expect(screen.getAllByText('report.pdf').length).toBeGreaterThanOrEqual(1);
  });

  it('shows file count for multiple files', () => {
    const files = [createFile('a.pdf', 100, 'application/pdf'), createFile('b.pdf', 100, 'application/pdf')];
    render(<FileUpload variant="compact" value={files} multiple />);
    expect(screen.getByText('2 files selected')).toBeInTheDocument();
  });

  it('uses custom placeholder', () => {
    render(<FileUpload variant="compact" placeholder="Select CSV" hint="No CSV" />);
    expect(screen.getByRole('button', { name: /select csv/i })).toBeInTheDocument();
    expect(screen.getByText('No CSV')).toBeInTheDocument();
  });
});

// =============================================================================
//  5. Avatar variant
// =============================================================================

describe('FileUpload — avatar', () => {
  it('renders a circular button', () => {
    render(<FileUpload variant="avatar" />);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('rounded-full');
  });

  it('shows Remove link when file is present', () => {
    const file = createFile('photo.jpg', 100, 'image/jpeg');
    render(<FileUpload variant="avatar" value={[file]} />);
    expect(screen.getByText('Remove')).toBeInTheDocument();
  });

  it('removes file when Remove is clicked', async () => {
    const onChange = vi.fn();
    const file = createFile('photo.jpg', 100, 'image/jpeg');
    render(<FileUpload variant="avatar" value={[file]} onChange={onChange} />);
    await userEvent.click(screen.getByText('Remove'));
    expect(onChange).toHaveBeenCalledWith([]);
  });
});

// =============================================================================
//  6. Gallery variant
// =============================================================================

describe('FileUpload — gallery', () => {
  it('shows add button when under maxFiles', () => {
    render(<FileUpload variant="gallery" maxFiles={3} />);
    // The "+" button exists
    const buttons = screen.getAllByRole('button');
    expect(buttons.length).toBeGreaterThanOrEqual(1);
  });

  it('shows file count text', () => {
    const files = [createFile('a.jpg', 100, 'image/jpeg'), createFile('b.jpg', 100, 'image/jpeg')];
    render(<FileUpload variant="gallery" value={files} maxFiles={4} multiple />);
    expect(screen.getByText('2/4 files')).toBeInTheDocument();
  });

  it('hides add button when at maxFiles', () => {
    const files = [createFile('a.jpg', 100, 'image/jpeg'), createFile('b.jpg', 100, 'image/jpeg')];
    const { container } = render(<FileUpload variant="gallery" value={files} maxFiles={2} multiple />);
    // Only remove buttons, no add button with border-dashed
    const addBtn = container.querySelector('.border-dashed');
    expect(addBtn).toBeNull();
  });
});

// =============================================================================
//  7. Inline variant
// =============================================================================

describe('FileUpload — inline', () => {
  it('shows "Attach file" link when empty', () => {
    render(<FileUpload variant="inline" />);
    expect(screen.getByText('Attach file')).toBeInTheDocument();
  });

  it('uses custom placeholder', () => {
    render(<FileUpload variant="inline" placeholder="Add contract" />);
    expect(screen.getByText('Add contract')).toBeInTheDocument();
  });

  it('shows filename, Change and Remove when file exists', () => {
    const file = createFile('contract.pdf', 5000, 'application/pdf');
    render(<FileUpload variant="inline" value={[file]} />);
    expect(screen.getByText('contract.pdf')).toBeInTheDocument();
    expect(screen.getByText('Change')).toBeInTheDocument();
    expect(screen.getByText('Remove')).toBeInTheDocument();
  });

  it('removes file when Remove is clicked', async () => {
    const onChange = vi.fn();
    const file = createFile('doc.pdf', 100, 'application/pdf');
    render(<FileUpload variant="inline" value={[file]} onChange={onChange} />);
    await userEvent.click(screen.getByText('Remove'));
    expect(onChange).toHaveBeenCalledWith([]);
  });
});

// =============================================================================
//  8. Accessibility
// =============================================================================

describe('FileUpload — accessibility', () => {
  it('hidden input has accept attribute', () => {
    render(<FileUpload accept="image/*" />);
    const input = document.querySelector('input[type="file"]');
    expect(input).toHaveAttribute('accept', 'image/*');
  });

  it('hidden input has multiple attribute when multiple is true', () => {
    render(<FileUpload multiple />);
    const input = document.querySelector('input[type="file"]');
    expect(input).toHaveAttribute('multiple');
  });

  it('hidden input is disabled when disabled prop is set', () => {
    render(<FileUpload disabled />);
    const input = document.querySelector('input[type="file"]');
    expect(input).toBeDisabled();
  });

  it('dropzone has role="button"', () => {
    render(<FileUpload />);
    expect(screen.getByRole('button')).toBeInTheDocument();
  });

  it('error message has role="alert"', () => {
    const onChange = vi.fn();
    render(<FileUpload onChange={onChange} maxSize={1} />);
    const input = document.querySelector('input[type="file"]') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [createFile('big.txt', 100, 'text/plain')] } });
    expect(screen.getByRole('alert')).toBeInTheDocument();
  });
});
