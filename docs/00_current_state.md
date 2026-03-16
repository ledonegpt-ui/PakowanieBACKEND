# System Status

System modules currently implemented:

Auth
Stations
Carriers
Picking
Packing
Shipping
Heartbeat
Import

Architecture:

API → Controllers → Services → Repositories → MySQL

---

# Picking module

Status: IMPLEMENTED

Features:

✔ batch creation  
✔ order selection  
✔ product picking  
✔ missing items  
✔ manual order drop  
✔ batch refill  
✔ batch close  
✔ batch abandon  
✔ event log  
✔ aggregated product list  

Database tables:

picking_batches  
picking_batch_orders  
picking_order_items  
picking_batch_items  
picking_events  

Workflow:

IMPORT → PICKING → PACKING → SHIPPING

Orders used for picking must have:

pak_orders.status = 10

## Korekta 2026-03-13 — picking data model

### Picking — doprecyzowanie źródła danych
- nagłówek zamówienia pochodzi z `pak_orders`
- pozycje do pickingu pochodzą z Subiekta i są zapisane w `pak_order_items`
- przy otwarciu batcha są kopiowane do `picking_order_items`

Flow:
- source → `pak_orders`
- Subiekt → `pak_order_items`
- `pak_order_items` → `picking_order_items`

### Ważne
- po cleanupie pozycje pickingu są subiektowe
- `line_key` ma semantykę `SUB-*`
- `offer_id` pochodzi z `ob_SyncId` i jest głównym łącznikiem z marketplace / zdjęciami / metadanymi
- wcześniejsze opisy sugerujące równoległe aktywne źródła pozycji `EU-*` / `BL-*` są nieaktualne dla obecnego modelu

---

## Legacy flow nadal istnieje

Repo zawiera nie tylko nowy modułowy backend `/api/v1`, ale również starszą warstwę plikową, która nadal jest częścią projektu.

Legacy obejmuje między innymi:
- `queue.php`
- `order.php`
- `api/*.php`
- `assets/js/queue.js`

To oznacza, że dokumentacja projektu musi rozróżniać:
1. nowy modułowy backend
2. starą warstwę file-based / legacy

Aktualny stan repo to współistnienie obu tych warstw.
