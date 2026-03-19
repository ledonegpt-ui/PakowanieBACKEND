<?php
declare(strict_types=1);

final class ImportState
{
    /** @var \PDO */
    private $mysql;

    public function __construct(\PDO $mysql)
    {
        $this->mysql = $mysql;
    }

    public function getInt(string $provider, string $key, int $default = 0): int
    {
        $st = $this->mysql->prepare("SELECT state_val FROM import_state WHERE provider=:p AND state_key=:k");
        $st->execute([':p' => $provider, ':k' => $key]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null) return $default;
        $v = (string)$v;
        return ctype_digit($v) ? (int)$v : $default;
    }

    public function set(string $provider, string $key, string $val): void
    {
        $st = $this->mysql->prepare("
            INSERT INTO import_state(provider, state_key, state_val) VALUES(:p,:k,:v)
            ON DUPLICATE KEY UPDATE state_val=VALUES(state_val), updated_at=NOW()
        ");
        $st->execute([':p' => $provider, ':k' => $key, ':v' => $val]);
    }
}