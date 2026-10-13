<?php

declare(strict_types=1);

/** ILIAS-Abhängigkeiten für isolierte Zugriffs- und HTTP-Tests. */
class ilObject
{
    public static string $test_type = 'tst';
    public static function _lookupType($id, $by_ref = false): string { return self::$test_type; }
    public static function _lookupObjectId($ref_id): int { return 123; }
}

class ilObjTest
{
    public int $id = 123;
    public bool $online = true;
    public bool $start_reached = true;
    public bool $end_reached = false;
    public bool $processing_enabled = true;
    public bool $reset_processing = true;
    public int $processing_seconds = 600;
    public int $extra_seconds = 0;
    public array $timer_checks = [];

    public function getId(): int { return $this->id; }
    public function startingTimeReached(): bool { return $this->start_reached; }
    public function endingTimeReached(): bool { return $this->end_reached; }
    public function getEnableProcessingTime(): bool { return $this->processing_enabled; }
    public function getResetProcessingTime(): bool { return $this->reset_processing; }

    public function getObjectProperties(): object
    {
        return new class($this->online) {
            public function __construct(private bool $online) {}
            public function getPropertyIsOnline(): self { return $this; }
            public function getIsOnline(): bool { return $this->online; }
        };
    }

    public function isMaxProcessingTimeReached(int $started, int $active_id): bool
    {
        $this->timer_checks[] = [$started, $active_id];
        return $this->processing_enabled && time() > $started + $this->processing_seconds + $this->extra_seconds;
    }
}

class ilObjectFactory
{
    public static ?object $test_object = null;
    public static array $lookups = [];
    public static function getInstanceByRefId(int $ref_id, bool $stop_on_error = true): ?object
    {
        self::$lookups[] = $ref_id;
        return self::$test_object;
    }
}

class ilEtsResourceUser
{
    public int $id = 6;
    public bool $anonymous = false;
    public function getId(): int { return $this->id; }
    public function isAnonymous(): bool { return $this->anonymous; }
    public function getLogin(): string { return 'resource-fixture'; }
}

class ilEtsResourceAccess
{
    public bool $read = true;
    public array $calls = [];
    public function checkAccess(string $permission, string $cmd, int $ref_id): bool
    {
        $this->calls[] = [$permission, $cmd, $ref_id];
        return $this->read;
    }
}

class ilEtsResourceDatabase
{
    public ?array $active;
    public ?string $first_pass_started;
    public bool $fail = false;
    public bool $forbid_resource_query = false;
    public array $queries = [];
    public array $resource_row;

    public function __construct()
    {
        $this->active = ['active_id' => 7, 'tries' => 0, 'last_started_pass' => 0,
            'last_finished_pass' => null, 'submitted' => 0, 'pass_started' => date('Y-m-d H:i:s', time() - 60)];
        $this->first_pass_started = date('Y-m-d H:i:s', time() - 60);
        $this->resource_row = ['stored_name' => '123_' . str_repeat('a', 32) . '.pdf',
            'filename' => 'fixture.pdf', 'mimetype' => 'application/pdf'];
    }

    public function queryF(string $sql, array $types, array $values): array
    {
        $this->queries[] = [$sql, $types, $values];
        if ($this->fail) {
            throw new TypeError('Simulierter Datenbankfehler');
        }
        if (str_contains($sql, 'tst_active')) {
            return ['row' => $values === [123, 6, 0] && ($this->active['submitted'] ?? 1) === 0
                ? $this->active : null];
        }
        if (str_contains($sql, 'tst_times')) {
            return ['row' => ['pass_started' => $this->first_pass_started]];
        }
        if (str_contains($sql, 'examressheet_settings')) {
            if ($this->forbid_resource_query) {
                throw new RuntimeException('Unberechtigter Zugriff auf Hilfsmitteldaten');
            }
            return ['row' => $this->resource_row];
        }
        throw new RuntimeException('Unerwartete Testabfrage');
    }

    public function fetchAssoc(array $result): ?array { return $result['row']; }
}

class ilEtsResourceDic
{
    public ilEtsResourceUser $test_user;
    public ilEtsResourceDatabase $test_db;
    public ilEtsResourceAccess $test_access;

    public function __construct()
    {
        $this->test_user = new ilEtsResourceUser();
        $this->test_db = new ilEtsResourceDatabase();
        $this->test_access = new ilEtsResourceAccess();
    }

    public function user(): ilEtsResourceUser { return $this->test_user; }
    public function database(): ilEtsResourceDatabase { return $this->test_db; }
    public function access(): ilEtsResourceAccess { return $this->test_access; }
}
