# API v1 Endpoints

Base path:
- `/api/v1`

## Health
- `GET /api/v1/health`

## Auth
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `GET /api/v1/auth/me`

## Stations
- `GET /api/v1/stations`
- `POST /api/v1/stations/select`

Uwagi:
- `POST /api/v1/stations/select` istnieje w routerze i powinien być traktowany jako lekki endpoint techniczny / stub.
- Nie jest to rozbudowany odpowiednik starego flow logowania stanowiska z warstwy legacy.

## Carriers
- `GET /api/v1/carriers`

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

### Picking operations
- `POST /api/v1/picking/orders/{orderId}/items/{itemId}/picked`
- `POST /api/v1/picking/orders/{orderId}/items/{itemId}/missing`
- `POST /api/v1/picking/orders/{orderId}/drop`

## Packing
Uwaga: w route parametr `{orderId}` faktycznie niesie `order_code`.

- `POST /api/v1/packing/orders/{orderId}/open`
- `GET /api/v1/packing/orders/{orderId}`
- `POST /api/v1/packing/orders/{orderId}/finish`
- `POST /api/v1/packing/orders/{orderId}/cancel`
- `POST /api/v1/packing/orders/{orderId}/heartbeat`

## Shipping
- `GET /api/v1/shipping/rules`
- `POST /api/v1/shipping/resolve-method`
- `GET /api/v1/shipping/orders/{orderId}/options`
- `POST /api/v1/shipping/orders/{orderId}/generate-label`
- `GET /api/v1/shipping/orders/{orderId}/label`
- `POST /api/v1/shipping/orders/{orderId}/reprint`

## Heartbeat
- `POST /api/v1/heartbeat`
