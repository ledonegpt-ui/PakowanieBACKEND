# Picking API

## Status

Dokument opisuje aktualne endpointy pickingu w:
- `/api/v1`

oraz realne zachowanie serwisu po ostatnich zmianach.

---

## Base path

```text
/api/v1
```

## Autoryzacja

Wszystkie endpointy pickingu poza `health` wymagają autoryzacji nowego API.

Typowy nagłówek:

```http
Authorization: Bearer {token}
```

---

## Model działania

Picking działa na dwóch poziomach:

## 1. `orders`
Warstwa operacyjna do akcji:
- `picked`
- `missing`
- `drop`

## 2. `products`
Warstwa zbiorcza do GUI:
- agregacja po produkcie
- lista główna powinna być renderowana właśnie stąd

Rekomendacja:
- GUI nie powinno liczyć własnych agregatów z `orders`
- GUI powinno renderować listę główną z `products`

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

Dozwolone:
- `cutoff`
- `cutoff_cluster`
- `emergency_single`

### Default przy braku pola
Jeżeli `selection_mode` nie jest wysłane:
- używany jest `cutoff_cluster`

### Fallback przy błędnej wartości
Jeżeli klient wyśle nieobsługiwany `selection_mode`:
- serwis zrobi fallback do `cutoff`

### `cutoff`
FIFO:
- źródło: `pak_orders.status = 10`
- sortowanie:
  - `imported_at ASC`
  - `order_code ASC`

### `cutoff_cluster`
Tryb domyślny:
- pierwszy order pochodzi z FIFO
- kolejne ordery są dobierane po podobieństwie produktów
- matching działa po:
  - `subiekt_tow_id + uom`
- jeśli podobnych orderów jest za mało, batch jest dopełniany zwykłym cutoffem

### `emergency_single`
Tryb awaryjny:
- bierze dokładnie jedno zamówienie
- sortowanie:
  - `courier_priority DESC`
  - `imported_at ASC`
  - `order_code ASC`

---

## `POST /api/v1/picking/batches/open`

Otwiera nowy batch operatora.

### Request

```json
{
  "carrier_key": "inpost",
  "target_orders_count": 10,
  "selection_mode": "cutoff_cluster"
}
```

### Zasady
- `carrier_key` jest wymagane
- `selection_mode` jest opcjonalne
- `target_orders_count` jest opcjonalne
- dla `emergency_single` target jest wymuszany do `1`
- jeśli target jest poza zakresem `1..50`, serwis ustawia `10`
- operator może mieć tylko jeden otwarty batch naraz
- jeżeli batch już istnieje, endpoint zwraca istniejący

### Dobór orderów
Serwis wyklucza:
- ordery aktywne w innych otwartych batchach

### Response
Response zwraca pełny model:
- `batch`
- `orders`
- `products`

---

## `GET /api/v1/picking/batches/current`

Zwraca aktualny otwarty batch operatora.

### Response
- jeśli batch istnieje: pełny payload pickingu
- jeśli batch nie istnieje: `null`

---

## `GET /api/v1/picking/batches/{batchId}`

Zwraca pełny szczegół batcha:
- `batch`
- `orders`
- `products`

---

## `POST /api/v1/picking/batches/{batchId}/refill`

Dobiera kolejne ordery do batcha.

### Logika
Refill:
- działa tylko dla batcha `open`
- bierze `target_orders_count`
- liczy aktywne ordery
- dobiera brakujące rekordy według aktualnego `selection_mode`
- wyklucza ordery już użyte wcześniej w tym samym batchu
- wyklucza ordery aktywne w innych otwartych batchach

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

Zmienia tryb doboru batcha.

### Request

```json
{
  "selection_mode": "emergency_single"
}
```

### Zasady
- endpoint przyjmuje tylko:
  - `cutoff`
  - `cutoff_cluster`
  - `emergency_single`
- nie przebudowuje aktualnie przypisanych orderów
- wpływa dopiero na kolejne refill

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

---

## `POST /api/v1/picking/batches/{batchId}/close`

Zamyka batch.

### Warunek
Batch można zamknąć tylko wtedy, gdy:
- nie ma już orderów `assigned`

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

Zwraca operacyjny order-level payload pickingu.

### Zawartość
Każdy order zawiera:
- dane orderu
- listę itemów z `picking_order_items`

### Ważne
Lista `orders`:
- pokazuje tylko ordery aktywne
- **nie pokazuje orderów `dropped`**

### Pola itemu
- `id`
- `pak_order_item_id`
- `subiekt_tow_id`
- `subiekt_symbol`
- `subiekt_desc`
- `source_name`
- `product_code`
- `product_name`
- `uom`
- `is_unmapped`
- `expected_qty`
- `picked_qty`
- `status`
- `missing_reason`

---

## `GET /api/v1/picking/batches/{batchId}/products`

Zwraca zagregowaną listę produktów dla batcha.

### Klucz agregacji
Docelowo:
- `subiekt_tow_id`
- `uom`

### Ważne doprecyzowanie
Aktualne zapytania źródłowe pobierają:
- `NULL AS uom`

więc dziś agregacja działa w praktyce głównie po:
- `subiekt_tow_id`

### Zwracane pola
- `subiekt_tow_id`
- `subiekt_symbol`
- `subiekt_desc`
- `source_name`
- `product_code`
- `product_name`
- `uom`
- `is_unmapped`
- `total_expected_qty`
- `total_picked_qty`
- `total_missing_qty`
- `remaining_qty`
- `status`
- `qty_breakdown`
- `qty_breakdown_label`
- `order_breakdown`

---

## `POST /api/v1/picking/orders/{orderId}/items/{itemId}/picked`

Oznacza konkretny item jako zebrany.

### Request
Brak body.

### Logika
- ustawia `picked_qty = expected_qty`
- ustawia status itemu na `picked`
- przebudowuje agregaty
- gdy order nie ma już itemów `pending`, order przechodzi do `picked`

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

---

## `POST /api/v1/picking/orders/{orderId}/items/{itemId}/missing`

Oznacza pozycję jako brakującą.

### Request

```json
{
  "reason": "brak na półce"
}
```

### Logika
- wymaga niepustego `reason`
- ustawia status itemu na `missing`
- zapisuje `missing_reason`
- przebudowuje agregaty
- nie dropuje automatycznie orderu

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

---

## `POST /api/v1/picking/orders/{orderId}/drop`

Usuwa całe zamówienie z batcha.

### Request

```json
{
  "reason": "missing_items"
}
```

### Logika
- działa na poziomie orderu
- wymaga niepustego `reason`
- ustawia order jako `dropped`
- przebudowuje agregaty
- uruchamia refill od razu w serwisie

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

---

## Eventy

Najważniejsze eventy pickingu:
- `batch_opened`
- `batch_refilled`
- `selection_mode_changed`
- `item_picked`
- `item_missing`
- `order_dropped`
- `batch_closed`
- `batch_abandoned`

---

## Najważniejsze uwagi wdrożeniowe

- default `selection_mode` to `cutoff_cluster`
- błędny `selection_mode` robi fallback do `cutoff`
- `missing` nie dropuje orderu
- `drop` robi refill od razu
- `products` to główne źródło listy dla GUI
- `uom` jest częścią modelu, ale w aktualnym źródle nie jest jeszcze realnie zasilane
