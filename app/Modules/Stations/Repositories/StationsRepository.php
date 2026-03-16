<?php
declare(strict_types=1);

final class StationsRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function allActive(): array
    {
        $sql = "
            SELECT
                id,
                station_code,
                station_name,
                printer_ip,
                printer_name,
                package_mode_default,
                is_active
            FROM stations
            WHERE is_active = 1
            ORDER BY CAST(station_code AS UNSIGNED), station_code
        ";

        $st = $this->db->query($sql);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findActiveSessionByToken(string $token): ?array
    {
        $sql = "
            SELECT
                s.id AS session_id,
                s.station_id,
                s.package_mode,
                st.station_code,
                st.station_name,
                st.package_mode_default
            FROM user_station_sessions s
            INNER JOIN stations st ON st.id = s.station_id
            WHERE s.session_token = :token
              AND s.is_active = 1
            LIMIT 1
        ";

        $st = $this->db->prepare($sql);
        $st->execute([':token' => $token]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function updateSessionPackageMode(int $sessionId, string $packageMode): void
    {
        $sql = "
            UPDATE user_station_sessions
            SET package_mode = :package_mode,
                last_seen_at = NOW()
            WHERE id = :session_id
              AND is_active = 1
        ";

        $st = $this->db->prepare($sql);
        $st->execute([
            ':package_mode' => $packageMode,
            ':session_id' => $sessionId,
        ]);
    }
}
