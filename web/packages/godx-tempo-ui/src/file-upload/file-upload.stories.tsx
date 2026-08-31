import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useState } from "react";

import { FileUpload } from "../file-upload";
import { Label } from "../label";

const meta: Meta<typeof FileUpload> = {
  title: "UI/FileUpload",
  component: FileUpload,
  tags: ["autodocs"],
  argTypes: {
    variant: {
      control: "select",
      options: ["dropzone", "compact", "avatar", "gallery", "inline"],
    },
    disabled: { control: "boolean" },
    multiple: { control: "boolean" },
    showPreview: { control: "boolean" },
  },
  parameters: {
    docs: {
      description: {
        component: [
          "File upload component with 5 visual variants, drag-and-drop support, and file previews.",
          "",
          "| Variant | Use case |",
          "|---------|----------|",
          "| `dropzone` (default) | Large drop area with icon + text + file list |",
          "| `compact` | Small button + filename inline |",
          "| `avatar` | Circular image for profile pictures |",
          "| `gallery` | Grid of thumbnails with add button |",
          "| `inline` | Single-line: icon + filename + change/remove |",
        ].join("\n"),
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof FileUpload>;

// ─── Helper ──────────────────────────────────────────────────────────────────

function Controlled({
  label,
  ...props
}: React.ComponentProps<typeof FileUpload> & { label?: string }) {
  const [files, setFiles] = useState<File[]>([]);
  return (
    <div className="flex flex-col gap-1.5 w-96">
      {label && <Label>{label}</Label>}
      <FileUpload value={files} onChange={setFiles} {...props} />
      {files.length > 0 && (
        <p className="text-xs text-muted-foreground">
          {files.length} file(s): {files.map((f) => f.name).join(", ")}
        </p>
      )}
    </div>
  );
}

// =============================================================================
//  1. Dropzone Variant (default)
// =============================================================================

/** Default large drop area — drag-and-drop with icon, text, and file list below. */
export const Dropzone: Story = {
  name: "Dropzone (default)",
  render: () => (
    <Controlled label="Attachments" accept="image/*,.pdf" multiple maxFiles={5} />
  ),
};

/** Dropzone — single file only (no `multiple`). */
export const DropzoneSingle: Story = {
  name: "Dropzone: Single File",
  render: () => (
    <Controlled label="Upload menu PDF" accept="application/pdf" />
  ),
};

/** Dropzone — custom placeholder text. */
export const DropzoneCustomText: Story = {
  name: "Dropzone: Custom Text",
  render: () => (
    <Controlled
      label="Invoice"
      accept=".pdf,.xlsx"
      placeholder="Drop your invoice here"
      hint="or click to browse"
    />
  ),
};

/** Dropzone — disabled state. */
export const DropzoneDisabled: Story = {
  name: "Dropzone: Disabled",
  render: () => (
    <Controlled label="Locked uploader" disabled />
  ),
};

/** Dropzone — without file preview list. */
export const DropzoneNoPreview: Story = {
  name: "Dropzone: No Preview",
  render: () => (
    <Controlled label="Background upload" accept="image/*" multiple showPreview={false} />
  ),
};

// =============================================================================
//  2. Compact Variant
// =============================================================================

/** Compact button — small "Choose file" with filename shown inline. */
export const Compact: Story = {
  name: "Compact",
  render: () => (
    <Controlled variant="compact" label="Document" accept=".pdf,.doc,.docx" />
  ),
};

/** Compact — multiple files. */
export const CompactMultiple: Story = {
  name: "Compact: Multiple",
  render: () => (
    <Controlled variant="compact" label="Attachments" accept="image/*,.pdf" multiple maxFiles={3} />
  ),
};

/** Compact — custom button text. */
export const CompactCustomText: Story = {
  name: "Compact: Custom Text",
  render: () => (
    <Controlled variant="compact" label="CSV Import" accept=".csv" placeholder="Select CSV" hint="No CSV selected" />
  ),
};

/** Compact — disabled. */
export const CompactDisabled: Story = {
  name: "Compact: Disabled",
  render: () => (
    <Controlled variant="compact" label="Locked" disabled />
  ),
};

// =============================================================================
//  3. Avatar Variant
// =============================================================================

/** Avatar — circular upload for profile pictures. */
export const Avatar: Story = {
  name: "Avatar",
  render: () => (
    <Controlled variant="avatar" label="Profile Picture" accept="image/*" maxSize={2 * 1024 * 1024} />
  ),
};

/** Avatar — disabled state. */
export const AvatarDisabled: Story = {
  name: "Avatar: Disabled",
  render: () => (
    <Controlled variant="avatar" label="Profile Picture" accept="image/*" disabled />
  ),
};

// =============================================================================
//  4. Gallery Variant
// =============================================================================

/** Gallery — image grid with add button for multi-image uploads. */
export const Gallery: Story = {
  name: "Gallery",
  render: () => (
    <Controlled variant="gallery" label="Product Photos" accept="image/*" multiple maxFiles={6} />
  ),
};

/** Gallery — limited to 3 images. */
export const GallerySmall: Story = {
  name: "Gallery: 3 Images",
  render: () => (
    <Controlled variant="gallery" label="Event Photos" accept="image/*" multiple maxFiles={3} />
  ),
};

/** Gallery — disabled. */
export const GalleryDisabled: Story = {
  name: "Gallery: Disabled",
  render: () => (
    <Controlled variant="gallery" label="Photos" accept="image/*" multiple maxFiles={4} disabled />
  ),
};

// =============================================================================
//  5. Inline Variant
// =============================================================================

/** Inline — single-line minimal style with paperclip icon. */
export const Inline: Story = {
  name: "Inline",
  render: () => (
    <Controlled variant="inline" label="Resume" accept=".pdf,.doc,.docx" />
  ),
};

/** Inline — custom placeholder text. */
export const InlineCustomText: Story = {
  name: "Inline: Custom Text",
  render: () => (
    <Controlled variant="inline" label="Contract" accept=".pdf" placeholder="Attach contract" />
  ),
};

/** Inline — disabled. */
export const InlineDisabled: Story = {
  name: "Inline: Disabled",
  render: () => (
    <Controlled variant="inline" label="File" disabled />
  ),
};

// =============================================================================
//  6. All Variants Side by Side
// =============================================================================

/** All 5 variants rendered together for visual comparison. */
export const AllVariants: Story = {
  name: "All Variants",
  render: () => {
    const [dropzone, setDropzone] = useState<File[]>([]);
    const [compact, setCompact] = useState<File[]>([]);
    const [avatar, setAvatar] = useState<File[]>([]);
    const [gallery, setGallery] = useState<File[]>([]);
    const [inline, setInline] = useState<File[]>([]);

    return (
      <div className="space-y-8 max-w-lg">
        <div className="space-y-1.5">
          <Label>Dropzone (default)</Label>
          <FileUpload value={dropzone} onChange={setDropzone} accept="image/*,.pdf" multiple maxFiles={3} />
        </div>

        <div className="space-y-1.5">
          <Label>Compact</Label>
          <FileUpload variant="compact" value={compact} onChange={setCompact} accept=".pdf" multiple maxFiles={3} />
        </div>

        <div className="space-y-1.5">
          <Label>Avatar</Label>
          <FileUpload variant="avatar" value={avatar} onChange={setAvatar} accept="image/*" />
        </div>

        <div className="space-y-1.5">
          <Label>Gallery</Label>
          <FileUpload variant="gallery" value={gallery} onChange={setGallery} accept="image/*" multiple maxFiles={6} />
        </div>

        <div className="space-y-1.5">
          <Label>Inline</Label>
          <FileUpload variant="inline" value={inline} onChange={setInline} accept=".pdf,.doc" />
        </div>
      </div>
    );
  },
};
