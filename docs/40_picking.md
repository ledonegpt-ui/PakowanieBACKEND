# Picking

## Status
AKTUALNE — zgodne z bieżącym stanem systemu po wdrożeniu:
- agregacji po `subiekt_tow_id`
- rozszerzonego modelu produktu
- trybów doboru batcha
- przełączania `selection_mode` w locie
- przekazania batcha do packingu po zamknięciu

## Architektura modułu

Picking jest zorganizowany w układzie:
- `PickingBatchesController`
- `PickingOrdersController`
- `PickingBatchService`
- `PickingBatchRepository`

Flow:
- API -> Controller -> Service -> Repository -> MySQL

## Tabele używane przez picking

- `picking_batches`
- `picking_batch_orders`
- `picking_order_items`
- `picking_batch_items`
- `picking_events`
- `pak_orders`
- `pak_order_items`

## Statusy

### `picking_batches.status`
- `open`
- `completed`
- `abandoned`

### `picking_batch_orders.status`
- `assigned`
- `picked`
- `dropped`

### `picking_order_items.status`
- `pending`
- `picked`
- `missing`

### `picking_batch_items.status`
- `pending`
- `partial`
- `picked`

## Główna zasada modelu produktowego

W pickingu source of truth produktu to:
- `subiekt_tow_id`

Dla kompatybilności:
- `product_code` zostaje w API,
- ale dla poprawnie zmapowanych pozycji jest to string z `subiekt_tow_id`,
- dla fallbacków legacy może wystąpić techniczny klucz typu `legacy:{pak_order_item_id}`.

Agregacja produktów w batchu działa po:
- `subiekt_tow_id`
- `uom`

## Snapshot item-level

Po otwarciu batcha i przy refill pozycje są kopiowane z `pak_order_items` do `picking_order_items`.

Snapshot przechowuje między innymi:
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

## Domyślny tryb doboru

Domyślny `selection_mode`:
- `cutoff_cluster`

## Tryby doboru batcha

### `cutoff`
Klasyczny FIFO:
- system bierze najstarsze dostępne zamówienia pasujące do `carrier_key`

### `cutoff_cluster`
Tryb domyślny:
- pierwszy order nadal pochodzi z cutoff/FIFO
- kolejne ordery są dobierane z preferencją wspólnych produktów
- dopasowanie działa po `subiekt_tow_id + uom`
- jeżeli nie da się dobrać wystarczająco podobnych orderów, batch jest dopełniany zwykłym cutoffem

### `emergency_single`
Tryb awaryjny:
- batch bierze tylko jedno zamówienie
- kolejność jest oparta o `courier_priority DESC`, a potem FIFO

## Refill

Refill działa na podstawie:
- `target_orders_count`
- liczby aktywnych zamówień w batchu
- bieżącego `selection_mode` zapisanego w batchu

Aktywne zamówienia to wszystkie poza `dropped`.

Refill:
- liczy ile zamówień brakuje do targetu
- wyklucza zamówienia już użyte w tym samym batchu
- wyklucza zamówienia aktywne w innych batchach
- dobiera nowe rekordy zgodnie z `selection_mode`
- zapisuje je do `picking_batch_orders`
- kopiuje ich pozycje do `picking_order_items`
- przebudowuje `picking_batch_items`

## Zmiana trybu w locie

Dostępny jest endpoint:
- `POST /api/v1/picking/batches/{batchId}/selection-mode`

Zmiana:
- zapisuje nowy `selection_mode` do batcha,
- nie przebudowuje aktualnego składu batcha,
- wpływa na kolejne refill.

## Agregaty produktów

`GET /picking/batches/{batchId}/products` zwraca dane z `picking_batch_items`.

To jest widok zagregowany dla całego batcha:
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
- `qty_breakdown`
- `qty_breakdown_label`
- `order_breakdown`

## Semantyka akcji operatora

### `picked`
- działa item-level
- ustawia pozycję jako zebraną
- przebudowuje agregaty
- jeżeli wszystkie pozycje ordera są rozliczone, order może przejść do `picked`

### `missing`
- działa item-level
- zostawia pozycję świadomie oznaczoną jako brak
- nie dropuje automatycznie zamówienia
- przebudowuje agregaty

### `drop`
- działa order-level
- usuwa całe zamówienie z batcha
- przebudowuje agregaty
- GUI może po tym wykonać refill

## Semantyka przycisków GUI

### `X`
- dropuje całe zamówienie z bieżącego batcha

### `Brak`
- oznacza konkretną pozycję jako `missing`
- pozycja zostaje świadomie rozliczona jako brak

### `Zakończono -> packing`
- GUI zamyka batch
- następnie przechodzi do packingu

## Zamknięcie batcha

Batch można zamknąć tylko wtedy, gdy:
- nie ma już orderów w statusie `assigned`

Typowy flow:
1. wszystkie pozycje rozliczone jako `picked` lub `missing`
2. batch nie ma już `assigned`
3. operator zamyka batch
4. GUI przechodzi do packingu

## Event log

Tabela:
- `picking_events`

Typy eventów:
- `batch_opened`
- `batch_refilled`
- `selection_mode_changed`
- `item_picked`
- `item_missing`
- `order_dropped`
- `batch_closed`
- `batch_abandoned`

## Ważne doprecyzowanie

`picking_batch_orders` jest aktywnie używana przez system.
To nie jest tabela pomocnicza ani martwy artefakt.

`orders` w API służy do operacji item-level.
`products` w API służy do renderowania głównej listy zbiorczej.
