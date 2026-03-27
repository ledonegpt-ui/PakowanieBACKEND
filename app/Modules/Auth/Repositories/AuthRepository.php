<?php
declare(strict_types=1);

final class AuthRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findActiveUserByLoginOrBarcode(string $value): ?array
    {
        $sql = "
            SELECT id, login, display_name, barcode, is_active
            FROM users
            WHERE is_active = 1
              AND (login = :login_value OR barcode = :barcode_value)
            LIMIT 1
        ";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':login_value' => $value,
            ':barcode_value' => $value,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function findActiveStationByCode(string $stationCode): ?array
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
              AND station_code = :station_code
            LIMIT 1
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':station_code' => $stationCode]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function deactivateActiveSessionsForUser(int $userId): void
    {
        $sql = "
            UPDATE user_station_sessions
            SET is_active = 0,
                ended_at = NOW()
            WHERE user_id = :user_id
              AND is_active = 1
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':user_id' => $userId]);
    }

    public function createSession( int $userId, int $stationId, string $token, string $workflowMode, string $packageMode, int $pickingBatchSize ): void {
        $sql = "
            INSERT INTO user_station_sessions (
                user_id, station_id, session_token, workflow_mode, package_mode, picking_batch_size, started_at, last_seen_at, is_active
            ) VALUES (
                :user_id, :station_id, :session_token, :workflow_mode, :package_mode, :picking_batch_size, NOW(), NOW(), 1
            )
        ";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':user_id' => $userId,
            ':station_id' => $stationId,
            ':session_token' => $token,
            ':workflow_mode' => $workflowMode,
            ':package_mode' => $packageMode, ':picking_batch_size' => $pickingBatchSize, ]);
    }

    public function findActiveSessionByToken(string $token): ?array
    {
        $sql = "
            SELECT
                s.id AS session_id,
                s.session_token,
                s.workflow_mode, s.package_mode, s.picking_batch_size, s.started_at,
                s.last_seen_at,
                u.id AS user_id,
                u.login,
                u.display_name,
                u.barcode,
                st.id AS station_id,
                st.station_code,
                st.station_name,
                st.printer_ip,
                st.printer_name,
                st.package_mode_default
            FROM user_station_sessions s
            INNER JOIN users u ON u.id = s.user_id
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

    public function touchSession(string $token): void
    {
        $sql = "
            UPDATE user_station_sessions
            SET last_seen_at = NOW()
            WHERE session_token = :token
              AND is_active = 1
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':token' => $token]);
    }

    public function deactivateSession(string $token): void
    {
        $sql = "
            UPDATE user_station_sessions
            SET is_active = 0,
                ended_at = NOW()
            WHERE session_token = :token
              AND is_active = 1
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':token' => $token]);
    }

    public function rolesForUser(int $userId): array
    {
        $sql = "
            SELECT role_code
            FROM user_roles
            WHERE user_id = :user_id
            ORDER BY role_code
        ";
        $st = $this->db->prepare($sql);
        $st->execute([':user_id' => $userId]);

        $roles = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $roles[] = (string)$row['role_code'];
        }

        return $roles;
    }
}
