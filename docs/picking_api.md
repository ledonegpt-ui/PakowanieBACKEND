========================================
# Picking API — dokumentacja

## Przegląd

Picking to etap kompletacji towaru przed pakowaniem.
Operator wybiera grupę kurierską, system dobiera zamówienia do batcha,
operator zbiera towar z magazynu oznaczając pozycje jako zebrane lub brakujące.

---

## Tabele

| Tabela | Opis |
|---|---|
| `picking_batches` | Nadrzędny rekord partii roboczej |
| `picking_batch_orders` | Zamówienia przypisane do batcha |
| `picking_order_items` | Pozycje zamówień do zebrania |
| `picking_batch_items` | Zagregowana lista produktów dla batcha |
| `picking_events` | Audit log wszystkich akcji |

### Statusy

**picking_batches.status**
- `open` — batch aktywny, operator pracuje
- `completed` — batch zamknięty

**picking_batch_orders.status**
- `assigned` — zamówienie aktywne, w trakcie kompletacji
- `picked` — wszystkie pozycje zebrane
- `dropped` — zamówienie wypadło z batcha

**picking_order_items.status**
- `pending` — pozycja czeka na zebranie
- `picked` — pozycja zebrana
- `missing` — brak na magazynie

---

## Endpointy

Wszystkie endpointy wymagają nagłówka:
```
Authorization: Bearer {token}
```

---

### POST /api/v1/picking/batches/open

Otwiera nowy batch dla operatora.

**Request:**
```json
{
  "carrier_key": "dpd",
  "target_orders_count": 10
}
```

| Pole | Typ | Wymagane | Opis |
|---|---|---|---|
| `carrier_key` | string | tak | Klucz grupy kurierskiej np. `dpd`, `inpost`, `gls` |
| `target_orders_count` | int | nie | Liczba zamówień w batchu, domyślnie 10, max 50 |

**Response:**
```json
{
  "ok": true,
  "data": {
    "picking": {
      "batch": {
        "id": 4,
        "batch_code": "BATCH-1773182921-1",
        "carrier_key": "dpd",
        "user_id": 1,
        "station_id": 1,
        "status": "open",
        "workflow_mode": "integrated",
        "target_orders_count": 10,
        "active_orders_count": 10,
        "picked_orders_count": 0,
        "dropped_orders_count": 0,
        "total_orders_count": 10,
        "started_at": "2026-03-10 23:41:45",
        "completed_at": null,
        "abandoned_at": null
      },
      "orders": [...],
      "products": [...]
    }
  }
}
```

**Błędy:**
- `400` — operator ma już otwarty batch
- `400` — brak zamówień dla podanej grupy
- `400` — brak `carrier_key`

**Logika:**
1. Sprawdza czy operator nie ma otwartego batcha (jeden na raz)
2. Pobiera kandydatów z `pak_orders` gdzie `status = 10`
3. Przepuszcza każde zamówienie przez `ShippingMethodResolver`
4. Wyklucza zamówienia zajęte w innych otwartych batchach
5. Tworzy batch, przypisuje zamówienia i pozycje
6. Buduje agregaty `picking_batch_items`
7. Loguje event `batch_opened`
8. Całość w transakcji MySQL z `SELECT FOR UPDATE`

---

### GET /api/v1/picking/batches/current

Zwraca aktualnie otwarty batch operatora.

**Response:**
```json
{
  "ok": true,
  "data": {
    "picking": {
      "batch": { ... },
      "orders": [...],
      "products": [...]
    }
  }
}
```

Jeśli operator nie ma otwartego batcha, `picking` jest `null`.

---

### GET /api/v1/picking/batches/{batchId}

Zwraca szczegóły konkretnego batcha.

**Response:** identyczny jak `/current`

**Błędy:**
- `400` — batch nie istnieje
- `400` — batch należy do innego operatora lub stanowiska

---

### GET /api/v1/picking/batches/{batchId}/orders

Zwraca listę zamówień w batchu.

**Response:**
```json
{
  "ok": true,
  "data": {
    "orders": [
      {
        "id": 1,
        "order_code": "1873329",
        "status": "assigned",
        "drop_reason": null,
        "assigned_at": "2026-03-10 23:41:45",
        "removed_at": null,
        "delivery_method": "Kurier DPD",
        "carrier_code": null,
        "courier_code": null
      }
    ]
  }
}
```

---

### GET /api/v1/picking/batches/{batchId}/products

Zwraca zagregowaną listę produktów do zebrania dla całego batcha.

**Response:**
```json
{
  "ok": true,
  "data": {
    "products": [
      {
        "id": 1,
        "product_code": "SKU-123",
        "product_name": "Nazwa produktu",
        "total_expected_qty": "6.000",
        "total_picked_qty": "0.000",
        "status": "pending"
      }
    ]
  }
}
```

**Status agregatu:**
- `pending` — nic jeszcze nie zebrano
- `partial` — część zebrana
- `picked` — wszystko zebrane

---

### POST /api/v1/picking/orders/{orderCode}/items/{pakItemId}/picked

Oznacza pozycję zamówienia jako zebraną.

`pakItemId` to `pak_order_item_id` z tabeli `pak_order_items`.

**Request:** brak body

**Response:**
```json
{
  "ok": true,
  "data": {
    "picking": {
      "order_code": "1873329",
      "pak_item_id": 1251,
      "status": "picked",
      "order_status": "picked"
    }
  }
}
```

`order_status` = `picked` gdy wszystkie pozycje zamówienia są zebrane, `assigned` gdy jeszcze nie.

**Logika:**
1. Ustawia `picked_qty = expected_qty`, `status = picked`
2. Sprawdza czy wszystkie pozycje zamówienia są picked
3. Jeśli tak — ustawia `picking_batch_orders.status = picked`
4. Przebudowuje agregaty `picking_batch_items`
5. Loguje event `item_picked`

---

### POST /api/v1/picking/orders/{orderCode}/items/{pakItemId}/missing

Oznacza pozycję jako brakującą. Automatycznie dropuje zamówienie i odpala refill.

**Request:**
```json
{
  "reason": "brak na magazynie"
}
```

| Pole | Typ | Wymagane | Opis |
|---|---|---|---|
| `reason` | string | **tak** | Powód braku |

**Response:**
```json
{
  "ok": true,
  "data": {
    "picking": {
      "order_code": "1873329",
      "pak_item_id": 1251,
      "status": "missing"
    }
  }
}
```

**Logika:**
1. Oznacza pozycję jako `missing` z powodem
2. Loguje event `item_missing`
3. Dropuje zamówienie z batcha (`order_dropped`)
4. Automatycznie odpala refill — dobiera nowe zamówienie

---

### POST /api/v1/picking/orders/{orderCode}/drop

Ręczne usunięcie zamówienia z batcha. Automatycznie odpala refill.

**Request:**
```json
{
  "reason": "uszkodzony towar"
}
```

| Pole | Typ | Wymagane | Opis |
|---|---|---|---|
| `reason` | string | **tak** | Powód dropu |

**Response:**
```json
{
  "ok": true,
  "data": {
    "picking": {
      "order_code": "1873329",
      "status": "dropped",
      "reason": "uszkodzony towar"
    }
  }
}
```

---

### POST /api/v1/picking/batches/{batchId}/refill

Ręczne dobranie zamówień do batcha (do targetu).

**Request:** brak body

**Response:**
```json
{
  "ok": true,
  "data": {
    "picking": {
      "refilled": 1,
      "active_orders": 10
    }
  }
}
```

**Logika:**
1. Liczy ile aktywnych zamówień zostało
2. Oblicza ile brakuje do `target_orders_count`
3. Wyklucza wszystkie zamówienia które kiedykolwiek były w tym batchu
4. Wyklucza zamówienia aktywne w innych otwartych batchach
5. Dobiera brakującą liczbę nowych zamówień
6. Całość w transakcji z `SELECT FOR UPDATE`

---

### POST /api/v1/picking/batches/{batchId}/close

Zamyka batch. Możliwe tylko gdy wszystkie aktywne zamówienia mają status `picked`.

**Request:** brak body

**Response:**
```json
{
  "ok": true,
  "data": {
    "picking": {
      "batch_id": 4,
      "status": "completed"
    }
  }
}
```

**Błędy:**
- `400` — są zamówienia ze statusem `assigned` (nie wszystko zebrane)
- `400` — batch nie należy do operatora

---

## Bezpieczeństwo

- Wszystkie endpointy wymagają bearer tokenu
- Każda akcja mutująca sprawdza czy batch należy do operatora (`user_id`) i stanowiska (`station_id`)
- `open` i `refill` używają transakcji MySQL z `SELECT FOR UPDATE` — zabezpieczenie przed wyścigami

---

## Events (picking_events)

| event_type | Kiedy |
|---|---|
| `batch_opened` | Otwarcie batcha |
| `item_picked` | Zebranie pozycji |
| `item_missing` | Brak pozycji |
| `order_dropped` | Drop zamówienia (manual lub po missing) |
| `batch_refilled` | Dobranie nowych zamówień |
| `batch_closed` | Zamknięcie batcha |

Każdy event zawiera w `payload_json`:
- `batch_id`
- `user_id`
- kontekstowe pola zależne od typu eventu

---

## Reguły biznesowe

1. Jeden operator = jeden otwarty batch naraz
2. Zamówienie nie może być w dwóch otwartych batchach jednocześnie
3. Resolver decyduje o grupie kurierskiej — nie surowy `delivery_method`
4. Brak na pozycji = automatyczny drop zamówienia
5. Drop = automatyczny refill
6. Dropped zamówienie nigdy nie wraca do tego samego batcha
7. Batch można zamknąć tylko gdy wszystkie aktywne zamówienia są picked
8. `reason` jest obowiązkowy dla `missing` i `drop`
9. Agregaty `picking_batch_items` są przebudowywane po każdej akcji
10. Agregaty uwzględniają tylko zamówienia ze statusem innym niż `dropped`

---

## Heartbeat

### POST /api/v1/heartbeat

Podtrzymuje aktywność sesji operatora. Kotlin wywołuje co 60 sekund.

**Request:** brak body

**Response:**
```json
{
  "ok": true,
  "data": {
    "heartbeat": {
      "picking_batch_id": 12,
      "packing_session_id": null,
      "ts": "2026-03-11 10:00:00"
    }
  }
}
```

Jeśli operator nie ma żadnej aktywnej sesji zwraca:
```json
{
  "ok": true,
  "data": {
    "heartbeat": {
      "status": "no_active_session",
      "ts": "2026-03-11 10:00:00"
    }
  }
}
```

---

## Abandon

### POST /api/v1/picking/batches/{batchId}/abandon

Ręczne porzucenie batcha przez operatora (nagły wypadek, koniec zmiany).

**Request:** brak body, brak wymaganego powodu

**Response:**
```json
{
  "ok": true,
  "data": {
    "picking": {
      "batch_id": 12,
      "status": "abandoned"
    }
  }
}
```

**Błędy:**
- `400` — batch nie istnieje
- `400` — batch nie należy do operatora
- `400` — batch nie jest open

**Co się dzieje z zamówieniami:**
Zamówienia z abandoned batcha automatycznie wracają do puli — `getOrderCodesInOpenBatches()` filtruje tylko po `pb.status = 'open'`. Inny operator może je natychmiast dobrać.

---

## Mechanizm timeout (cron)

Skrypt `bin/abandon_stale_sessions.php` odpala się co minutę przez cron.

Warunki abandon:
- batch status = `open`
- `last_seen_at IS NULL` i batch otwarty > 5 minut (operator nigdy nie wysłał heartbeatu)
- `last_seen_at IS NOT NULL` i ostatni heartbeat > 5 minut temu

Po abandon:
- `picking_batches.status = abandoned`
- `picking_batches.abandoned_at = NOW()`
- event `batch_abandoned` z `reason = heartbeat_timeout` w `picking_events`
- zamówienia wracają do puli

Log: `storage/logs/cron_abandon.log`

---

## Statusy — kompletna lista

**picking_batches.status**
| Status | Opis |
|---|---|
| `open` | Batch aktywny |
| `completed` | Zamknięty przez operatora po zebraniu wszystkiego |
| `abandoned` | Porzucony ręcznie lub przez timeout heartbeatu |

**picking_batch_orders.status**
| Status | Opis |
|---|---|
| `assigned` | Aktywne, w trakcie kompletacji |
| `picked` | Wszystkie pozycje zebrane |
| `dropped` | Wypadło z batcha (missing lub ręczny drop) |

**picking_order_items.status**
| Status | Opis |
|---|---|
| `pending` | Czeka na zebranie |
| `picked` | Zebrana |
| `missing` | Brak na magazynie |

**picking_batch_items.status (agregaty)**
| Status | Opis |
|---|---|
| `pending` | Nic nie zebrano |
| `partial` | Część zebrana |
| `picked` | Wszystko zebrane |

---

## Wymagane działania po stronie Kotlin (tablet)

| Co | Endpoint | Kiedy |
|---|---|---|
| Heartbeat | `POST /api/v1/heartbeat` | Co 60 sekund gdy batch otwarty |
| Heartbeat po reconnect | `POST /api/v1/heartbeat` | Natychmiast po odzyskaniu połączenia |
| Abandon | `POST /api/v1/picking/batches/{id}/abandon` | Przycisk "porzuć" w UI |

**Ważne dla Kotlina:**
- heartbeat musi być wysyłany agresywnie po reconnect — 5 minut to bufor na krótkie zerwania
- przy stracie połączenia > 5 minut batch zostanie abandoned przez cron — po reconnect Kotlin powinien sprawdzić `GET /api/v1/picking/batches/current` czy batch nadal istnieje
- jeśli `current` zwróci null — batch został abandoned, operator musi otworzyć nowy

========================================
FILE: docs/system/01_system_context.md
========================================
# 01. Kontekst systemu

## Status
POTWIERDZONE + PLANOWANE

## Cel systemu
Celem systemu jest obsługa procesu:
- importu zamówień do bazy lokalnej,
- logowania operatora,
- wyboru stanowiska,
- wyboru grupy kurierskiej,
- kompletacji produktów,
- pakowania zamówienia,
- wygenerowania listu przewozowego i etykiety,
- wydruku etykiety,
- zapisania zdarzeń operacyjnych.

## Środowisko techniczne
POTWIERDZONE:
- PHP 7.2
- MySQL 5.7
- serwer HTTP z rewrite do `/api/v1`
- aplikacja docelowa: tablet / Kotlin
- projekt działa bez nowoczesnego frameworka typu Laravel/Symfony
- trzeba zachować kompatybilność z PHP 7.2

## Obecny stan projektu
POTWIERDZONE:
- aktywny projekt został odchudzony
- stary system został przeniesiony do `starysystem/`
- w aktywnym projekcie zostawiono:
  - bootstrap,
  - konfigurację,
  - DB layer,
  - importer i repo importowe,
  - nową strukturę katalogów API/module,
  - nowe migracje dla auth/shipping/picking/packing/audit

## Zachowane elementy krytyczne
POTWIERDZONE:
- `app/bootstrap.php`
- `app/Lib/Db.php`
- `app/Services/ImportState.php`
- `app/Services/ImporterMasterV2.php`
- `app/Services/BaselinkerBatchReader.php`
- `app/Services/SubiektReaderV2.php`
- `app/Services/FirebirdEUReader.php`
- `app/Services/OrderRepositoryV2.php`
- `app/Services/LegacyAuctionPhotoMap.php`
- `bin/import_orders_master_v2.php`

## Zewnętrzne integracje
POTWIERDZONE lub PLANOWANE:
- BaseLinker
- Allegro API
- InPost ShipX
- DPD API
- DHL API
- GLS API
- ORLEN / Packeta API
- źródła importu:
  - MSSQL / Subiekt
  - Firebird EU
  - BaseLinker

## Docelowy tryb działania
PLANOWANE:
- dziś: tryb zintegrowany `integrated`
  - ten sam operator może kompletować i pakować
- przyszłość: rozdzielenie ról
  - komisjoner zbiera,
  - pakowacz pakuje

## Kluczowa zasada dla nowych programistów
Nie wolno zakładać, że:
- `menu_group == label_provider`
- `delivery_method` samo w sobie wystarcza do logiki etykiety
- stare statusy `pak_orders.status` są już finalnie uzgodnione
- logika starego systemu przeglądarkowego jest wiążąca dla nowego API

========================================
FILE: docs/system/02_workflow_target.md
========================================
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

========================================
FILE: docs/system/README.md
========================================
# System pakowania LED-ONE - dokumentacja główna

Ta dokumentacja ma służyć tak, żeby nowy programista po wejściu do projektu:
- nie zgadywał jak działa system,
- nie zgadywał jak wygląda API,
- nie zgadywał jak wygląda baza,
- nie zgadywał które elementy są już zaimplementowane,
- nie zgadywał które elementy są dopiero planowane.

## Zasada dokumentacji
Każda rzecz musi mieć jeden z trzech statusów:
- `POTWIERDZONE` - wynika z aktualnego kodu, bazy albo ustaleń biznesowych,
- `PLANOWANE` - ustalony kontrakt docelowy do wdrożenia,
- `DECYZJA_WYMAGANA` - temat nierozstrzygnięty i nie wolno go implementować „na czuja”.

## Część ręczna
- `01_system_context.md`
- `02_workflow_target.md`
- `03_api_contract.md`
- `04_database_contract.md`
- `05_shipping_and_labels.md`
- `06_implementation_rules.md`

## Część generowana z projektu i bazy
- `generated/01_inventory.md`
- `generated/02_routes.md`
- `generated/03_db_schema.md`
- `generated/04_carrier_groups.md`
- `generated/05_shipping_map.md`
- `generated/06_stations.md`
- `generated/07_migrations.md`

## Najważniejsze zasady biznesowe
1. System ma być API-first.
2. Klientem docelowym jest aplikacja tabletowa Kotlin.
3. Import zamówień ma dalej działać i nie wolno go zepsuć.
4. Stary system przeglądarkowy został odłożony do `starysystem/`.
5. Logika wyboru kuriera ma być rozbita na:
   - `menu_group` - grupa kafelka dla operatora,
   - `shipment_type` - docelowy typ wysyłki,
   - `label_provider` - przez jakie API generujemy etykietę,
   - `label_endpoint` - jaki adapter backendowy ma wykonać wysyłkę.
