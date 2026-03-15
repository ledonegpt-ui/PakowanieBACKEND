# API v1 Endpoints

## Base path
- `/api/v1`

## Routing
Źródłem prawdy dla listy endpointów jest:
- `api/v1/index.php`

Cały ruch do `/api/v1/*` jest przepisywany do tego pliku przez rewrite.

---

## Autoryzacja

Publiczny pozostaje tylko:
- `GET /api/v1/health`
- `POST /api/v1/auth/login`

Pozostałe endpointy działają za middleware sesji / auth nowego API.

---

## Health
- `GET /api/v1/health`

---

## Auth
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `GET /api/v1/auth/me`

---

## Stations
- `GET /api/v1/stations`
- `POST /api/v1/stations/select`

---

## Carriers
- `GET /api/v1/carriers`

---

## Picking

### Batch lifecycle
- `POST /api/v1/picking/batches/open`
- `GET /api/v1/picking/batches/current`
- `GET /api/v1/picking/batches/{batchId}`
- `POST /api/v1/picking/batches/{batchId}/refill`
- `POST /api/v1/picking/batches/{batchId}/selection-mode`
- `POST /api/v1/picking/batches/{batchId}/close`
- `POST /api/v1/picking/batches/{batchId}/abandon`

### Batch content
- `GET /api/v1/picking/batches/{batchId}/orders`
- `GET /api/v1/picking/batches/{batchId}/products`

### Item / order actions
- `POST /api/v1/picking/orders/{orderId}/items/{itemId}/picked`
- `POST /api/v1/picking/orders/{orderId}/items/{itemId}/missing`
- `POST /api/v1/picking/orders/{orderId}/drop`

### Uwaga semantyczna
W pickingu:
- `{batchId}` to ID batcha
- `{orderId}` to ID rekordu `picking_batch_orders`
- `{itemId}` to ID rekordu `picking_order_items`

---

## Packing

### Endpointy
- `POST /api/v1/packing/orders/{orderId}/open`
- `GET /api/v1/packing/orders/{orderId}`
- `POST /api/v1/packing/orders/{orderId}/finish`
- `POST /api/v1/packing/orders/{orderId}/cancel`
- `POST /api/v1/packing/orders/{orderId}/heartbeat`

### Uwaga semantyczna
W packingu parametr:
- `{orderId}`

w praktyce oznacza:
- `order_code`

a nie liczbowe ID.

### Uwaga o heartbeat
Endpoint:
- `POST /api/v1/packing/orders/{orderId}/heartbeat`

jest obecny w routerze, ale kontroler odsyła tylko informację:
- `use_global_heartbeat`

Realny heartbeat dla nowego API jest pod:
- `POST /api/v1/heartbeat`

---

## Shipping

### Endpointy
- `GET /api/v1/shipping/rules`
- `POST /api/v1/shipping/resolve-method`
- `GET /api/v1/shipping/orders/{orderId}/options`
- `POST /api/v1/shipping/orders/{orderId}/generate-label`
- `GET /api/v1/shipping/orders/{orderId}/label`
- `POST /api/v1/shipping/orders/{orderId}/reprint`

### Uwaga semantyczna
W shippingu parametr:
- `{orderId}`

również jest używany jako:
- `order_code`

---

## Heartbeat
- `POST /api/v1/heartbeat`

---

## Szybka mapa workflow

### Picking
1. `POST /api/v1/picking/batches/open`
2. `GET /api/v1/picking/batches/current`
3. `GET /api/v1/picking/batches/{batchId}/products`
4. akcje `picked` / `missing` / `drop`
5. `POST /api/v1/picking/batches/{batchId}/refill`
6. `POST /api/v1/picking/batches/{batchId}/close`

### Packing
1. `POST /api/v1/packing/orders/{orderId}/open`
2. `GET /api/v1/shipping/orders/{orderId}/options`
3. `POST /api/v1/shipping/orders/{orderId}/generate-label`
4. `POST /api/v1/packing/orders/{orderId}/finish`

---

## Powiązane legacy API

To zestawienie dotyczy wyłącznie:
- `/api/v1/*`

Stare endpointy plikowe w:
- `/api/*.php`

są opisane osobno w:
- `docs/65_legacy_file_endpoints.md`
