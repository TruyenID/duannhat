<?php

namespace App\Services\Customer\ValueObjects;

use App\Services\DomainMutation\MutationCommand;

final readonly class CustomerMergePlan
{
    /**
     * Thứ tự KHOÁ, sắp theo id — cố ý không phải thứ tự nguồn/đích.
     *
     * Hai lượt gộp chéo nhau (A→B và B→A) khoá hai hàng theo hai thứ tự ngược
     * nhau là công thức tạo deadlock. Sắp trước khi khoá thì mọi lượt gộp cùng
     * cặp luôn khoá theo một chiều.
     *
     * @var list<string>
     */
    public array $lockOrder;

    /**
     * #1550 — hai id NGUỒN/ĐÍCH, khai tường minh.
     *
     * `VerifiedCustomerMutation::issue()` đọc `$mergePlan->sourceCustomerId` để
     * đối chiếu với lệnh, nhưng class này chưa từng khai hai thuộc tính đó — nó
     * chỉ nhận chúng làm tham số rồi sắp vào `lockOrder`. Đọc một thuộc tính
     * không tồn tại trên `readonly class` là `ErrorException`, tức lượt gộp CHẾT
     * ngay ở khâu xác minh.
     *
     * Chưa ai thấy vì toàn bộ namespace `App\Services\Customer` chưa từng có
     * class thực thi nào — #1550 là lượt chạy đầu tiên.
     *
     * `lockOrder` KHÔNG thay được: nó đã sắp, nên không nói được ai là nguồn.
     */
    public string $sourceCustomerId;

    public string $targetCustomerId;

    public function __construct(string $sourceCustomerId, string $targetCustomerId, array $references)
    {
        $this->sourceCustomerId = MutationCommand::uuid($sourceCustomerId, 'sourceCustomerId');
        $this->targetCustomerId = MutationCommand::uuid($targetCustomerId, 'targetCustomerId');
        $ids = [$this->sourceCustomerId, $this->targetCustomerId];
        sort($ids, SORT_STRING);
        $this->lockOrder = $ids;
        foreach ($references as $reference) {
            if (! is_string($reference) || trim($reference) === '') {
                throw new \InvalidArgumentException('Merge references must be named.');
            }
        }
        $this->references = array_values(array_unique($references));
    }

    /** @var list<string> */
    public array $references;
}
