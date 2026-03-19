# Moduły docelowe i aktywne

## Cel tego pliku

Ten dokument opisuje **co realnie istnieje dziś w repo** oraz jak traktować to w dokumentacji.

Repo zawiera:
1. **aktywny modułowy backend `/api/v1`**
2. **aktywny legacy flow oparty o `api/*.php` oraz stare widoki**

---

## Aktywne moduły nowego API

### Auth
Status: **wdrożony**

### Stations
Status: **wdrożony częściowo**

Endpointy:
- `GET /api/v1/stations` — lista stacji z bazy
- `POST /api/v1/stations/select` — stub techniczny (nie modyfikuje sesji)
- `POST /api/v1/stations/package-mode` — **zaimplementowany**
  - Aktualizuje `package_mode` (`small` | `large`) dla aktywnej sesji stacji
  - Wymaga aktywnej sesji w `user_station_sessions`

### Carriers
Status: **wdrożony**

### Picking
Status: **wdrożony**

Obsługuje:
- open batch, refill, selection_mode
- mark item picked / missing
- manual drop order
- close / abandon
- event log

### Packing
Status: **wdrożony**

Snapshoty sesji: `packing_sessions`, `packing_session_items`

### Shipping
Status: **wdrożony**

Adaptery: Allegro, BaseLinker, DPD, GLS, InPost

### Heartbeat
Status: **wdrożony**

---

## Legacy flow

Status: **nadal obecny w repo i nadal istotny dokumentacyjnie**

Legacy warstwa: `queue.php`, `order.php`, `api/*.php` (18 plików), `assets/js/queue.js`

Szczegółowy opis endpointów legacy: `docs/65_legacy_api_endpoints.md`
