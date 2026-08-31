import { type NextRequest, NextResponse } from "next/server";

// Đường KHÔNG đòi đăng nhập. Mỗi mục ở đây là một quyết định, nên nêu lý do —
// một danh sách chỉ có đường dẫn sẽ được nới ra bởi người sau mà không ai cân.
//
// `/downloads` (#3088): trang tải bản workstation. Người cài máy ở quán KHÔNG
// có tài khoản admin — bắt họ đăng nhập để tải trình cài là đóng đúng cánh cửa
// mà trang này sinh ra để mở. Chủ dự án chốt 2026-08-17: "làm ở next ko cần auth".
//
// Nó chỉ đọc `manifest.json` công khai (Apache đã phục vụ file cài ở
// `/downloads/workstation/...` không cần auth từ trước), nên công khai trang
// này không lộ thêm gì.
const PUBLIC_PATHS = ["/login", "/auth", "/test", "/downloads"];

export function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;

  // Allow public paths
  if (PUBLIC_PATHS.some((p) => pathname === p || pathname.startsWith(p + "/"))) {
    return NextResponse.next();
  }

  // Check for auth token in cookie
  const token = request.cookies.get("token")?.value;

  if (!token) {
    const loginUrl = new URL("/login", request.url);
    loginUrl.searchParams.set("redirect", pathname);
    return NextResponse.redirect(loginUrl);
  }

  return NextResponse.next();
}

export const config = {
  matcher: [
    // Match all paths except static files, api, and the PUBLIC pages.
    //
    // `/downloads` (#3088) loại ở ĐÂY chứ không chỉ ở `PUBLIC_PATHS`: proxy
    // không nên CHẠY cho một trang công khai, chứ không phải chạy rồi tha. Hai
    // cách cùng ra 200, nhưng cách này không để trang tải phụ thuộc vào việc
    // một nhánh `if` bên trong hàm auth còn đúng hay không.
    //
    // Giữ luôn `/downloads` trong `PUBLIC_PATHS` là CỐ Ý — hai lớp, và lớp
    // trong là thứ bắt được nếu ai đó sửa regex này sai.
    "/((?!_next/static|_next/image|favicon.ico|api|s3/|downloads).*)",
  ],
};
