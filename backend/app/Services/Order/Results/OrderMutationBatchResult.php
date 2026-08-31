<?php

namespace App\Services\Order\Results;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationResult;
use JsonSerializable;

/**
 * Kết quả của một lô mutation áp trong MỘT transaction (#1666).
 *
 * Có class riêng vì hợp đồng của facade đòi mỗi thao tác trả về một class
 * `final readonly` — `array` trần thì một decorator không đọc nổi cái nó vừa
 * bọc (`DomainMutationContractsTest`).
 *
 * `aggregateId` là đơn hàng: một lô, theo định nghĩa của command dựng ra nó,
 * chỉ chạm đúng một đơn.
 */
final readonly class OrderMutationBatchResult implements JsonSerializable
{
    public string $aggregateId;

    /** @var list<MutationResult> */
    public array $results;

    /** @param  list<MutationResult>  $results */
    public function __construct(string $aggregateId, array $results)
    {
        $this->aggregateId = MutationCommand::uuid($aggregateId, 'aggregateId');
        $this->results = array_values($results);
    }

    /** Số thao tác đã áp — 0 là không thể, command đã từ chối lô rỗng. */
    public function count(): int
    {
        return count($this->results);
    }

    /** @return array{aggregate_id: string, applied: int, results: list<MutationResult>} */
    public function jsonSerialize(): array
    {
        return [
            'aggregate_id' => $this->aggregateId,
            'applied' => $this->count(),
            'results' => $this->results,
        ];
    }
}
