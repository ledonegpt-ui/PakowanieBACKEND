<?php

if ($argc < 2) {
    echo "Usage: php tools/trace_order.php ORDER_CODE\n";
    exit(1);
}

$orderCode = $argv[1];

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';

$db = Db::mysql($cfg);

function section($title){
    echo "\n====================================\n";
    echo "$title\n";
    echo "====================================\n";
}

function query($db,$sql,$params){
    $st=$db->prepare($sql);
    $st->execute($params);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r){
        print_r($r);
    }
}

section("ORDER");

query($db,"
SELECT order_code,status,delivery_method,carrier_code,courier_code,imported_at
FROM pak_orders
WHERE order_code = ?
",[$orderCode]);

section("ORDER ITEMS");

query($db,"
SELECT item_id,sku,name,quantity
FROM pak_order_items
WHERE order_code = ?
",[$orderCode]);

section("PICKING BATCH");

query($db,"
SELECT pb.*
FROM picking_batch_orders pbo
JOIN picking_batches pb ON pb.id = pbo.batch_id
WHERE pbo.order_code = ?
",[$orderCode]);

section("PICKING ITEMS");

query($db,"
SELECT poi.*
FROM picking_order_items poi
JOIN picking_batch_orders pbo ON pbo.id = poi.batch_order_id
WHERE pbo.order_code = ?
",[$orderCode]);

section("PICKING EVENTS");

query($db,"
SELECT event_type,event_message,created_at
FROM picking_events pe
JOIN picking_batch_orders pbo ON pbo.id = pe.batch_order_id
WHERE pbo.order_code = ?
ORDER BY created_at
",[$orderCode]);

section("PACKING");

query($db,"
SELECT *
FROM packing_orders
WHERE order_code = ?
",[$orderCode]);

echo "\nDone.\n";

