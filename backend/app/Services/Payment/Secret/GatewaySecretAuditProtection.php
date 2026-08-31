<?php

namespace App\Services\Payment\Secret;

use App\Services\Payment\Secret\Exceptions\InvalidGatewaySecretConfiguration;
use Illuminate\Database\ConnectionInterface;

final readonly class GatewaySecretAuditProtection
{
    private const UPDATE_TRIGGER = 'payment_gateway_secret_audits_no_update';

    private const DELETE_TRIGGER = 'payment_gateway_secret_audits_no_delete';

    public function __construct(private ConnectionInterface $database) {}

    public function install(): void
    {
        $this->assertSupportedDriver();

        if (! $this->database->getSchemaBuilder()->hasTable('payment_gateway_secret_audits')) {
            throw new InvalidGatewaySecretConfiguration('audit_table_missing');
        }

        if ($this->database->getDriverName() === 'sqlite') {
            if (! $this->triggerExists(self::UPDATE_TRIGGER)) {
                $this->database->statement('CREATE TRIGGER '.self::UPDATE_TRIGGER." BEFORE UPDATE ON payment_gateway_secret_audits BEGIN SELECT RAISE(ABORT, 'payment gateway secret audit is append-only'); END");
            }
            if (! $this->triggerExists(self::DELETE_TRIGGER)) {
                $this->database->statement('CREATE TRIGGER '.self::DELETE_TRIGGER." BEFORE DELETE ON payment_gateway_secret_audits BEGIN SELECT RAISE(ABORT, 'payment gateway secret audit is append-only'); END");
            }
        } else {
            if (! $this->triggerExists(self::UPDATE_TRIGGER)) {
                $this->database->unprepared('CREATE TRIGGER '.self::UPDATE_TRIGGER." BEFORE UPDATE ON payment_gateway_secret_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment gateway secret audit is append-only'");
            }
            if (! $this->triggerExists(self::DELETE_TRIGGER)) {
                $this->database->unprepared('CREATE TRIGGER '.self::DELETE_TRIGGER." BEFORE DELETE ON payment_gateway_secret_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment gateway secret audit is append-only'");
            }
        }

        $this->assertInstalled();
    }

    public function assertInstalled(): void
    {
        $this->assertSupportedDriver();

        if ($this->installedTriggerCount() !== 2) {
            throw new InvalidGatewaySecretConfiguration('audit_protection_missing');
        }
    }

    private function installedTriggerCount(): int
    {
        if ($this->database->getDriverName() === 'sqlite') {
            return (int) $this->database->table('sqlite_master')
                ->where('type', 'trigger')
                ->whereIn('name', [self::UPDATE_TRIGGER, self::DELETE_TRIGGER])
                ->count();
        }

        $result = $this->database->selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ? AND TRIGGER_NAME IN (?, ?)',
            ['payment_gateway_secret_audits', self::UPDATE_TRIGGER, self::DELETE_TRIGGER],
        );

        return (int) ($result->aggregate ?? 0);
    }

    private function triggerExists(string $name): bool
    {
        if ($this->database->getDriverName() === 'sqlite') {
            return $this->database->table('sqlite_master')
                ->where('type', 'trigger')
                ->where('name', $name)
                ->exists();
        }

        return $this->database->selectOne(
            'SELECT 1 AS present FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ? AND TRIGGER_NAME = ? LIMIT 1',
            ['payment_gateway_secret_audits', $name],
        ) !== null;
    }

    private function assertSupportedDriver(): void
    {
        if (! in_array($this->database->getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new InvalidGatewaySecretConfiguration('audit_driver_unsupported');
        }
    }
}
