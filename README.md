Pakowacz — Warehouse Picking & Packing System

System do obsługi zbierania (picking) oraz pakowania (packing) zamówień
z marketplace i ERP.

Projekt jest zoptymalizowany pod pracę magazynu z wieloma stanowiskami oraz
automatyczny podział zamówień według:

carrier + package_mode

czyli np.

DPD + small
DPD + large
InPost + small
Główne funkcje systemu
Import zamówień

Źródła:

marketplace

ERP

integracje API

Tabele:

pak_orders
pak_order_items
Klasyfikacja produktów

Tabela:

product_size_map

Pole:

size_status

Możliwe wartości:

small
large
other

Z tego wyliczany jest rozmiar zamówienia.

Picking

Moduł zbierania produktów przez magazyniera.

Tabela batchy:

picking_batches

Powiązane tabele:

picking_batch_orders
picking_order_items
picking_batch_items
picking_events

Batch pickingu jest filtrowany po:

carrier_key
package_mode
Packing

Moduł pakowania zamówień.

Funkcje:

skan zamówienia

weryfikacja produktów

generowanie etykiety

druk Zebra

Architektura systemu
GUI (PHP)
│
│ REST API
▼
Controller
│
▼
Service Layer
│
▼
Repository Layer
│
▼
MySQL
Diagram przepływu
Marketplace / ERP
│
▼
pak_orders
pak_order_items
│
▼
product_size_map
│
▼
resolveOrderPackageSize()
│
▼
Picking
────────────────────
picking_batches
picking_batch_orders
picking_order_items
picking_batch_items
│
▼
Packing
────────────────────
GUI
druk etykiety
drukarka Zebra
package_mode

System rozdziela picking na dwa typy paczek:

small
large

Źródło:

product_size_map.size_status

Reguły:

large   → jeśli choć jeden produkt large
small   → jeśli wszystkie small / other
unknown → brak klasyfikacji

unknown nie trafia do automatycznego pickingu.

Stanowiska magazynowe

Tabela:

stations

Pole:

package_mode_default

Sesja operatora:

user_station_sessions.package_mode
user_station_sessions.picking_batch_size

Operator może zmienić dla swojej sesji:

- tryb stanowiska (`package_mode`)
- liczbę zamówień pobieranych do nowego batcha (`picking_batch_size`)

API

Base URL:

/api/v1

Najważniejsze endpointy:

POST /auth/login
GET  /auth/me

GET  /carriers

POST /stations/package-mode

POST /picking/batches/open
GET  /picking/batches/current
GET  /picking/batches/{id}/orders
GET  /picking/batches/{id}/products

POST /picking/orders/{order}/items/{item}/picked
POST /picking/orders/{order}/items/{item}/missing
POST /picking/orders/{order}/drop

POST /picking/batches/{id}/refill
POST /picking/batches/{id}/selection-mode
POST /picking/batches/{id}/close
POST /picking/batches/{id}/abandon
GUI

GUI znajduje się w katalogu:

GUI/

Najważniejsze pliki:

GUI/index.php
GUI/workflow.php
GUI/picking.php
GUI/packing.php
Dokumentacja

Pełna dokumentacja znajduje się w katalogu:

docs/

Najważniejsze pliki:

docs/10_architecture_overview.md
docs/40_picking.md
docs/41_picking_data_model.md
docs/50_target_tables.md
docs/60_api_v1_endpoints.md
Kluczowe założenia projektu
Picking działa na snapshot danych

Dane z pak_order_items są kopiowane do:

picking_order_items

Dzięki temu picking nie zmienia się w trakcie pracy.

Batch jest izolowany

Batch posiada:

carrier_key
package_mode
selection_mode
Refill

Refill automatycznie dobiera kolejne zamówienia.

Technologie

Backend:

PHP
MySQL
REST API

Frontend:

PHP GUI
JavaScript

Druk:

Zebra printers
Status projektu

System jest używany produkcyjnie w magazynie.

Obsługuje:

picking batchowy

dynamiczny refill

klasyfikację paczek

generowanie etykiet

Autor

Projekt rozwijany wewnętrznie dla systemu magazynowego.

## Aktualizacja 2026-03-25 — sesyjna wielkość batcha pickingowego

Nowe ustawienie sesji stacji:
- `user_station_sessions.picking_batch_size`

To ustawienie:
- jest niezależne od `package_mode`
- określa ile zamówień ma zostać pobranych do nowego batcha
- działa dla całej aktywnej sesji stanowiska

Nowy endpoint:
- `POST /api/v1/stations/picking-batch-size`

Body:
- `{ "picking_batch_size": 1..100 }`

Zasada działania:
- `package_mode` nadal określa tryb `small` / `large`
- przy `POST /api/v1/picking/batches/open` backend może przyjąć `target_orders_count` z body
- jeśli `target_orders_count` nie zostanie przekazany, backend bierze wartość z `user_station_sessions.picking_batch_size`
- jeśli sesja nie ma poprawnej wartości, używany jest fallback z konfiguracji `PICKING_BATCH_SIZE`

