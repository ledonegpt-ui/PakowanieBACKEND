# 02. Workflow docelowy

## Status
PLANOWANE + częściowo POTWIERDZONE

## 1. Logowanie operatora
Operator loguje się przez:
- skan kodu kreskowego operatora,
- wskazanie stanowiska.

Wynik:
- powstaje sesja w `user_station_sessions`,
- operator dostaje bearer token,
- aplikacja zna aktualne stanowisko i tryb workflow.

## 2. Menu kafelków kurierskich
Aplikacja pobiera listę grup menu:
- InPost
- DPD
- GLS
- ORLEN
- Allegro One
- DHL
- ERLI
- Odbiór osobisty
- Inne (fallback awaryjny)

Każdy kafelek pokazuje:
- `group_key`
- `label`
- `orders_count`
- przykładowe metody dostawy w tej grupie

## 3. Otwarcie batcha kompletacyjnego
Operator wybiera kafelek `menu_group`, np. `dpd`.

Backend:
- znajduje pierwsze N otwartych zamówień pasujących do `menu_group`,
- pomija zamówienia już przypisane do innych otwartych batchy,
- tworzy `picking_batches`,
- tworzy `picking_batch_orders`,
- tworzy `picking_order_items`,
- tworzy zagregowaną listę `picking_batch_items`.

## 4. Kompletacja produktów
Aplikacja pokazuje listę produktów do zebrania:
- kod / SKU
- nazwa
- suma wymaganych ilości
- status

Dla pozycji operator może wykonać:
- `picked`
- `missing`

## 5. Braki i refill
Jeśli dla zamówienia wystąpi brak:
- zamówienie wypada z batcha,
- jego status w batchu przechodzi na `dropped`,
- backend dobiera następne zamówienie z tego samego `menu_group`,
- agregaty produktów są odświeżane.

## 6. Przejście do pakowania
Po zebraniu pozycji system przechodzi do pakowania.

Aplikacja widzi:
- dane zamówienia
- pozycje
- zdjęcia
- opisy
- ilości oczekiwane
- ilości spakowane

## 7. Zakończenie pakowania
Po kliknięciu „koniec”:
- jeśli przesyłka wymaga gabarytu, aplikacja musi go podać,
- backend używa resolvera wysyłki,
- backend wybiera adapter generowania etykiety,
- backend zapisuje tracking, shipment id, label metadata,
- backend przygotowuje wydruk.

## 8. Wydruk
Docelowo backend ma sterować wydrukiem przez stanowisko:
- drukarka przypisana do stanowiska,
- etykieta PDF/ZPL,
- reprint możliwy z API.

## 9. Zdarzenia i audyt
Każdy istotny krok ma być logowany:
- auth
- wybór stanowiska
- open batch
- refill
- missing
- finish packing
- generate label
- reprint
- błędy workflow
