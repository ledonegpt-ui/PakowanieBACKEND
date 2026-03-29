# API v1 Endpoints

Base path: `/api/v1`

Źródło prawdy: `api/v1/index.php` (router).

---

## Health

- `GET /api/v1/health`
  - Publiczny (brak auth)
  - Odpowiedź: `{ system, version, status }`

---

## Auth

- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `GET  /api/v1/auth/me`

---

## Stations

- `GET  /api/v1/stations`
  - Zwraca listę aktywnych stanowisk z bazy.

- `POST /api/v1/stations/select`
  - Status: **stub techniczny** — odbiera `station_code`, nie modyfikuje sesji.
  - Odpowiedź: `{ module, action, status: "stub", received: { station_code } }`

- `POST /api/v1/stations/package-mode`
  - Status: **zaimplementowany**
  - Wymaga: Bearer token (aktywna sesja stacji)
  - Body: `{ "package_mode": "small" | "large" }`
  - Aktualizuje `package_mode` w aktywnej sesji stacji (`user_station_sessions`).
  - Odpowiedź: `{ ok, data: { station: { station_id, station_code, package_mode, package_mode_default } } }`
  - Błędy: 400 jeśli brak tokenu, brak aktywnej sesji lub nieprawidłowa wartość `package_mode`
- `POST /api/v1/stations/picking-batch-size`
- Status: **zaimplementowany**
- Wymaga: Bearer token (aktywna sesja stacji)
- Body: `{ "picking_batch_size": 1..100 }`
- Aktualizuje `picking_batch_size` w aktywnej sesji stacji (`user_station_sessions`).
- Odpowiedź: `{ ok, data: { station: { station_id, station_code, package_mode, package_mode_default, picking_batch_size } } }`
- Błędy: 400 jeśli brak tokenu, brak aktywnej sesji lub nieprawidłowa wartość `picking_batch_size`

- `POST /api/v1/stations/workflow-mode`
  - Status: **zaimplementowany**
  - Wymaga: Bearer token (aktywna sesja stacji)
  - Body: `{ "workflow_mode": "integrated" | "split" }`
  - Aktualizuje `workflow_mode` w aktywnej sesji stacji.
  - Odpowiedź: `{ ok, data: { station: { station_id, station_code, workflow_mode, work_mode, package_mode, package_mode_default, picking_batch_size } } }`
  - Błędy: 400 jeśli brak tokenu, brak aktywnej sesji lub nieprawidłowa wartość

- `POST /api/v1/stations/work-mode`
  - Status: **zaimplementowany**
  - Wymaga: Bearer token (aktywna sesja stacji)
  - Body: `{ "work_mode": "picker" | "packer" }`
  - Aktualizuje `work_mode` w aktywnej sesji stacji (rola operatora w trybie split).
  - Odpowiedź: `{ ok, data: { station: { station_id, station_code, workflow_mode, work_mode, package_mode, package_mode_default, picking_batch_size } } }`
  - Błędy: 400 jeśli brak tokenu, brak aktywnej sesji lub nieprawidłowa wartość

---

## Carriers

- `GET /api/v1/carriers`

---

## Picking

### Batch lifecycle

- `POST /api/v1/picking/batches/open`
- Body może przekazać `target_orders_count`, ale jeśli go brak, backend bierze domyślną wartość z aktywnej sesji stacji (`user_station_sessions.picking_batch_size`), a dopiero potem fallback z konfiguracji.
- `GET  /api/v1/picking/batches/current`
- `GET  /api/v1/picking/batches/{batchId}`
- `POST /api/v1/picking/batches/{batchId}/refill`
- `POST /api/v1/picking/batches/{batchId}/selection-mode`
- `POST /api/v1/picking/batches/{batchId}/close`
- `POST /api/v1/picking/batches/{batchId}/abandon`

### Batch content

- `GET /api/v1/picking/batches/{batchId}/orders`
- `GET /api/v1/picking/batches/{batchId}/products`


### Ważne — {orderId} w picking operations

Parametr {orderId} niesie **picking_batch_orders.id** (liczba całkowita), NIE order_code.

Wartości id zwracają:
- `POST /api/v1/picking/batches/open` → `orders[].id`
- `GET  /api/v1/picking/batches/{batchId}/orders` → `orders[].id`

### Picking operations

- `POST /api/v1/picking/orders/{orderId}/items/{itemId}/picked`
- `POST /api/v1/picking/orders/{orderId}/items/{itemId}/missing`
- `POST /api/v1/picking/orders/{orderId}/drop`

---


## Packing — nawigacja

- `GET /api/v1/packing/current`
  - Zwraca aktualnie otwartą sesję packingu dla zalogowanego operatora
  - Odpowiedź gdy jest otwarta sesja: `{ has_open_session: true, session, order, items, package, label, basket, shipping }`
  - Odpowiedź gdy brak: `{ has_open_session: false, session: null, ... }`
  - Używać przy starcie aplikacji do odzyskania stanu po crash/wylogowaniu

- `GET /api/v1/packing/next-ready-batch`
  - Sprawdza czy jest gotowy koszyk dla packera (tryb split).
  - Wymaga: `work_mode = packer` w sesji
  - Odpowiedź: `{ batch_id, basket_id, basket_no, package_mode, ready, reason }`
  - Szczegóły: `docs/46_split_workflow.md`

- `POST /api/v1/packing/open-next-ready-batch`
  - Atomowo otwiera pierwszy gotowy koszyk i pierwszą sesję pakowania (tryb split).
  - Wymaga: `work_mode = packer` w sesji
  - Odpowiedź: dane sesji pakowania + `auto_loaded_batch`, `batch_id`, `basket_id`, `basket_no`
  - Szczegóły: `docs/46_split_workflow.md`

- `GET /api/v1/packing/next?batch_id={id}`
  - Zwraca pierwsze zamówienie z batcha gotowe do spakowania
  - Zamówienie musi mieć status `picked` w `picking_batch_orders`
  - Pomija zamówienia które mają już sesję `completed` w `packing_sessions`
  - Odpowiedź gdy jest zamówienie: `{ order_code, batch_done: false, batch_id }`
  - Odpowiedź gdy batch skończony: `{ order_code: null, batch_done: true, batch_id }`
  - Błąd 400 jeśli batch nie istnieje

## Packing

Uwaga: parametr `{orderId}` w route faktycznie niesie `order_code`.

- `POST /api/v1/packing/orders/{orderId}/open`
- `GET  /api/v1/packing/orders/{orderId}`
- `POST /api/v1/packing/orders/{orderId}/finish`
- `POST /api/v1/packing/orders/{orderId}/cancel`
- `POST /api/v1/packing/orders/{orderId}/heartbeat`

---

## Shipping

- `GET  /api/v1/shipping/rules`
- `POST /api/v1/shipping/resolve-method`
- `GET  /api/v1/shipping/orders/{orderId}/options`
- `POST /api/v1/shipping/orders/{orderId}/generate-label`
- `GET  /api/v1/shipping/orders/{orderId}/label`
- `POST /api/v1/shipping/orders/{orderId}/reprint`

---

## Heartbeat

- `POST /api/v1/heartbeat`
