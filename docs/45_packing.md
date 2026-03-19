# Packing

## Cel modułu

Nowy packing w `/api/v1` działa jako **sesyjny etap po pickingu**.

To nie jest to samo co stary flow oparty o:
- `api/start_pack.php`
- `api/finish_pack.php`
- `api/cancel_pack.php`

Nowy packing pracuje na własnych snapshotach i własnym modelu sesji.

---

## Główne tabele

### `packing_sessions`
Reprezentuje aktywną lub zakończoną sesję pakowania dla zamówienia.

Typowe pola:
- `id`
- `order_id`
- `station_id`
- `operator_id`
- `status`
- `started_at`
- `completed_at`
- `cancelled_at`

### `packing_session_items`
Snapshot pozycji do spakowania w ramach sesji.

Typowe pola:
- `packing_session_id`
- `order_item_id`
- `sku`
- `name`
- `expected_qty`
- `packed_qty`

### `packing_events`
Log zdarzeń packingu.

---

## Statusy sesji

`packing_sessions.status`:
- `open`
- `completed`
- `cancelled`
- `abandoned`

---

## Główne endpointy

### `POST /api/v1/packing/orders/{orderId}/open`
Otwiera sesję pakowania.

Efekt:
- tworzy lub odzyskuje aktywną sesję
- buduje snapshot pozycji w `packing_session_items`

### `GET /api/v1/packing/orders/{orderId}`
Zwraca szczegóły sesji i dane orderu do ekranu pakowania.

### `POST /api/v1/packing/orders/{orderId}/finish`
Kończy sesję pakowania, ale dopiero gdy spełnione są warunki techniczne.

### `POST /api/v1/packing/orders/{orderId}/cancel`
Anuluje sesję pakowania.

### `POST /api/v1/packing/orders/{orderId}/heartbeat`
Endpoint pomocniczy; w praktyce należy używać globalnego:
- `POST /api/v1/heartbeat`

---

## Najważniejsza zasada biznesowa

`finish` w nowym packingu **nie jest odpowiedzialne za wygenerowanie etykiety jako główny etap**.

Przed zamknięciem sesji system wymaga, aby order miał:
- paczkę w `packages`
- etykietę w `package_labels`

To oznacza, że nowy packing korzysta z efektów modułu shipping.

---

## Relacja do shipping

Shipping odpowiada za:
- resolve provider
- generowanie przesyłki
- zapis `packages`
- zapis `package_labels`

Packing:
- otwiera sesję
- pozwala operatorowi przejść przez order
- kończy sesję dopiero po spełnieniu warunków technicznych

---

## Relacja do legacy flow

Nowy packing nie powinien być mylony ze starymi plikami:
- `queue.php`
- `order.php`
- `api/start_pack.php`
- `api/finish_pack.php`
- `api/cancel_pack.php`

Legacy flow operuje bezpośrednio na statusach `pak_orders`.
Nowy packing pracuje na osobnych tabelach sesyjnych.
