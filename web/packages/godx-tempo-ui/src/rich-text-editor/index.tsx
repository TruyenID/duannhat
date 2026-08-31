import { useEffect } from "react";
import { EditorContent, useEditor, type Editor } from "@tiptap/react";
import StarterKit from "@tiptap/starter-kit";
import {
  Bold,
  Code,
  Heading1,
  Heading2,
  Heading3,
  Italic,
  List,
  ListOrdered,
  Quote,
  Redo2,
  Strikethrough,
  Undo2,
} from "lucide-react";

import { Button } from "../button";
import { Separator } from "../separator";
import { cn } from "../lib/utils";

export interface RichTextEditorProps {
  value?: string;
  onChange?: (html: string) => void;
  editable?: boolean;
  className?: string;
}

export function RichTextEditor({
  value,
  onChange,
  editable = true,
  className,
}: RichTextEditorProps) {
  const editor = useEditor({
    extensions: [StarterKit],
    content: value ?? "",
    editable,
    immediatelyRender: false,
    editorProps: {
      attributes: {
        class: cn(
          "prose prose-sm dark:prose-invert max-w-none min-h-32 px-3 py-2",
          "focus:outline-none",
          "[&_p]:my-1 [&_h1]:mt-3 [&_h2]:mt-3 [&_h3]:mt-2",
        ),
      },
    },
    onUpdate: ({ editor }) => {
      onChange?.(editor.getHTML());
    },
  });

  // Tiptap's `useEditor` only consumes `content` at mount; subsequent
  // changes to the `value` prop are ignored without an explicit
  // `setContent` call. That breaks every controlled-by-parent usage —
  // most visibly TranslatableRichText, which swaps `value` whenever the
  // user clicks a different locale tab. Re-sync on external value
  // changes, but skip the round-trip that would fire if `value` is just
  // echoing what `onUpdate` already pushed up (compare against the
  // editor's current HTML to avoid an infinite loop and to preserve
  // cursor position during normal typing).
  useEffect(() => {
    if (!editor) return;
    const next = value ?? "";
    if (next === editor.getHTML()) return;
    // `false` skips the onUpdate fire so this prop-driven sync doesn't
    // bounce back through onChange.
    editor.commands.setContent(next, { emitUpdate: false });
  }, [editor, value]);

  if (!editor) {
    return (
      <div
        data-slot="rich-text-editor"
        className={cn(
          "rounded-md border border-input bg-background",
          "h-40 animate-pulse",
          className,
        )}
      />
    );
  }

  return (
    <div
      data-slot="rich-text-editor"
      className={cn(
        "rounded-md border border-input bg-background",
        "focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/50",
        className,
      )}
    >
      {editable ? <EditorToolbar editor={editor} /> : null}
      <EditorContent editor={editor} />
    </div>
  );
}

interface EditorToolbarProps {
  editor: Editor;
}

function EditorToolbar({ editor }: EditorToolbarProps) {
  return (
    <div className="flex flex-wrap items-center gap-0.5 border-b border-border px-1.5 py-1">
      <ToolbarButton
        onClick={() => editor.chain().focus().toggleBold().run()}
        active={editor.isActive("bold")}
        label="Bold"
      >
        <Bold className="size-3.5" />
      </ToolbarButton>
      <ToolbarButton
        onClick={() => editor.chain().focus().toggleItalic().run()}
        active={editor.isActive("italic")}
        label="Italic"
      >
        <Italic className="size-3.5" />
      </ToolbarButton>
      <ToolbarButton
        onClick={() => editor.chain().focus().toggleStrike().run()}
        active={editor.isActive("strike")}
        label="Strikethrough"
      >
        <Strikethrough className="size-3.5" />
      </ToolbarButton>
      <ToolbarButton
        onClick={() => editor.chain().focus().toggleCode().run()}
        active={editor.isActive("code")}
        label="Inline code"
      >
        <Code className="size-3.5" />
      </ToolbarButton>

      <Separator orientation="vertical" className="mx-1 h-5" />

      <ToolbarButton
        onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()}
        active={editor.isActive("heading", { level: 1 })}
        label="Heading 1"
      >
        <Heading1 className="size-3.5" />
      </ToolbarButton>
      <ToolbarButton
        onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}
        active={editor.isActive("heading", { level: 2 })}
        label="Heading 2"
      >
        <Heading2 className="size-3.5" />
      </ToolbarButton>
      <ToolbarButton
        onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}
        active={editor.isActive("heading", { level: 3 })}
        label="Heading 3"
      >
        <Heading3 className="size-3.5" />
      </ToolbarButton>

      <Separator orientation="vertical" className="mx-1 h-5" />

      <ToolbarButton
        onClick={() => editor.chain().focus().toggleBulletList().run()}
        active={editor.isActive("bulletList")}
        label="Bullet list"
      >
        <List className="size-3.5" />
      </ToolbarButton>
      <ToolbarButton
        onClick={() => editor.chain().focus().toggleOrderedList().run()}
        active={editor.isActive("orderedList")}
        label="Ordered list"
      >
        <ListOrdered className="size-3.5" />
      </ToolbarButton>
      <ToolbarButton
        onClick={() => editor.chain().focus().toggleBlockquote().run()}
        active={editor.isActive("blockquote")}
        label="Quote"
      >
        <Quote className="size-3.5" />
      </ToolbarButton>

      <Separator orientation="vertical" className="mx-1 h-5" />

      <ToolbarButton
        onClick={() => editor.chain().focus().undo().run()}
        disabled={!editor.can().undo()}
        label="Undo"
      >
        <Undo2 className="size-3.5" />
      </ToolbarButton>
      <ToolbarButton
        onClick={() => editor.chain().focus().redo().run()}
        disabled={!editor.can().redo()}
        label="Redo"
      >
        <Redo2 className="size-3.5" />
      </ToolbarButton>
    </div>
  );
}

interface ToolbarButtonProps {
  onClick: () => void;
  active?: boolean;
  disabled?: boolean;
  label: string;
  children: React.ReactNode;
}

function ToolbarButton({
  onClick,
  active,
  disabled,
  label,
  children,
}: ToolbarButtonProps) {
  return (
    <Button
      type="button"
      variant="ghost"
      size="xs"
      aria-label={label}
      aria-pressed={active}
      disabled={disabled}
      onClick={onClick}
      className={cn("size-6 p-0", active && "bg-muted text-foreground")}
    >
      {children}
    </Button>
  );
}
