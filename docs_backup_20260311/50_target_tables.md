# Tabele — stan aktualny

## Status
POTWIERDZONE — wszystkie tabele istnieją w bazie (zweryfikowane 2026-03-11)

## Tabele importu (bazowe, nie ruszać)
- `pak_orders` — PK: order_code
- `pak_order_items` — PK: item_id, FK order_code -> pak_orders.order_code

## Tabele auth / operators / stations
- `users` ✅
- `user_roles` ✅
- `stations` ✅
- `user_station_sessions` ✅

## Tabele shipping
- `shipping_rule_sets` ✅
- `shipping_rules` ✅
- `shipping_providers` ✅ (zasilona danymi dla dpd_contract, gls, baselinker, inpost_shipx, allegro)

## Tabele picking
- `picking_batches` ✅ — kolumna grupująca to `carrier_key` (nie `menu_group`)
- `picking_batch_orders` ✅
- `picking_batch_items` ✅
- `picking_order_items` ✅
- `picking_events` ✅

## Tabele packing
- `packing_sessions` ✅
- `packing_session_items` ✅
- `packages` ✅
- `package_labels` ✅
- `packing_events` ✅

## Tabele audit
- `order_events` ✅
- `api_request_logs` ✅
- `workflow_errors` ✅

## Ważne uwagi
- Kolumna w `picking_batches` to `carrier_key`, nie `menu_group` — nie mylić
- Wszystkie tabele workflow łączą się z zamówieniami przez `order_code`
- Pozycje łączą się przez `pak_order_items.item_id`
- Istniejące pola `picked_qty` / `packed_qty` w `pak_order_items` zachowane dla kompatybilności
