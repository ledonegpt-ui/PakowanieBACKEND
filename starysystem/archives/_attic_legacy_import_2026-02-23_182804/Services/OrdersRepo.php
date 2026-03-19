<?php
declare(strict_types=1);

final class OrdersRepo
{
    /** @var \PDO */
    private $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function getOrder(string $orderCode): ?array
    {
        $st = $this->db->prepare("SELECT * FROM pak_orders WHERE order_code = :c LIMIT 1");
        $st->execute([':c' => $orderCode]);
        $order = $st->fetch();
        if (!$order) return null;

        $it = $this->db->prepare("
            SELECT *
            FROM pak_order_items
            WHERE order_code = :c
            ORDER BY item_id ASC
        ");
        $it->execute([':c' => $orderCode]);
        $items = $it->fetchAll();

        $order['items'] = $items;
        return $order;
    }
}
