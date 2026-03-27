System Architecture Overview

Dokument opisuje ogólną architekturę systemu pakowania oraz przepływ danych
od importu zamówienia aż do wygenerowania etykiety.

Główne moduły systemu

System składa się z trzech głównych etapów:

Import zamówień

Picking (zbieranie produktów)

Packing (pakowanie i generowanie etykiety)

Diagram wysokiego poziomu
Marketplace / ERP
│
▼
pak_orders
pak_order_items
│
▼
product_size_map
(klasyfikacja rozmiaru)
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
picking_events
│
▼
Packing
────────────────────
GUI pakowania
druk etykiety
drukarka Zebra
Architektura API
GUI (PHP)
│
│ REST
▼
API Controller
│
▼
Service Layer
│
▼
Repository Layer
│
▼
MySQL

Warstwy systemu:

GUI → API → Service → Repository → Database
Flow zamówienia
1 Import zamówienia

Zamówienie trafia do tabel:

pak_orders
pak_order_items

Status początkowy:

status = 10

czyli:

READY FOR PICKING
2 Klasyfikacja produktów

Tabela:

product_size_map

Pole:

size_status

Możliwe wartości:

small
large
other

Reguły wyliczenia rozmiaru zamówienia:

large   → jeśli choć jeden produkt large
small   → jeśli wszystkie produkty small lub other
unknown → jeśli choć jeden produkt nie ma klasyfikacji
3 Tryb stanowiska

Tabela:

stations

Pole:

package_mode_default

Możliwe wartości:

small
large

Sesja operatora:

user_station_sessions.package_mode
user_station_sessions.picking_batch_size

Operator może zmienić dla swojej sesji:
- tryb stanowiska (`package_mode`)
- domyślną liczbę zamówień do nowego batcha (`picking_batch_size`)

4 Otwieranie batcha pickingu

Endpoint:

POST /picking/batches/open

Batch jest filtrowany jednocześnie po:

carrier_key
package_mode

czyli np:

dpd + small
dpd + large
inpost + small
5 Snapshot danych

Po otwarciu batcha dane są kopiowane do:

picking_order_items

Dlatego picking operuje na snapshot danych,
a nie bezpośrednio na pak_order_items.

6 Agregacja produktów

Produkty agregowane są w tabeli:

picking_batch_items

Klucz agregacji:

subiekt_tow_id
uom
7 Akcje operatora

Operator może wykonać operacje:

picked
missing
drop
refill
close
abandon
8 Refill batcha

Refill dobiera kolejne zamówienia gdy:

liczba orderów < target_orders_count

Filtry refill:

carrier_key
package_mode
selection_mode
9 Zamknięcie batcha

Batch można zamknąć gdy:

brak orderów assigned

Status batcha zmienia się na:

completed
10 Packing

Po zakończeniu pickingu operator przechodzi do:

packing GUI

Etap pakowania:

skan zamówienia
druk etykiety
Kluczowe zasady systemu
Picking działa na snapshot danych

System nie operuje bezpośrednio na pak_order_items.

package_mode rozdziela picking

System dzieli picking na:

small
large
unknown nie trafia do automatycznego pickingu

Jeśli produkt nie ma klasyfikacji:

order = unknown

Taki order nie jest dobierany automatycznie do batcha.

Najważniejsze tabele systemu
pak_orders
pak_order_items
product_size_map

stations
user_station_sessions

picking_batches
picking_batch_orders
picking_order_items
picking_batch_items
picking_events
Główne endpointy API
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
Podsumowanie

Picking w systemie działa według schematu:

carrier_key + package_mode

i operuje na snapshot danych w batchach, dzięki czemu:

picking jest stabilny

GUI działa szybko

refill działa przewidywalnie

operatorzy nie blokują sobie pracy

## Aktualizacja 2026-03-25 — sesyjny picking_batch_size

Nowy element architektury sesji stacji:
- `user_station_sessions.picking_batch_size`

Rola:
- przechowuje domyślną liczbę zamówień pobieranych do nowego batcha
- działa dla całej aktywnej sesji stanowiska
- jest niezależny od `package_mode`

Nowy endpoint:
- `POST /api/v1/stations/picking-batch-size`

Zależności:
- `package_mode` nadal steruje rozdziałem `small` / `large`
- `target_orders_count` pozostaje parametrem batcha
- przy `POST /api/v1/picking/batches/open`:
  - jeśli body zawiera `target_orders_count`, ta wartość ma priorytet
  - jeśli nie, backend bierze wartość z `user_station_sessions.picking_batch_size`
  - jeśli sesja nie ma poprawnej wartości, używany jest fallback z konfiguracji `PICKING_BATCH_SIZE`

