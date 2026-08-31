<?php

namespace App\Services\Payment\Settlement\Exceptions;

use RuntimeException;

/**
 * #2893 — di trú quy thuộc DỪNG LẠI thay vì đoán.
 *
 * Mỗi lượt ném ở đây là một câu hỏi mà chỉ người vận hành trả lời được: đích
 * đến không xác định được duy nhất, hoặc định danh PSP thật chưa khai. Chuyển
 * chủ sở hữu của 968 bản ghi tiền dựa trên một phỏng đoán còn tệ hơn để nguyên
 * — bản vá sai sẽ trông y hệt bản vá đúng cho tới lúc kế toán đối chiếu.
 */
final class SettlementAttributionRefused extends RuntimeException {}
