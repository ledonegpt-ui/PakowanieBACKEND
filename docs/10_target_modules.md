# Moduły docelowe i aktywne

## Cel tego pliku

Ten dokument opisuje **co realnie istnieje dziś w repo** oraz jak traktować to w dokumentacji.

Repo zawiera:
1. **aktywny modułowy backend `/api/v1`**
2. **aktywny legacy flow oparty o `api/*.php` oraz stare widoki**

Dokumentacja powinna rozróżniać te dwie warstwy.

---

## Aktywne moduły nowego API

### Auth
Status: **wdrożony**

### Stations
Status: **wdrożony częściowo**

Uwaga:
- `POST /api/v1/stations/select` istnieje w routerze, ale obecnie jest lekkim endpointem technicznym / stubem

### Carriers
Status: **wdrożony**

### Picking
Status: **wdrożony**

Obsługuje:
- open batch
- refill
- `selection_mode`
- mark item picked
- mark item missing
- manual drop order
- close / abandon
- event log

### Packing
Status: **wdrożony**

Nowy packing działa sesyjnie i ma własne snapshoty:
- `packing_sessions`
- `packing_session_items`

### Shipping
Status: **wdrożony**

W repo istnieją adaptery m.in. dla:
- Allegro
- BaseLinker
- DPD
- GLS
- InPost

### Heartbeat
Status: **wdrożony**

Główny endpoint:
- `POST /api/v1/heartbeat`

---

## Legacy flow

Status: **nadal obecny w repo i nadal istotny dokumentacyjnie**

Legacy warstwa obejmuje:
- `queue.php`
- `order.php`
- `api/*.php`
- `assets/js/queue.js`

Dopóki te pliki istnieją i są używane, dokumentacja projektu musi je obejmować.
