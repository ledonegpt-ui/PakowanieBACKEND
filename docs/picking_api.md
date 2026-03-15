cat > docs/picking_api.md <<'MD'
# Picking API

## Status
AKTUALNE — zgodne z bieżącym działaniem systemu:
- agregacja po `subiekt_tow_id`
- rozszerzony model produktu (`subiekt_symbol`, `subiekt_desc`, `source_name`, `product_name`)
- tryby doboru batcha
- przełączanie trybu w locie
- flow picking -> packing

## Base path

```text
/api/v1
```

## Autoryzacja

Wszystkie endpointy pickingu wymagają:

```http
Authorization: Bearer {token}
```

---

## Model działania

Picking działa na dwóch poziomach:

### 1. `orders`
To jest warstwa operacyjna:
- order-level
- item-level
- służy do akcji:
    - `picked`
    - `missing`
    - `drop`

### 2. `products`
To jest warstwa do renderowania listy zbiorczej:
- agregacja po produkcie magazynowym
- source of truth produktu = `subiekt_tow_id`
- agregacja po:
    - `subiekt_tow_id`
    - `uom`

GUI głównego pickingu powinno renderować listę właśnie z `products`.
GUI nie powinno liczyć własnej agregacji po orderach.

---

## Statusy

### `picking_batches.status`
- `open`
- `completed`
- `abandoned`

### `picking_batch_orders.status`
- `assigned`
- `picked`
- `dropped`

### `picking_order_items.status`
- `pending`
- `picked`
- `missing`

### `picking_batch_items.status`
- `pending`
- `partial`
- `picked`

---

## Tryby doboru batcha

Dozwolone `selection_mode`:
- `cutoff`
- `cutoff_cluster`
- `emergency_single`

Domyślny tryb:
- `cutoff_cluster`

### `cutoff`
FIFO:
- system bierze najstarsze dostępne zamówienia
- sortowanie bazowe po `imported_at ASC, order_code ASC`

### `cutoff_cluster`
Tryb domyślny:
- pierwszy order jest wybierany jak w cutoff
- kolejne ordery są dobierane preferencyjnie po wspólnych produktach
- dopasowanie działa po `subiekt_tow_id + uom`
- jeśli nie uda się dobrać wystarczająco podobnych orderów, system dopełnia batch zwykłym cutoffem

### `emergency_single`
Tryb awaryjny:
- batch bierze jedno zamówienie
- wybór działa po:
    - `courier_priority DESC`
    - następnie `imported_at ASC`
    - następnie `order_code ASC`

---

## Endpointy

## `POST /api/v1/picking/batches/open`

Otwiera nowy batch dla operatora.

### Request

```json
{
  "carrier_key": "inpost",
  "target_orders_count": 10,
  "selection_mode": "cutoff_cluster"
}
```

### Pola requestu

- `carrier_key` — wymagane
- `target_orders_count` — opcjonalne
- `selection_mode` — opcjonalne

### Domyślne zachowanie

Jeżeli `selection_mode` nie jest podane:
- używany jest `cutoff_cluster`

Jeżeli `selection_mode = emergency_single`:
- system wymusza `target_orders_count = 1`

### Response

```json
{
  "ok": true,
  "data": {
    "picking": {
      "batch": {
        "id": 3,
        "batch_code": "BATCH-1773528111-1",
        "carrier_key": "inpost",
        "user_id": 1,
        "station_id": 11,
        "status": "open",
        "workflow_mode": "integrated",
        "selection_mode": "cutoff_cluster",
        "target_orders_count": 3,
        "started_at": "2026-03-14 23:41:51",
        "completed_at": null,
        "abandoned_at": null,
        "active_orders_count": 3,
        "picked_orders_count": 0,
        "dropped_orders_count": 0,
        "total_orders_count": 3
      },
      "orders": [],
      "products": []
    }
  }
}
```

---

## `GET /api/v1/picking/batches/current`

Zwraca aktualny otwarty batch operatora.

### Response
- jeśli operator ma otwarty batch: zwracany jest pełny payload pickingu
- jeśli operator nie ma otwartego batcha: `picking = null`

---

## `GET /api/v1/picking/batches/{batchId}`

Zwraca pełny szczegół batcha:
- `batch`
- `orders`
- `products`

---

## `POST /api/v1/picking/batches/{batchId}/refill`

Dobiera kolejne zamówienia do batcha.

### Logika
Refill:
- bierze aktualny `selection_mode` zapisany w batchu
- wyklucza zamówienia aktywne w innych open batchach
- wyklucza zamówienia już użyte wcześniej w tym samym batchu
- dobiera brakującą liczbę orderów do `target_orders_count`

### Response

```json
{
  "ok": true,
  "data": {
    "picking": {
      "refilled": 2,
      "active_orders": 3
    }
  }
}
```

---

## `POST /api/v1/picking/batches/{batchId}/selection-mode`

Zmienia tryb doboru batcha w locie.

### Request

```json
{
  "selection_mode": "emergency_single"
}
```

### Response

```json
{
  "ok": true,
  "data": {
    "picking": {
      "batch_id": 3,
      "selection_mode": "emergency_single",
      "status": "updated"
    }
  }
}
```

### Ważne
Zmiana:
- zapisuje nowy tryb do batcha
- nie przebudowuje aktualnie przypisanych orderów
- wpływa na kolejne refill

---

## `POST /api/v1/picking/batches/{batchId}/close`

Zamyka batch.

### Warunek
Batch może zostać zamknięty tylko wtedy, gdy:
- nie ma już orderów w statusie `assigned`

### Response

```json
{
  "ok": true,
  "data": {
    "picking": {
      "batch_id": 3,
      "status": "completed"
    }
  }
}
```

---

## `POST /api/v1/picking/batches/{batchId}/abandon`

Porzuca batch.

### Response

```json
{
  "ok": true,
  "data": {
    "picking": {
      "batch_id": 3,
      "status": "abandoned"
    }
  }
}
```

---

## `GET /api/v1/picking/batches/{batchId}/orders`

Zwraca order-level dane robocze do operacji.

### Response — przykład

```json
{
  "ok": true,
  "data": {
    "orders": [
      {
        "id": 9,
        "order_code": "1894352",
        "status": "assigned",
        "drop_reason": null,
        "assigned_at": "2026-03-14 23:41:51",
        "removed_at": null,
        "delivery_method": "Allegro Paczkomaty InPost (Smart)",
        "carrier_code": null,
        "courier_code": null,
        "items": [
          {
            "id": 31,
            "pak_order_item_id": 1,
            "subiekt_tow_id": 3698,
            "subiekt_symbol": "B1643",
            "subiekt_desc": "czarno-złoty PAJĄK zwis POTRÓJNY na żarówke 3 x e27 czarno-złoty",
            "source_name": "ADAPTER WTYCZKA UNIWERSALNA BIAŁA",
            "product_code": "3698",
            "product_name": "ADAPTER WTYCZKA UNIWERSALNA BIAŁA",
            "uom": null,
            "is_unmapped": false,
            "expected_qty": 1,
            "picked_qty": 0,
            "status": "pending",
            "missing_reason": null
          }
        ]
      }
    ]
  }
}
```

### Znaczenie pól itemu

- `subiekt_tow_id` — główny identyfikator produktu
- `subiekt_symbol` — symbol z Subiekta
- `subiekt_desc` — opis z Subiekta
- `source_name` — nazwa źródłowa z `pak_order_items.name`
- `product_code` — alias kompatybilności, zwykle `string(subiekt_tow_id)`
- `product_name` — finalna nazwa do GUI
- `expected_qty` — ilość oczekiwana
- `picked_qty` — ilość zebrana
- `status` — `pending|picked|missing`

---

## `GET /api/v1/picking/batches/{batchId}/products`

Zwraca zagregowaną listę produktów dla batcha.

### Główna zasada
Agregacja działa po:
- `subiekt_tow_id`
- `uom`

### Response — przykład

```json
{
  "ok": true,
  "data": {
    "products": [
      {
        "id": 86,
        "subiekt_tow_id": "4045",
        "subiekt_symbol": "B0192",
        "subiekt_desc": "8W ! VINTAGE GRUSZKA 8W ciepła filament ; AI 199166 ; LL 3376",
        "source_name": "ŻARÓWKA VINTAGE 8W CIEPŁA",
        "product_code": "4045",
        "product_name": "ŻARÓWKA VINTAGE 8W CIEPŁA",
        "uom": null,
        "is_unmapped": false,
        "total_expected_qty": 3,
        "total_picked_qty": 0,
        "total_missing_qty": 0,
        "remaining_qty": 3,
        "status": "pending",
        "qty_breakdown": [3],
        "qty_breakdown_label": "3",
        "order_breakdown": [
          {
            "order_code": "1894352",
            "qty": 3,
            "item_ids": [32],
            "item_count": 1,
            "status_summary": "pending"
          }
        ]
      }
    ]
  }
}
```

### Znaczenie pól produktu

- `subiekt_tow_id` — source of truth produktu
- `subiekt_symbol` — symbol z Subiekta
- `subiekt_desc` — opis z Subiekta
- `source_name` — nazwa źródłowa
- `product_code` — alias kompatybilności
- `product_name` — finalna nazwa do GUI
- `total_expected_qty` — suma oczekiwana
- `total_picked_qty` — suma zebrana
- `total_missing_qty` — suma braków
- `remaining_qty` — ile realnie zostało do zebrania
- `qty_breakdown` — tablica źródłowych ilości, np. `[1,2,6]`
- `qty_breakdown_label` — string do prostego renderu, np. `1+2+6`
- `order_breakdown` — rozbicie per order

### Status agregatu

- `pending` — wszystkie itemy pending
- `picked` — wszystkie itemy picked i brak missing
- `partial` — dowolny miks picked/pending/missing lub wszystko missing

---

## `POST /api/v1/picking/orders/{orderId}/items/{itemId}/picked`

Oznacza konkretny item jako zebrany.

### Request
Brak body.

### Response

```json
{
  "ok": true,
  "data": {
    "picking": {
      "order_id": 9,
      "order_code": "1894352",
      "item_id": 31,
      "pak_item_id": 1,
      "status": "picked",
      "order_status": "assigned"
    }
  }
}
```

### Logika
- działa item-level
- aktualizuje `picked_qty`
- przebudowuje agregaty
- gdy order nie ma już itemów `pending`, może przejść do `picked`

---

## `POST /api/v1/picking/orders/{orderId}/items/{itemId}/missing`

Oznacza pozycję jako brakującą.

### Request

```json
{
  "reason": "brak na półce"
}
```

### Response

```json
{
  "ok": true,
  "data": {
    "picking": {
      "order_id": 9,
      "order_code": "1894352",
      "item_id": 31,
      "pak_item_id": 1,
      "status": "missing"
    }
  }
}
```

### Logika
- działa item-level
- zostawia świadomy brak
- nie usuwa całego ordera z batcha
- przebudowuje agregaty

---

## `POST /api/v1/picking/orders/{orderId}/drop`

Usuwa całe zamówienie z batcha.

### Request

```json
{
  "reason": "missing_items"
}
```

### Response

```json
{
  "ok": true,
  "data": {
    "picking": {
      "order_id": 9,
      "order_code": "1894352",
      "status": "dropped",
      "reason": "missing_items"
    }
  }
}
```

### Logika
- działa order-level
- usuwa całe zamówienie z bieżącego batcha
- przebudowuje agregaty
- uruchamia refill

### Ważne
Kliknięcie `X` w GUI odpowiada właśnie tej operacji.

---

## Relacja z GUI

### GUI pickingu
Powinno:
- renderować główną listę z `products`
- pokazywać:
    - `product_name`
    - `subiekt_symbol`
    - `subiekt_desc`
    - sumę do zebrania
    - breakdown `qty_breakdown_label`
- używać `orders.items` do akcji item-level

### GUI nie powinno
- liczyć własnej agregacji po orderach
- agregować po `offer_id`
- traktować `offer_id` jako identyfikatora magazynowego

---

## Relacja z packing

Typowy flow:
1. operator kończy kompletację
2. batch jest zamykany
3. GUI przechodzi do packingu
4. packing pracuje dalej order-level

---

## Eventy

System loguje między innymi:
- `batch_opened`
- `batch_refilled`
- `selection_mode_changed`
- `item_picked`
- `item_missing`
- `order_dropped`
- `batch_closed`
- `batch_abandoned`

MD
