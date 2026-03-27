# Picking API

## Zakres

Ten plik opisuje nowy picking działający pod `/api/v1/picking/...`.

Nie opisuje legacy flow opartego o `queue.php`, `order.php` i `api/*.php`.

---

## Główne endpointy

### `POST /api/v1/picking/batches/open`
Otwiera batch pickingu.

### `POST /api/v1/picking/batches/{batchId}/refill`
Dociąga nowe ordery do batcha.

### `GET /api/v1/picking/batches/{batchId}`
Zwraca szczegóły batcha.

### `POST /api/v1/picking/batches/{batchId}/items/{itemId}/pick`
Oznacza item jako zebrany.

### `POST /api/v1/picking/batches/{batchId}/items/{itemId}/missing`
Oznacza item jako brakujący.

### `POST /api/v1/picking/batches/{batchId}/orders/{orderId}/drop`
Manualnie zdejmuje order z batcha.

### `POST /api/v1/picking/batches/{batchId}/close`
Zamyka batch.

### `POST /api/v1/picking/batches/{batchId}/abandon`
Porzuca batch.

---

## Ważne zasady

### `selection_mode`
Batch może być otwierany z określonym trybem doboru zamówień.

### Snapshot pozycji
Picking nie pracuje bezpośrednio na żywych rekordach GUI, tylko na snapshotach:
- `picking_batch_orders`
- `picking_order_items`
- `picking_batch_items`

### `missing` vs `drop`
- `missing` działa na poziomie itemu
- `drop` działa na poziomie całego orderu
- `missing` nie powoduje automatycznie `drop`

### Agregacja GUI
Widok produktowy jest budowany agregacyjnie po:
- `subiekt_tow_id`
- `uom`

### Przejście dalej
Po zamknięciu batcha ordery mogą przejść do dalszego etapu, w tym do packingu.

## Aktualizacja 2026-03-25 — domyślny rozmiar batcha z sesji stacji

Dla `POST /api/v1/picking/batches/open` można jawnie przekazać:
- `target_orders_count`

Jeśli `target_orders_count` nie zostanie przekazany:
- backend najpierw sprawdza aktywną sesję stacji
- używa `user_station_sessions.picking_batch_size`
- dopiero potem używa fallbacku z konfiguracji `PICKING_BATCH_SIZE`

To ustawienie:
- jest niezależne od `package_mode`
- `package_mode` nadal określa tryb `small` / `large`
- `picking_batch_size` określa ile zamówień ma zostać pobranych do nowego batcha

