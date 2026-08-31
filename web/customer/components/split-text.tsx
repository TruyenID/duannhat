import { Fragment } from "react";
import type { CSSProperties } from "react";

/**
 * SplitText — chia `text` theo word, wrap mỗi word trong <span> inline-block
 * và apply animation `anim-blur-in-kf` với `animationDelay` tăng dần. Ký tự
 * `\n` được thay bằng <br /> để giữ line-break.
 *
 * Auto-play on mount — chỉ dùng cho headline above-the-fold. Dưới fold
 * dùng `[data-reveal]` + plain text.
 */
type Props = {
  text: string;
  className?: string;
  /** Delay giữa hai word liền kề (ms). */
  wordDelay?: number;
  /** Delay bắt đầu trước word đầu tiên (ms). */
  startDelay?: number;
  /** Element bao ngoài — mặc định `span` để nest được trong <h1>. */
  as?: "span" | "div";
};

export default function SplitText({
  text,
  className,
  wordDelay = 70,
  startDelay = 120,
  as: Tag = "span",
}: Props) {
  const lines = text.split("\n");
  let idx = 0;
  return (
    <Tag className={className} data-split-text>
      {lines.map((line, li) => {
        const words = line.split(" ");
        return (
          <Fragment key={li}>
            {li > 0 && <br />}
            {words.map((word, wi) => {
              const i = idx++;
              const style: CSSProperties = {
                animationDelay: `${startDelay + i * wordDelay}ms`,
              };
              return (
                <Fragment key={wi}>
                  <span style={style}>{word}</span>
                  {wi < words.length - 1 ? " " : ""}
                </Fragment>
              );
            })}
          </Fragment>
        );
      })}
    </Tag>
  );
}
