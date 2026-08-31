/**
 * `paths:` của GitHub Actions là bộ lọc GLOB, không phải danh sách chuỗi.
 *
 * #2971 — hai rào từng ghim kỳ vọng bằng **chuỗi nguyên văn**
 * (`paths.includes('.github/workflows/backend-tests.yml')`). Khi bộ lọc được mở
 * rộng thành `.github/workflows/**` — rộng hơn hẳn, phủ đúng file đó và nhiều
 * hơn — cả hai cùng đỏ, dù không rào nào bị nới. So nguyên văn trả lời sai câu
 * hỏi: thứ cần biết là *"bộ lọc có cho lượt chạy này thấy file đó không"*, chứ
 * không phải *"chuỗi này có xuất hiện không"*.
 *
 * Dùng chung một phép so để câu hỏi ấy chỉ có MỘT câu trả lời trong cả repo.
 */

/** Một entry trong `paths:` có phủ được `subject` không (so theo THƯ MỤC). */
export function covers(entry, subject) {
  if (entry === subject) return true;

  // 'a/**' phủ 'a/b/**' và 'a/x.yml'. Cắt đúng hai ký tự `**`, giữ lại dấu `/`
  // — nếu cắt cả `/` thì 'a/**' sẽ phủ nhầm 'ab/x'.
  const dir = entry.endsWith("/**") ? entry.slice(0, -2) : null;

  return dir !== null && subject.startsWith(dir);
}

/** `paths:` có phủ được `subject` không. */
export function pathsCover(paths, subject) {
  return paths.some((entry) => covers(entry, subject));
}
