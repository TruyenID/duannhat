"use client";

import { useEffect, useMemo, useState } from "react";
import { useTranslations } from "next-intl";
import { apiFetch } from "@/lib/api";
import { useBrand } from "@/context/brand-context";
import { Input } from "@/components/ui/input";
import { Minus, Plus } from "lucide-react";

/**
 * FAQ = bài viết trong category `faq` (không phải bảng riêng — xem
 * `CustomerPostController`). Công khai: khách chưa đăng nhập cũng đọc được,
 * nên trang này KHÔNG có auth guard như các trang khác dưới /account.
 */
interface FaqPost {
  id: string;
  slug: string;
  title: string | null;
  excerpt: string | null;
  content: string | null;
}

/**
 * Chuẩn hoá chuỗi để so khớp tìm kiếm: bỏ dấu tiếng Việt rồi hạ chữ thường, nên
 * gõ "diem het han" vẫn ra "điểm hết hạn". `đ` không mang dấu phụ tách rời được
 * nên NFD không đụng tới nó — phải thay tay.
 */
function foldText(value: string): string {
  return value
    .normalize("NFD")
    .replace(/\p{Diacritic}/gu, "")
    .replace(/đ/g, "d")
    .replace(/Đ/g, "D")
    .toLowerCase();
}

// ---------------------------------------------------------------------------
// AccountFaqView — panel "Câu hỏi thường gặp" trong vỏ 2 cột (#1486).
//
// Accordion viết tay chứ không dùng <details>: chỉ MỘT câu được mở tại một thời
// điểm, và trạng thái mở đó cũng là thứ ô tìm kiếm phải xoá mỗi lần lọc lại.
// ---------------------------------------------------------------------------
export default function AccountFaqView() {
  const t = useTranslations("faq");
  const { currentBranch } = useBrand();

  const [posts, setPosts] = useState<FaqPost[]>([]);
  const [fetching, setFetching] = useState(true);
  const [openId, setOpenId] = useState<string | null>(null);
  const [query, setQuery] = useState("");

  // #1673 — FAQ có hai cấp: câu của HQ và câu riêng của chi nhánh. Không gửi
  // `branch` thì backend chỉ trả câu của HQ, nên khách đứng ở chi nhánh nào
  // phải nói ra chi nhánh đó — kể cả khi chi nhánh tắt kế thừa và bộ câu hỏi
  // của nó hoàn toàn khác.
  const branchSlug = currentBranch.slug;

  useEffect(() => {
    const query = new URLSearchParams({
      category: "faq",
      with_content: "1",
      limit: "50",
    });

    // Chưa chọn chi nhánh (slug rỗng lúc context đang tải) thì bỏ hẳn tham số
    // thay vì gửi `branch=` rỗng — backend coi chuỗi rỗng là không truyền,
    // nhưng gửi rỗng làm URL khác nhau giữa hai lần render và fetch hai lần.
    if (branchSlug) {
      query.set("branch", branchSlug);
    }

    // Cố ý KHÔNG `setFetching(true)` ở đây: gọi setState thẳng trong effect vi
    // phạm `react-hooks/set-state-in-effect`. Hệ quả là đổi chi nhánh thì danh
    // sách cũ đứng yên tới khi danh sách mới về, thay vì nháy sang spinner —
    // chấp nhận được, và trong thực tế người dùng hiếm khi đổi chi nhánh khi
    // đang mở đúng trang FAQ.
    apiFetch<{ data: FaqPost[] }>(`/api/v1/customer/posts?${query.toString()}`, {
      silent401: true,
    })
      .then(({ data }) => setPosts(data))
      .catch(() => setPosts([]))
      .finally(() => setFetching(false));
  }, [branchSlug]);

  // Lọc cả tiêu đề LẪN nội dung: người dùng thường nhớ một từ trong câu trả lời
  // ("voucher") chứ không nhớ câu hỏi được đặt ra sao.
  const visible = useMemo(() => {
    const needle = foldText(query.trim());
    if (needle === "") return posts;
    return posts.filter((post) =>
      foldText(`${post.title ?? ""} ${post.content ?? post.excerpt ?? ""}`).includes(needle),
    );
  }, [posts, query]);

  return (
    <>
      <h2 className="text-2xl font-bold text-primary">{t("title")}</h2>

      {fetching ? (
        <div className="flex items-center justify-center py-20">
          <span className="size-5 animate-spin rounded-full border-2 border-primary border-t-transparent" />
        </div>
      ) : posts.length === 0 ? (
        <p className="py-16 text-center text-sm text-neutral-500">{t("empty")}</p>
      ) : (
        <>
          <div className="mt-6">
            <Input
              type="search"
              value={query}
              onChange={(e) => {
                setQuery(e.target.value);
                // Câu đang mở có thể vừa bị lọc mất — đóng lại để danh sách
                // không đổi chiều cao vì một mục người dùng không còn thấy.
                setOpenId(null);
              }}
              placeholder={t("searchPlaceholder")}
              aria-label={t("searchPlaceholder")}
              className="h-12 rounded-xl border-neutral-200 px-4 text-sm"
            />
          </div>

          {visible.length === 0 ? (
            <p className="py-16 text-center text-sm text-neutral-500">{t("noResults")}</p>
          ) : (
            <ul className="mt-2 divide-y divide-neutral-200">
              {visible.map((post) => {
                const open = openId === post.id;
                const body = post.content ?? post.excerpt;
                const panelId = `faq-panel-${post.id}`;

                return (
                  <li key={post.id}>
                    <button
                      type="button"
                      aria-expanded={open}
                      aria-controls={open && body ? panelId : undefined}
                      onClick={() => setOpenId(open ? null : post.id)}
                      className="flex w-full items-center gap-4 py-5 text-left transition-colors hover:text-primary"
                    >
                      <span className="min-w-0 flex-1 text-base font-medium text-neutral-900">
                        {post.title}
                      </span>
                      {open ? (
                        <Minus className="size-5 shrink-0 text-neutral-400" strokeWidth={1.5} />
                      ) : (
                        <Plus className="size-5 shrink-0 text-neutral-400" strokeWidth={1.5} />
                      )}
                    </button>

                    {open && body && (
                      <div
                        id={panelId}
                        className="-mt-1 whitespace-pre-line pr-9 pb-6 text-sm leading-relaxed text-neutral-500"
                      >
                        {body}
                      </div>
                    )}
                  </li>
                );
              })}
            </ul>
          )}
        </>
      )}
    </>
  );
}
