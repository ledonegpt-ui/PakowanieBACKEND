# Picking

## Status
POTWIERDZONE — zweryfikowane w kodzie, migracjach i bazie

## Architektura modułu
Picking jest zorganizowany w układzie:

- `PickingBatchesController`
- `PickingOrdersController`
- `PickingBatchService`
- `PickingBatchRepository`

Flow requestu:

API → Controller → Service → Repository → MySQL

---

## Endpointy

### Batch lifecycle
- `POST /api/v1/picking/batches/open`
- `GET /api/v1/picking/batches/current`
- `GET /api/v1/picking/batches/{batchId}`
- `POST /api/v1/picking/batches/{batchId}/refill`
- `POST /api/v1/picking/batches/{batchId}/close`
- `POST /api/v1/picking/batches/{batchId}/abandon`

### Batch content
- `GET /api/v1/picking/batches/{batchId}/orders`
- `GET /api/v1/picking/batches/{batchId}/products`

### Picking operations
- `POST /api/v1/picking/orders/{orderId}/items/{itemId}/picked`
- `POST /api/v1/picking/orders/{orderId}/items/{itemId}/missing`
- `POST /api/v1/picking/orders/{orderId}/drop`

---

## Tabele używane przez picking
- `picking_batches`
- `picking_batch_orders`
- `picking_order_items`
- `picking_batch_items`
- `picking_events`
- `pak_orders`
- `pak_order_items`

---

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

---

## Jak działa open batch

1. Operator wybiera `carrier_key`
2. System znajduje zamówienia z `pak_orders.status = 10`
3. System filtruje je przez resolver wysyłki
4. System wyklucza zamówienia aktywne w innych otwartych batchach
5. Tworzy rekord w `picking_batches`
6. Dodaje zamówienia do `picking_batch_orders`
7. Kopiuje pozycje do `picking_order_items`
8. Buduje agregaty w `picking_batch_items`
9. Zapisuje event `batch_opened`

---

## Refill

Refill działa na podstawie:

- `target_orders_count`
- liczby aktywnych zamówień w batchu

Aktywne zamówienia to wszystkie poza `dropped`.

System:
- liczy ile zamówień brakuje do targetu,
- wyklucza zamówienia już użyte w danym batchu,
- wyklucza zamówienia aktywne w innych batchach,
- dobiera nowe rekordy,
- zapisuje je do `picking_batch_orders`,
- kopiuje ich pozycje do `picking_order_items`.

---

## Agregaty produktów

`GET /picking/batches/{batchId}/products` zwraca dane z `picking_batch_items`.

Jest to widok zagregowany dla całego batcha:
- `product_code`
- `product_name`
- `total_expected_qty`
- `total_picked_qty`
- `status`

To nie są osobne pozycje zamówienia, tylko suma pozycji z wszystkich aktywnych zamówień w batchu.

---

## Event log

Tabela:
- `picking_events`

Typy eventów:
- `batch_opened`
- `batch_refilled`
- `item_picked`
- `item_missing`
- `order_dropped`
- `batch_closed`
- `batch_abandoned`

---

## Ważne doprecyzowanie

Tabela `picking_batch_orders` jest aktywnie używana przez system.  
Wcześniejsze opisy sugerujące, że jest pusta albo nieużywana, są nieaktualne.

