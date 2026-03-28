# System Status

System modules currently implemented:

Auth, Stations, Carriers, Picking, Packing, Shipping, Heartbeat, Import

Architecture: API → Controllers → Services → Repositories → MySQL

---

# Picking module

Status: IMPLEMENTED

Features:
- batch creation, order selection, product picking
- missing items, manual order drop, batch refill
- batch close, batch abandon, event log, aggregated product list

Database tables:
picking_batches, picking_batch_orders, picking_order_items, picking_batch_items, picking_events

Workflow: IMPORT → PICKING → PACKING → SHIPPING

Orders used for picking must have: pak_orders.status = 10

## Korekta 2026-03-13 — picking data model

- nagłówek zamówienia pochodzi z `pak_orders`
- pozycje do pickingu pochodzą z Subiekta i są zapisane w `pak_order_items`
- przy otwarciu batcha są kopiowane do `picking_order_items`
- po cleanupie pozycje pickingu są subiektowe (`line_key` = `SUB-*`)
- `offer_id` pochodzi z `ob_SyncId` i jest głównym łącznikiem z marketplace
- wcześniejsze opisy sugerujące równoległe aktywne źródła `EU-*` / `BL-*` są nieaktualne

---

## Stations — aktualizacja 2026-03-19

Moduł Stations posiada teraz trzy endpointy:
- `GET /api/v1/stations` — lista aktywnych stacji
- `POST /api/v1/stations/select` — stub techniczny
- `POST /api/v1/stations/package-mode` — **zaimplementowany**
  - aktualizuje tryb pakowania (`small` | `large`) w aktywnej sesji stacji
  - źródło: `StationsController::packageMode()` → `StationsService::updatePackageMode()`

---

## Legacy flow nadal istnieje

Repo zawiera nie tylko nowy modułowy backend `/api/v1`, ale również starszą warstwę plikową.

Legacy: `queue.php`, `order.php`, `api/*.php`, `assets/js/queue.js`

Aktualny stan repo to współistnienie obu tych warstw.
Szczegółowy opis endpointów legacy: `docs/65_legacy_api_endpoints.md`
