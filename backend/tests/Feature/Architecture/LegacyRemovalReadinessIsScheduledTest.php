<?php

use Illuminate\Console\Scheduling\Schedule;

/**
 * #2410 — dụng cụ đo readiness phải CHẠY THEO LỊCH, không chờ ai đó nhớ.
 *
 * `LegacyRemovalReadiness` được dựng chính để trả lời "đã gỡ được shim payment
 * legacy chưa?" bằng phép đo, và docblock của nó nói thẳng ý định:
 *
 * > the day a condition finally flips, a scheduled run says so instead of the
 * > debt sitting for another year.
 *
 * Đo 2026-08-14: nó **chưa bao giờ** được đăng ký — không ở `routes/console.php`,
 * không ở workflow nào. Tức lời hứa trên chưa từng có ai thực hiện, và khoản nợ
 * payment đã ngồi đó đúng như câu docblock cảnh báo.
 *
 * Một dụng cụ đo mà không ai chạy TỆ HƠN không có: nó trả lời "có" cho câu hỏi
 * *"chỗ này đã được canh chưa"* — cùng lớp lỗi với rào kêu oan bị tắt, và với
 * `SystemNotificationRuleSeeder` nằm trong danh sách không ai đọc (#2777).
 *
 * Bài này hỏi LỊCH THẬT (không đọc source), nên nó vẫn cắn nếu ai dời mục sang
 * file khác — thứ duy nhất nó ghim là "câu hỏi đó vẫn được hỏi định kỳ".
 */
it('payments:legacy-removal-readiness được đăng ký vào lịch (#2410)', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($e) => (string) $e->command)
        ->values();

    // Mẫu số TRƯỚC: nếu lịch rỗng thì phần khẳng định dưới xanh vô nghĩa. Số 0
    // ở đây là một khẳng định, không phải mặc định.
    expect($commands->count())->toBeGreaterThan(5,
        'lịch chỉ có '.$commands->count().' mục — bố cục đổi? Bài dưới đang không canh gì.');

    $hit = $commands->first(fn ($c) => str_contains($c, 'payments:legacy-removal-readiness'));

    expect($hit)->not->toBeNull(
        'không mục nào chạy `payments:legacy-removal-readiness`. Dụng cụ đo tồn tại '
        .'nhưng không ai chạy — xem docblock của LegacyRemovalReadiness.'
    );

    // `--strict` là phần mang tín hiệu: không có nó, lượt chạy luôn thoát 0 và
    // báo động không bao giờ tới. Cổng ĐẠT mà mã legacy còn nguyên chính là
    // trạng thái đáng hành động ("giờ xoá được rồi, và không ai biết").
    expect($hit)->toContain('--strict');
});
