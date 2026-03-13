<?php
declare(strict_types=1);

final class CarriersRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function openOrdersForCarrierMenu(): array
    {
        $sql = "
            SELECT
                order_code,
                delivery_method,
                carrier_code,
                courier_code,
                imported_at,
                status
            FROM pak_orders
            WHERE status = 10
            ORDER BY imported_at ASC, order_code ASC
        ";

        $st = $this->db->query($sql);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
