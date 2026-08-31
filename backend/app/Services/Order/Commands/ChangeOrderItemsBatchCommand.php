<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/**
 * Nhiều lần đổi dòng, áp như MỘT hành động được-ăn-cả-ngã-về-không (#1666).
 *
 * Command-của-command, cố ý. Bản đầu của lô này nhận thẳng `array $commands`
 * và `DomainMutationContractsTest` bắt được ngay: hợp đồng của facade là **một
 * command có kiểu vào, một kết quả `final readonly` ra**, và một `array` trần
 * phá đúng thứ hợp đồng ấy tồn tại để bảo vệ — một decorator hay plugin không
 * còn biết mình đang bọc cái gì.
 *
 * `context` của lô là context của lệnh đầu: mọi dòng trong một lô, theo cách nó
 * được dựng, cùng một đơn và cùng một người thao tác.
 */
final readonly class ChangeOrderItemsBatchCommand extends MutationCommand
{
    /** @var list<ChangeOrderItemsCommand> */
    public array $commands;

    /**
     * @param  list<ChangeOrderItemsCommand>  $commands  ít nhất một; lô rỗng là
     *                                                   lỗi lập trình chứ không phải "không có gì để làm"
     */
    public function __construct(MutationContext $context, array $commands)
    {
        parent::__construct($context);

        $commands = array_values($commands);

        if ($commands === []) {
            throw new \InvalidArgumentException('A batch needs at least one command.');
        }

        foreach ($commands as $command) {
            if (! $command instanceof ChangeOrderItemsCommand) {
                throw new \InvalidArgumentException('Every entry must be a ChangeOrderItemsCommand.');
            }

            // Một lô vắt qua hai đơn thì transaction quanh nó không còn nghĩa gì
            // — nó chỉ khiến hai đơn cùng hỏng khi một dòng sai.
            if ($command->orderId !== $commands[0]->orderId) {
                throw new \InvalidArgumentException('Every command in a batch must target the same order.');
            }
        }

        $this->commands = $commands;
    }
}
