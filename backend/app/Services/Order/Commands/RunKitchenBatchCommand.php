<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/**
 * Một lượt bump-all của bếp: bump trạng thái từng món KÈM các dấu thời gian
 * ghi-lần-đầu-thắng đi với chúng, áp như MỘT hành động (#1666).
 *
 * Trộn hai loại lệnh là cố ý — nửa lượt được áp là một ticket có trạng thái và
 * dấu thời gian mâu thuẫn nhau. Bên gọi quyết định lệnh nào thuộc lượt này, vì
 * nó là phía cầm các món đã nạp và thấy được dấu nào chưa đặt.
 *
 * Xem {@see ChangeOrderItemsBatchCommand} về lý do đây là command-của-command
 * chứ không phải một `array` trần.
 */
final readonly class RunKitchenBatchCommand extends MutationCommand
{
    /** @var list<BumpKitchenOrderItemStatusCommand|StampKitchenItemTimestampCommand> */
    public array $commands;

    /**
     * @param  list<BumpKitchenOrderItemStatusCommand|StampKitchenItemTimestampCommand>  $commands
     */
    public function __construct(MutationContext $context, array $commands)
    {
        parent::__construct($context);

        $commands = array_values($commands);

        if ($commands === []) {
            throw new \InvalidArgumentException('A kitchen batch needs at least one command.');
        }

        foreach ($commands as $command) {
            if (! $command instanceof BumpKitchenOrderItemStatusCommand
                && ! $command instanceof StampKitchenItemTimestampCommand) {
                throw new \InvalidArgumentException(
                    'A kitchen batch takes bump-status and stamp-timestamp commands only.',
                );
            }

            if ($command->orderId !== $commands[0]->orderId) {
                throw new \InvalidArgumentException('Every command in a batch must target the same order.');
            }
        }

        $this->commands = $commands;
    }
}
