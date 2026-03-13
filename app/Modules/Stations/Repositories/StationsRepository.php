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
                is_active
            FROM stations
            WHERE is_active = 1
            ORDER BY CAST(station_code AS UNSIGNED), station_code
        ";

        $st = $this->db->query($sql);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
