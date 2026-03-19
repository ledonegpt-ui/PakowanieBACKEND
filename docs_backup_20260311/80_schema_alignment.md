# Wyrównanie schematu bazy — stan potwierdzony

## Status
POTWIERDZONE — zweryfikowane 2026-03-11

## Potwierdzone fakty
- pak_orders PK = order_code (varchar 32)
- pak_order_items PK = item_id (bigint unsigned auto_increment)
- pak_order_items.order_code -> pak_orders.order_code
- MySQL 5.7.42
- Wszystkie nowe tabele workflow ISTNIEJĄ (migracje wykonane)

## Struktura pak_orders — kluczowe kolumny dla workflow
- `order_code` — PK, główny identyfikator w całym nowym systemie
- `delivery_method` — surowy string z importu, wchodzi do resolvera
- `carrier_code` — dodatkowy kod kuriera (często pusty)
- `courier_code` — kod kuriera z importu (często pusty)
- `status` — tinyint, wartość 10 = zamówienie czeka na picking
- `pack_started_at`, `pack_ended_at` — timestampy pakowania
- `packer`, `station` — kto i gdzie pakował
- `label_source` — skąd etykieta
- `nr_nadania` — tracking number (zapisywany przez packing/finish)
- `tracking_number` — kolumna z importu (może być wypełniona z zewnątrz)

## Uwaga o kolumnie picking_batches.carrier_key
Kolumna grupująca batche to `carrier_key`, nie `menu_group`.
Dokumentacja wcześniej błędnie używała `menu_group` jako nazwy kolumny.
W kodzie i bazie: `carrier_key`.

## Decyzje architektoniczne
- Nowe tabele workflow łączą się z zamówieniami przez `order_code`
- Pozycje łączą się przez `pak_order_items.item_id`
- Stary schemat importu pozostaje nienaruszony
- `picked_qty` / `packed_qty` w `pak_order_items` zachowane dla kompatybilności
