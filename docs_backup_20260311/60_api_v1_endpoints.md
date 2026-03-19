# API v1 — endpointy i stan implementacji

Wszystkie endpointy wymagają nagłówka: `Authorization: Bearer {token}`
Wyjątek: `POST /api/v1/auth/login` i `GET /api/v1/health` — publiczne.

## Legenda
- ✅ DZIAŁA
- ⚠️ CZĘŚCIOWY
- ❌ STUB / NIE ZAIMPLEMENTOWANY

---

## health
- ✅ GET /api/v1/health

## auth
- ✅ POST /api/v1/auth/login — body: `{"barcode": "a001", "station_code": "1"}`
- ✅ POST /api/v1/auth/logout
- ✅ GET /api/v1/auth/me

## stations
- ✅ GET /api/v1/stations
- ✅ POST /api/v1/stations/select

## carriers / resolver
- ✅ GET /api/v1/carriers
- ❌ GET /api/v1/carriers/{carrierKey}/stats — niezaimplementowany
- ✅ POST /api/v1/shipping/resolve-method — diagnostyczny

## picking batches
- ✅ POST /api/v1/picking/batches/open — body: `{"carrier_key": "dpd"}`
- ✅ GET /api/v1/picking/batches/current
- ✅ GET /api/v1/picking/batches/{batchId}
- ✅ POST /api/v1/picking/batches/{batchId}/refill
- ✅ POST /api/v1/picking/batches/{batchId}/close
- ✅ POST /api/v1/picking/batches/{batchId}/abandon

## picking orders / items
- ✅ GET /api/v1/picking/batches/{batchId}/orders
- ✅ GET /api/v1/picking/batches/{batchId}/products
- ✅ POST /api/v1/picking/orders/{orderCode}/items/{pakItemId}/picked
- ✅ POST /api/v1/picking/orders/{orderCode}/items/{pakItemId}/missing
- ✅ POST /api/v1/picking/orders/{orderCode}/drop

## heartbeat
- ✅ POST /api/v1/heartbeat — globalne, odświeża sesję picking i packing

## packing
- ✅ POST /api/v1/packing/orders/{orderCode}/open
- ✅ GET /api/v1/packing/orders/{orderCode}
- ⚠️ POST /api/v1/packing/orders/{orderCode}/finish — wymaga wcześniej wygenerowanej etykiety
- ✅ POST /api/v1/packing/orders/{orderCode}/cancel
- ❌ POST /api/v1/packing/orders/{orderCode}/heartbeat — przekierowuje do globalnego heartbeat

## shipping
- ✅ GET /api/v1/shipping/orders/{orderCode}/options
- ⚠️ POST /api/v1/shipping/orders/{orderCode}/generate-label — działa dla DPD (bug XML), reszta stub
- ✅ GET /api/v1/shipping/orders/{orderCode}/label
- ✅ POST /api/v1/shipping/orders/{orderCode}/reprint
- ✅ GET /api/v1/shipping/rules

## events / audit
- ❌ GET /api/v1/orders/{orderId}/events — niezaimplementowany
- ❌ GET /api/v1/picking/batches/{batchId}/events — niezaimplementowany
- ❌ GET /api/v1/packing/sessions/{sessionId}/events — niezaimplementowany

---

## Uwagi implementacyjne

### Identyfikator zamówienia
Parametr `{orderId}` w URL to **order_code** (np. `1873400`), nie wewnętrzne ID z bazy.

### Login
```bash
curl -X POST https://pakowanie.led-one.pl/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"barcode":"a001","station_code":"1"}'
```

### Kolejność wywołań (obowiązkowa)
1. POST /auth/login
2. POST /picking/batches/open
3. POST /picking/orders/{orderCode}/items/{pakItemId}/picked (dla każdej pozycji)
4. POST /picking/batches/{batchId}/close
5. POST /packing/orders/{orderCode}/open
6. POST /shipping/orders/{orderCode}/generate-label
7. GET /shipping/orders/{orderCode}/label
8. POST /packing/orders/{orderCode}/finish

Nie wolno przeskakiwać kroków — packing/open odrzuci zamówienie bez zakończonego pickingu,
packing/finish odrzuci sesję bez wygenerowanej etykiety.
