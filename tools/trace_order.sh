#!/bin/bash

if [ -z "$1" ]; then
    echo "Usage: ./tools/trace_order.sh ORDER_CODE"
    exit 1
fi

ORDER_CODE=$1

# pobierz dane DB z PHP
DB_INFO=$(php -r '
$cfg = require "app/bootstrap.php";
echo $cfg["db"]["database"]." ".$cfg["db"]["username"]." ".$cfg["db"]["password"]." ".$cfg["db"]["host"];
')

DB_NAME=$(echo $DB_INFO | awk '{print $1}')
DB_USER=$(echo $DB_INFO | awk '{print $2}')
DB_PASS=$(echo $DB_INFO | awk '{print $3}')
DB_HOST=$(echo $DB_INFO | awk '{print $4}')

echo "===================================="
echo " TRACE ORDER: $ORDER_CODE"
echo "===================================="
echo ""

mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" <<SQL

SELECT 'ORDER';
SELECT order_code,status,delivery_method,carrier_code,courier_code,imported_at
FROM pak_orders
WHERE order_code='$ORDER_CODE';

SELECT 'ORDER ITEMS';
SELECT item_id,sku,name,quantity
FROM pak_order_items
WHERE order_code='$ORDER_CODE';

SELECT 'PICKING BATCH';
SELECT pb.id,pb.batch_code,pb.status,pb.user_id,pb.station_id,pb.started_at
FROM picking_batch_orders pbo
JOIN picking_batches pb ON pb.id = pbo.batch_id
WHERE pbo.order_code='$ORDER_CODE';

SELECT 'PICKING ITEMS';
SELECT poi.id,poi.product_code,poi.expected_qty,poi.picked_qty,poi.status
FROM picking_order_items poi
JOIN picking_batch_orders pbo ON pbo.id = poi.batch_order_id
WHERE pbo.order_code='$ORDER_CODE';

SELECT 'PICKING EVENTS';
SELECT event_type,event_message,created_at
FROM picking_events pe
JOIN picking_batch_orders pbo ON pbo.id = pe.batch_order_id
WHERE pbo.order_code='$ORDER_CODE'
ORDER BY created_at;

SELECT 'PACKING';
SELECT *
FROM packing_orders
WHERE order_code='$ORDER_CODE';

SQL

