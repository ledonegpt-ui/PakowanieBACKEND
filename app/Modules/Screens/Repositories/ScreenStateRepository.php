<?php

declare(strict_types=1);

final class ScreenStateRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findStationByCode(string $stationCode): ?array
    {
        $sql = "
            SELECT
                id,
                station_code,
                station_name
            FROM stations
            WHERE station_code = :station_code
              AND is_active = 1
            LIMIT 1
        ";

        $st = $this->db->prepare($sql);
        $st->execute([
            ':station_code' => $stationCode,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function findCurrentByStationCode(string $stationCode): ?array
    {
        $sql = "
            SELECT
                st.id AS station_id,
                st.station_code,
                st.station_name,
                COALESCE(pss.state, 'idle') AS state,
                pss.payload_json,
                COALESCE(pss.version, 0) AS version,
                pss.updated_at
            FROM stations st
            LEFT JOIN packing_screen_state pss
                ON pss.station_id = st.id
            WHERE st.station_code = :station_code
              AND st.is_active = 1
            LIMIT 1
        ";

        $st = $this->db->prepare($sql);
        $st->execute([
            ':station_code' => $stationCode,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function upsertPackingState(int $stationId, array $packingPayload): void
    {
        $payloadJson = json_encode(
            $packingPayload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($payloadJson === false) {
            throw new RuntimeException('Failed to encode packing payload for screen state');
        }

        $sql = "
            INSERT INTO packing_screen_state (
                station_id,
                state,
                payload_json,
                version,
                updated_at
            ) VALUES (
                :station_id,
                'packing',
                :payload_json,
                1,
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                state = 'packing',
                payload_json = :payload_json_update,
                version = version + 1,
                updated_at = NOW()
        ";

        $st = $this->db->prepare($sql);
        $st->execute([
            ':station_id' => $stationId,
            ':payload_json' => $payloadJson,
            ':payload_json_update' => $payloadJson,
        ]);
    }

    public function upsertIdleState(int $stationId): void
    {
        $sql = "
            INSERT INTO packing_screen_state (
                station_id,
                state,
                payload_json,
                version,
                updated_at
            ) VALUES (
                :station_id,
                'idle',
                NULL,
                1,
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                state = 'idle',
                payload_json = NULL,
                version = version + 1,
                updated_at = NOW()
        ";

        $st = $this->db->prepare($sql);
        $st->execute([
            ':station_id' => $stationId,
        ]);
    }

    public function getVersionSnapshotByStationCode(string $stationCode): ?array
    {
        $sql = "
            SELECT
                st.id AS station_id,
                st.station_code,
                COALESCE(pss.state, 'idle') AS state,
                COALESCE(pss.version, 0) AS version,
                pss.updated_at
            FROM stations st
            LEFT JOIN packing_screen_state pss
                ON pss.station_id = st.id
            WHERE st.station_code = :station_code
              AND st.is_active = 1
            LIMIT 1
        ";

        $st = $this->db->prepare($sql);
        $st->execute([
            ':station_code' => $stationCode,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}