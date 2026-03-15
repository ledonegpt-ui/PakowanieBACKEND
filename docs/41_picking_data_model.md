# Picking Data Model

## Status
AKTUALNE — zgodne z bieżącym działaniem importu, snapshotu i agregacji pickingu.

## Model docelowy

System działa w modelu:
- nagłówek zamówienia pochodzi z source i jest zapisywany do `pak_orders`
- pozycje do pickingu pochodzą z Subiekta i są zapisywane do `pak_order_items`

Następnie przy tworzeniu batcha pozycje są kopiowane do:
- `picking_order_items`

A z nich budowane są agregaty w:
- `picking_batch_items`

Flow:
- source -> `pak_orders`
- Subiekt -> `pak_order_items`
- `pak_order_items` -> `picking_order_items`
- `picking_order_items` -> `picking_batch_items`

## Źródło pozycji do pickingu

Pozycje używane przez picking pochodzą z Subiekta, z pozycji dokumentu zapisanych lokalnie w `pak_order_items`.

## Znaczenie `offer_id`

Pole:
- `pak_order_items.offer_id`

Semantyka:
- powiązanie z ofertą marketplace / syncId

To pole nie jest głównym kluczem agregacji w pickingu.
Nie wolno budować agregacji magazynowej po `offer_id`.

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

### Identyfikacja pozycji
- `item_id` — techniczny PK
- `order_code` — identyfikator zamówienia
- `line_key` — identyfikator linii

### Powiązanie z ofertą
- `offer_id` — identyfikator oferty marketplace

### Dane subiektowe
- `subiekt_tow_id`
- `subiekt_symbol`
- `name`
- `subiekt_desc`

### Ilości
- `quantity`
- `picked_qty`
- `packed_qty`

## Snapshot w `picking_order_items`

Tabela `picking_order_items` przechowuje item-level snapshot używany do operacji.

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

## Semantyka nazw produktu

W aktualnym modelu:
- `source_name` = nazwa źródłowa z `pak_order_items.name`
- `subiekt_desc` = opis z Subiekta
- `subiekt_symbol` = symbol z Subiekta
- `product_name` = finalna nazwa do wyświetlenia w pickingu

Rekomendowany sposób renderu w GUI:
- główna nazwa: `product_name`
- pomocniczo: `subiekt_symbol`
- pomocniczo: `subiekt_desc`

## Fallback legacy

Jeżeli `subiekt_tow_id` nie jest dostępne:
- rekord może zostać oznaczony jako `is_unmapped = true`
- wtedy `product_code` może mieć techniczny klucz fallback
- taki rekord nie powinien być błędnie scalany z innymi produktami

## Agregat w `picking_batch_items`

Tabela `picking_batch_items` przechowuje widok produktowy dla batcha.

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

## Sposób liczenia agregatu

Agregat produktu jest liczony po:
- `subiekt_tow_id`
- `uom`

Dla pozycji zmapowanych:
- wszystkie itemy z tym samym `subiekt_tow_id + uom` wchodzą do jednego agregatu

Dla rekordów fallback/legacy:
- nie wolno scalać ich przypadkowo po pustym identyfikatorze

## Rozliczenie ilości

- `total_expected_qty` = suma oczekiwana z itemów
- `total_picked_qty` = suma zebrana
- `total_missing_qty` = suma pozycji oznaczonych jako missing
- `remaining_qty = total_expected_qty - total_picked_qty - total_missing_qty`

## Breakdown

Agregat przechowuje gotowe dane do GUI:
- `qty_breakdown` — lista ilości źródłowych, np. `[1,2,6]`
- `qty_breakdown_label` — string do prostego renderu, np. `1+2+6`
- `order_breakdown` — rozbicie per order

## Wniosek dla GUI pickingu

GUI pickingu powinno:
- renderować główną listę z `products`
- używać `orders` do operacji item-level
- nie liczyć własnej agregacji po orderach
- nie agregować po `offer_id`

Jeżeli trzeba powiązać pozycję z marketplace lub zdjęciami, należy używać:
- `offer_id`
