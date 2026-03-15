# Picking Data Model

## Status
AKTUALNE — zgodne z bieżącym działaniem importu, snapshotu i agregacji pickingu oraz przekazania do packingu.

## Model docelowy

System działa w modelu:
- nagłówek zamówienia pochodzi z source i jest zapisywany do `pak_orders`
- pozycje do pickingu pochodzą z danych zapisanych w `pak_order_items`

Następnie przy tworzeniu batcha pozycje są kopiowane do:
- `picking_order_items`

A z nich budowane są agregaty w:
- `picking_batch_items`

Po wejściu do packingu pozycje są kopiowane do:
- `packing_session_items`

Flow:
- source -> `pak_orders`
- source/subiekt -> `pak_order_items`
- `pak_order_items` -> `picking_order_items`
- `picking_order_items` -> `picking_batch_items`
- `pak_order_items` -> `packing_session_items`

## Source of truth produktu

W pickingu source of truth produktu to:
- `subiekt_tow_id`

Agregacja działa po:
- `subiekt_tow_id`
- `uom`

Dla kompatybilności:
- `product_code = string(subiekt_tow_id)` dla rekordów poprawnie zmapowanych
- dla fallbacków legacy możliwe jest `legacy:{pak_order_item_id}`

## Znaczenie pól w `pak_order_items`

### Identyfikacja
- `item_id`
- `order_code`
- `offer_id`

### Dane produktowe
- `subiekt_tow_id`
- `subiekt_symbol`
- `name`
- `subiekt_desc`
- `sku`

### Ilości
- `quantity`
- `picked_qty`
- `packed_qty`

## Snapshot w `picking_order_items`

Istotne pola:
- `batch_order_id`
- `pak_order_item_id`
- `subiekt_tow_id`
- `subiekt_symbol`
- `subiekt_desc`
- `source_name`
- `product_code`
- `product_name`
- `uom`
- `is_unmapped`
- `expected_qty`
- `picked_qty`
- `status`
- `missing_reason`

## Snapshot w `picking_batch_items`

Istotne pola:
- `batch_id`
- `subiekt_tow_id`
- `subiekt_symbol`
- `subiekt_desc`
- `source_name`
- `product_code`
- `product_name`
- `uom`
- `is_unmapped`
- `total_expected_qty`
- `total_picked_qty`
- `total_missing_qty`
- `remaining_qty`
- `status`
- `qty_breakdown_json`
- `qty_breakdown_label`
- `order_breakdown_json`

## Snapshot w `packing_session_items`

Istotne pola:
- `packing_session_id`
- `pak_order_item_id`
- `offer_id`
- `subiekt_tow_id`
- `subiekt_symbol`
- `subiekt_desc`
- `source_name`
- `product_code`
- `product_name`
- `uom`
- `is_unmapped`
- `expected_qty`
- `packed_qty`

## Semantyka nazw

W aktualnym modelu:
- `source_name` = nazwa źródłowa z `pak_order_items.name`
- `subiekt_desc` = opis z Subiekta
- `subiekt_symbol` = symbol z Subiekta
- `product_name` = finalna nazwa do wyświetlenia
- `product_code` = identyfikator kompatybilności

## Fallback legacy

Jeżeli `subiekt_tow_id` nie jest dostępne:
- rekord może zostać oznaczony jako `is_unmapped = true`
- wtedy `product_code` może mieć techniczny klucz fallback
- taki rekord nie powinien być błędnie scalany z innymi produktami

## Sposób liczenia agregatu pickingu

Agregat produktu jest liczony po:
- `subiekt_tow_id`
- `uom`

Dla pozycji zmapowanych:
- wszystkie itemy z tym samym `subiekt_tow_id + uom` wchodzą do jednego agregatu

## Packing i `offer_id`

Packing snapshot zapisuje także:
- `offer_id`

GUI packingu może grupować pozycje zamówienia po `offer_id`, żeby logicznie zebrać warianty pochodzące z tej samej oferty.

Uwaga:
- `offer_id` nie jest głównym kluczem magazynowym
- `offer_id` służy w packingu do wygodnego grupowania widoku
- agregacja pickingu nadal nie działa po `offer_id`

## Wniosek dla GUI

### GUI pickingu
Powinno:
- renderować główną listę z `products`
- używać `orders` do operacji item-level
- nie liczyć własnej agregacji po orderach

### GUI packingu
Powinno:
- renderować pozycje z `packing_session_items`
- pokazywać `product_name`, `subiekt_symbol`, `subiekt_desc`, `source_name`
- opcjonalnie grupować po `offer_id`

## Ważne doprecyzowanie

Picking i packing używają różnych snapshotów:
- picking pracuje na snapshotach batcha
- packing pracuje na snapshotach sesji packingu

Zmiany modelu danych widoczne są od momentu utworzenia nowego snapshotu.
