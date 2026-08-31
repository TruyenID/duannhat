import type { CSSProperties, ReactNode } from "react";

/**
 * Marquee — băng chữ trôi ngang vô tận. Render 2 segment giống hệt, CSS
 * translate 0 → -50% tạo vòng lặp seamless. Pure CSS, không JS.
 *
 * Dùng cho dải phân cách editorial (signature words, store names) — không
 * dùng cho nội dung quan trọng hoặc link cần click.
 */
type Props = {
  items: ReactNode[];
  /** Thời gian một vòng loop (giây). Càng lớn càng chậm. */
  speed?: number;
  className?: string;
  /** Class cho mỗi item (font, color, size). */
  itemClassName?: string;
  /** Separator giữa các item — mặc định dấu chấm giữa chiều cao. */
  separator?: ReactNode;
};

/** Inline segment — render không cần component riêng (React 19 rule:
 * không tạo component trong render). */
function renderSegment(
  items: ReactNode[],
  itemClassName: string,
  sep: ReactNode,
  ariaHidden: boolean,
) {
  return (
    <div className="flex shrink-0 items-center" aria-hidden={ariaHidden}>
      {items.map((item, i) => (
        <span
          key={i}
          className={`inline-flex items-center whitespace-nowrap ${itemClassName}`}
        >
          <span>{item}</span>
          {sep}
        </span>
      ))}
    </div>
  );
}

export default function Marquee({
  items,
  speed = 40,
  className = "",
  itemClassName = "",
  separator,
}: Props) {
  const sep = separator ?? (
    <span className="mx-6 text-current opacity-40 md:mx-10" aria-hidden>
      ·
    </span>
  );

  const style = { ["--marquee-speed" as string]: `${speed}s` } as CSSProperties;

  return (
    <div className={`overflow-hidden ${className}`}>
      <div className="marquee-track" style={style}>
        {renderSegment(items, itemClassName, sep, false)}
        {renderSegment(items, itemClassName, sep, true)}
      </div>
    </div>
  );
}
