import * as React from "react";
import { Upload, X, File, FileImage, FileText, FileVideo, ImagePlus, Paperclip, Plus } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "../button";

export type FileUploadVariant = "dropzone" | "compact" | "avatar" | "gallery" | "inline";

export interface FileUploadProps {
  value?: File[];
  onChange?: (files: File[]) => void;
  accept?: string;
  multiple?: boolean;
  maxSize?: number;
  maxFiles?: number;
  disabled?: boolean;
  className?: string;
  showPreview?: boolean;
  variant?: FileUploadVariant;
  placeholder?: string;
  hint?: string;
}

function getFileIcon(file: File) {
  if (file.type.startsWith("image/")) return FileImage;
  if (file.type.startsWith("video/")) return FileVideo;
  if (file.type.startsWith("text/")) return FileText;
  return File;
}

function formatFileSize(bytes: number) {
  if (bytes === 0) return "0 B";
  const k = 1024;
  const sizes = ["B", "KB", "MB", "GB"];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + " " + sizes[i];
}

export function FileUpload({
  value = [],
  onChange,
  accept,
  multiple = false,
  maxSize = 10 * 1024 * 1024,
  maxFiles,
  disabled,
  className,
  showPreview = true,
  variant = "dropzone",
  placeholder,
  hint,
}: FileUploadProps) {
  const inputRef = React.useRef<HTMLInputElement>(null);
  const [dragActive, setDragActive] = React.useState(false);
  const [error, setError] = React.useState<string>("");
  const previewUrls = React.useRef<Map<string, string>>(new Map());

  React.useEffect(() => {
    return () => { for (const url of previewUrls.current.values()) URL.revokeObjectURL(url); previewUrls.current.clear(); };
  }, []);

  const getPreviewUrl = React.useCallback((file: File, index: number): string => {
    const key = `${file.name}-${file.size}-${index}`;
    if (!previewUrls.current.has(key)) previewUrls.current.set(key, URL.createObjectURL(file));
    return previewUrls.current.get(key)!;
  }, []);

  const handleDrag = (e: React.DragEvent) => {
    e.preventDefault(); e.stopPropagation();
    if (e.type === "dragenter" || e.type === "dragover") setDragActive(true);
    else if (e.type === "dragleave") setDragActive(false);
  };

  const validateFiles = (files: File[]): { valid: File[]; error?: string } => {
    setError("");
    if (maxFiles && value.length + files.length > maxFiles) return { valid: [], error: `Maximum ${maxFiles} file(s) allowed` };
    const oversized = files.filter((f) => f.size > maxSize);
    if (oversized.length > 0) return { valid: [], error: `File exceeds ${Math.round(maxSize / 1024 / 1024)}MB limit` };
    return { valid: files };
  };

  const addFiles = (files: File[]) => {
    const { valid, error: err } = validateFiles(files);
    if (err) { setError(err); return; }
    onChange?.([...value, ...valid]);
  };

  const handleDrop = (e: React.DragEvent) => { e.preventDefault(); e.stopPropagation(); setDragActive(false); if (disabled) return; addFiles(Array.from(e.dataTransfer.files)); };
  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => { if (disabled) return; addFiles(Array.from(e.target.files || [])); if (inputRef.current) inputRef.current.value = ""; };
  const handleRemove = (index: number) => { const f = value[index]; const key = `${f.name}-${f.size}-${index}`; const url = previewUrls.current.get(key); if (url) { URL.revokeObjectURL(url); previewUrls.current.delete(key); } onChange?.(value.filter((_, i) => i !== index)); };
  const triggerPick = () => !disabled && inputRef.current?.click();

  const hiddenInput = <input ref={inputRef} type="file" accept={accept} multiple={multiple} onChange={handleChange} disabled={disabled} className="hidden" />;
  const errorEl = error ? <p className="mt-1.5 flex items-center gap-1.5 text-xs font-medium text-destructive" role="alert"><span className="inline-block h-1 w-1 rounded-full bg-destructive" />{error}</p> : null;

  // ── Avatar ──────────────────────────────────────────────────────────────
  if (variant === "avatar") {
    const file = value[0];
    const previewUrl = file?.type.startsWith("image/") ? getPreviewUrl(file, 0) : null;
    return (
      <div className={cn("inline-flex flex-col items-center gap-2", className)}>
        {hiddenInput}
        <button type="button" onClick={triggerPick} disabled={disabled} onDragEnter={handleDrag} onDragLeave={handleDrag} onDragOver={handleDrag} onDrop={handleDrop}
          className={cn("relative h-24 w-24 overflow-hidden rounded-full border-2 border-dashed transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring", dragActive ? "border-primary bg-primary/10" : "border-muted-foreground/25 hover:border-primary/50", disabled && "pointer-events-none opacity-50")}>
          {previewUrl ? <img src={previewUrl} alt="Avatar" className="h-full w-full object-cover" /> : <div className="flex h-full w-full items-center justify-center bg-muted"><ImagePlus className="h-8 w-8 text-muted-foreground" /></div>}
          <div className="absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 transition-opacity hover:opacity-100"><Upload className="h-5 w-5 text-white" /></div>
        </button>
        {file && <button type="button" onClick={() => handleRemove(0)} disabled={disabled} className="text-xs text-muted-foreground hover:text-destructive transition-colors">Remove</button>}
        {errorEl}
      </div>
    );
  }

  // ── Compact ─────────────────────────────────────────────────────────────
  if (variant === "compact") {
    return (
      <div className={cn("flex flex-col gap-1.5", className)}>
        {hiddenInput}
        <div className="flex items-center gap-2">
          <Button type="button" variant="outline" size="sm" onClick={triggerPick} disabled={disabled} className="shrink-0"><Upload className="mr-1.5 h-3.5 w-3.5" />{placeholder ?? "Choose file"}</Button>
          {value.length > 0 ? <span className="truncate text-sm text-muted-foreground">{value.length === 1 ? value[0].name : `${value.length} files selected`}</span> : <span className="text-sm text-muted-foreground">{hint ?? "No file chosen"}</span>}
        </div>
        {showPreview && value.length > 0 && (
          <ul className="space-y-1">
            {value.map((file, i) => (
              <li key={`${file.name}-${i}`} className="flex items-center gap-2 text-sm">
                <Paperclip className="h-3.5 w-3.5 shrink-0 text-muted-foreground" /><span className="truncate flex-1">{file.name}</span><span className="shrink-0 text-xs text-muted-foreground">{formatFileSize(file.size)}</span>
                <button type="button" onClick={() => handleRemove(i)} disabled={disabled} className="shrink-0 text-muted-foreground hover:text-destructive"><X className="h-3.5 w-3.5" /></button>
              </li>
            ))}
          </ul>
        )}
        {errorEl}
      </div>
    );
  }

  // ── Inline ──────────────────────────────────────────────────────────────
  if (variant === "inline") {
    const file = value[0];
    return (
      <div className={cn("flex items-center gap-2", className)}>
        {hiddenInput}
        {file ? (<><Paperclip className="h-4 w-4 shrink-0 text-muted-foreground" /><span className="truncate text-sm flex-1">{file.name}</span><span className="shrink-0 text-xs text-muted-foreground">{formatFileSize(file.size)}</span><button type="button" onClick={triggerPick} disabled={disabled} className="shrink-0 text-xs text-primary hover:underline">Change</button><button type="button" onClick={() => handleRemove(0)} disabled={disabled} className="shrink-0 text-xs text-muted-foreground hover:text-destructive">Remove</button></>) : (
          <button type="button" onClick={triggerPick} disabled={disabled} className={cn("flex items-center gap-1.5 text-sm text-primary hover:underline", disabled && "opacity-50 pointer-events-none")}><Paperclip className="h-4 w-4" />{placeholder ?? "Attach file"}</button>
        )}
        {errorEl}
      </div>
    );
  }

  // ── Gallery ─────────────────────────────────────────────────────────────
  if (variant === "gallery") {
    const canAdd = !maxFiles || value.length < maxFiles;
    return (
      <div className={cn("flex flex-col gap-2", className)}>
        {hiddenInput}
        <div className="flex flex-wrap gap-2">
          {value.map((file, i) => {
            const isImage = file.type.startsWith("image/");
            const url = isImage ? getPreviewUrl(file, i) : null;
            const Icon = getFileIcon(file);
            return (
              <div key={`${file.name}-${i}`} className="group/thumb relative h-20 w-20 overflow-hidden rounded-lg border bg-muted">
                {url ? <img src={url} alt={file.name} className="h-full w-full object-cover" /> : <div className="flex h-full w-full items-center justify-center"><Icon className="h-6 w-6 text-muted-foreground" /></div>}
                <button type="button" onClick={() => handleRemove(i)} disabled={disabled} className="absolute top-0.5 right-0.5 flex h-5 w-5 items-center justify-center rounded-full bg-black/60 text-white opacity-0 transition-opacity group-hover/thumb:opacity-100"><X className="h-3 w-3" /></button>
              </div>
            );
          })}
          {canAdd && (
            <button type="button" onClick={triggerPick} disabled={disabled} onDragEnter={handleDrag} onDragLeave={handleDrag} onDragOver={handleDrag} onDrop={handleDrop}
              className={cn("flex h-20 w-20 items-center justify-center rounded-lg border-2 border-dashed transition-all", dragActive ? "border-primary bg-primary/10" : "border-muted-foreground/25 hover:border-primary/50 hover:bg-accent/50", disabled && "pointer-events-none opacity-50")}>
              <Plus className="h-6 w-6 text-muted-foreground" />
            </button>
          )}
        </div>
        {maxFiles && <p className="text-xs text-muted-foreground">{value.length}/{maxFiles} files</p>}
        {errorEl}
      </div>
    );
  }

  // ── Dropzone (default) ──────────────────────────────────────────────────
  return (
    <div className={cn("w-full", className)}>
      {hiddenInput}
      <div onDragEnter={handleDrag} onDragLeave={handleDrag} onDragOver={handleDrag} onDrop={handleDrop} onClick={triggerPick}
        className={cn("group relative flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-8 cursor-pointer transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2", dragActive ? "border-primary bg-primary/10 text-primary" : "border-muted-foreground/25 hover:border-primary/50 hover:bg-accent/50", disabled && "pointer-events-none opacity-50", error && "border-destructive/50 hover:border-destructive")}
        tabIndex={disabled ? -1 : 0} role="button" onKeyDown={(e) => { if (!disabled && (e.key === "Enter" || e.key === " ")) { e.preventDefault(); triggerPick(); } }}>
        <div className={cn("flex h-12 w-12 items-center justify-center rounded-full transition-colors duration-200", dragActive ? "bg-primary/15 text-primary" : "bg-muted text-muted-foreground group-hover:bg-primary/10 group-hover:text-primary")}>
          <Upload className="h-6 w-6" />
        </div>
        <div className="text-center">
          <p className="text-sm"><span className="font-semibold text-primary">{placeholder ?? "Click to upload"}</span><span className="text-muted-foreground"> {hint ?? "or drag and drop"}</span></p>
          <p className="mt-1.5 text-xs text-muted-foreground">{accept && <span>{accept} · </span>}<span>Max: {Math.round(maxSize / 1024 / 1024)}MB</span>{maxFiles && <span> · {maxFiles} file(s)</span>}</p>
        </div>
      </div>
      {errorEl}
      {showPreview && value.length > 0 && (
        <ul className="mt-3 space-y-2">
          {value.map((file, index) => {
            const FileIcon = getFileIcon(file); const isImage = file.type.startsWith("image/"); const previewUrl = isImage ? getPreviewUrl(file, index) : null;
            return (
              <li key={`${file.name}-${index}`} className="group/item flex items-center gap-3 rounded-lg border bg-card p-2.5 transition-colors duration-150 hover:bg-accent/50">
                {previewUrl ? <img src={previewUrl} alt={file.name} className="h-10 w-10 flex-shrink-0 rounded-md border object-cover" /> : <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-md border bg-muted"><FileIcon className="h-5 w-5 text-muted-foreground" /></div>}
                <div className="min-w-0 flex-1"><p className="truncate text-sm font-medium leading-tight">{file.name}</p><p className="text-xs text-muted-foreground">{formatFileSize(file.size)}</p></div>
                <Button type="button" variant="ghost" size="icon" onClick={(e) => { e.stopPropagation(); handleRemove(index); }} disabled={disabled} className="h-8 w-8 flex-shrink-0 opacity-0 transition-opacity group-hover/item:opacity-100 hover:bg-destructive/10 hover:text-destructive focus-visible:opacity-100"><X className="h-4 w-4" /><span className="sr-only">Remove {file.name}</span></Button>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
