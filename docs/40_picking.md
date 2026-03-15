# Picking

## Status

Dokument opisuje **obecne zachowanie kodu**, a nie stan docelowy.

Picking w repo jest wdrożony i obsługuje:

- otwieranie batcha
- dobór zamówień wg `carrier_key`
- trzy tryby `selection_mode`
- item-level `picked`
- item-level `missing`
- order-level `drop`
- refill
- close
- abandon
- log zdarzeń
- widok zagregowanych produktów

---

## Architektura

Picking jest zorganizowany w warstwach:

- `PickingBatchesController`
- `PickingOrdersController`
- `PickingBatchService`
- `PickingBatchRepository`

Przepływ:
- API
- Controller
- Service
- Repository
- MySQL

---

## Tabele używane przez picking

### Tabele modułu
- `picking_batches`
- `picking_batch_orders`
- `picking_order_items`
- `picking_batch_items`
- `picking_events`

### Tabele źródłowe
- `pak_orders`
- `pak_order_items`

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

## Źródło danych i snapshot

### Nagłówek zamówienia
Źródłem jest:
- `pak_orders`

### Pozycje do pickingu
Źródłem są:
- `pak_order_items`

Przy otwarciu batcha oraz przy refill pozycje są kopiowane do:
- `picking_order_items`

To oznacza, że `picking_order_items` jest **snapshotem operacyjnym**, a nie widokiem liczonym live z `pak_order_items`.

---

## Model produktu

Dla pozycji poprawnie zmapowanych głównym identyfikatorem produktu jest:
- `subiekt_tow_id`

Dla kompatybilności API nadal zwraca również:
- `product_code`
- `product_name`

### Reguła identyfikacji
- dla pozycji zmapowanych `product_code = string(subiekt_tow_id)`
- dla fallbacków legacy `product_code = legacy:{pak_order_item_id}`

### Dane produktu przechowywane w snapshotach
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

## Agregacja produktów

### Docelowa zasada
Agregacja w `picking_batch_items` działa po:
- `subiekt_tow_id`
- `uom`

### Ważne doprecyzowanie
Bieżące odczyty z `pak_order_items` pobierają:
- `NULL AS uom`

W praktyce oznacza to, że aktualnie agregacja działa głównie po:
- `subiekt_tow_id`

`uom` jest już częścią modelu i klucza agregacji, ale dziś źródło jeszcze go realnie nie zasila.

### Fallback dla pozycji niezmapowanych
Jeżeli pozycja nie ma poprawnego `subiekt_tow_id`, agregacja używa technicznego klucza per pozycja legacy, tak aby różne niezmapowane rekordy nie skleiły się błędnie w jeden produkt.

---

## Otwieranie batcha

Endpoint:
- `POST /api/v1/picking/batches/open`

### Wymagane pole
- `carrier_key`

### Pola opcjonalne
- `selection_mode`
- `target_orders_count`

### Domyślne zachowanie
Jeżeli `selection_mode` nie jest podane:
- używany jest `cutoff_cluster`

### Zachowanie przy błędnym `selection_mode`
Jeżeli klient wyśle nieobsługiwany `selection_mode`:
- serwis robi fallback do `cutoff`

To jest ważne, bo **default przy braku pola** i **fallback przy błędnej wartości** to nie to samo.

### Zachowanie `target_orders_count`
- dla `emergency_single` target jest wymuszany do `1`
- jeżeli target jest `< 1` albo `> 50`, serwis ustawia `10`

### Ograniczenie sesyjne
- operator może mieć tylko jeden otwarty batch naraz
- jeśli już ma otwarty batch, `open` zwraca istniejący batch zamiast tworzyć nowy

---

## Tryby doboru batcha

Dozwolone:
- `cutoff`
- `cutoff_cluster`
- `emergency_single`

### `cutoff`
Klasyczny dobór FIFO:
- zamówienia z `pak_orders.status = 10`
- sortowanie:
  - `imported_at ASC`
  - `order_code ASC`

### `cutoff_cluster`
Tryb domyślny.

Logika:
1. system wybiera order kotwiczący tak jak w `cutoff`
2. pobiera szerszą pulę kandydatów
3. szuka orderów z częścią wspólnych produktów
4. dopasowanie działa po kluczu:
   - `subiekt_tow_id + uom`
5. jeśli podobnych orderów jest za mało, batch jest dopełniany zwykłym cutoffem

### `emergency_single`
Tryb awaryjny:
- batch bierze tylko jedno zamówienie
- sortowanie:
  - `courier_priority DESC`
  - `imported_at ASC`
  - `order_code ASC`

---

## Wykluczanie orderów przy doborze

Przy `open` i `refill` system wyklucza:

- ordery już aktywne w innych otwartych batchach
- przy refill dodatkowo wszystkie ordery, które były już kiedyś użyte w tym samym batchu

Dzięki temu refill nie dobiera ponownie orderu, który wcześniej wypadł z batcha.

---

## Warstwa `orders`

Endpoint:
- `GET /api/v1/picking/batches/{batchId}/orders`

To jest warstwa operacyjna do działań:
- item-level
- order-level

### Ważne
Payload `orders` **nie pokazuje orderów `dropped`**.  
Stats batcha nadal je liczą, ale lista `orders` zwraca tylko aktywne ordery.

---

## Warstwa `products`

Endpoint:
- `GET /api/v1/picking/batches/{batchId}/products`

To jest warstwa do renderowania głównej listy zbiorczej.

Zwraca między innymi:
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

### Status agregatu
- `pending` — wszystkie składowe są pending
- `picked` — wszystkie składowe są picked
- `partial` — miks pending / picked / missing albo całość missing

### Rekomendacja dla GUI
Główny ekran pickingu powinien renderować listę z:
- `products`

a nie liczyć własnej agregacji po `orders`.

---

## Akcje operatora

## `picked`
Endpoint:
- `POST /api/v1/picking/orders/{orderId}/items/{itemId}/picked`

Logika:
- działa item-level
- ustawia `picked_qty = expected_qty`
- ustawia status itemu na `picked`
- przebudowuje agregaty
- jeżeli order nie ma już itemów `pending`, order przechodzi do `picked`

## `missing`
Endpoint:
- `POST /api/v1/picking/orders/{orderId}/items/{itemId}/missing`

Logika:
- działa item-level
- wymaga niepustego `reason`
- ustawia status itemu na `missing`
- zapisuje `missing_reason`
- przebudowuje agregaty
- **nie dropuje automatycznie całego orderu**

## `drop`
Endpoint:
- `POST /api/v1/picking/orders/{orderId}/drop`

Logika:
- działa order-level
- wymaga niepustego `reason`
- ustawia order jako `dropped`
- przebudowuje agregaty
- **natychmiast uruchamia refill w tej samej transakcji**

To jest ważna zmiana względem starszych opisów: po manualnym dropie refill nie jest tylko „opcjonalnym ruchem GUI”, ale częścią serwisu.

---

## Refill

Endpoint:
- `POST /api/v1/picking/batches/{batchId}/refill`

Refill:
- działa tylko dla batcha `open`
- liczy liczbę aktywnych orderów
- porównuje ją z `target_orders_count`
- dobiera brakujące ordery wg aktualnego `selection_mode`
- kopiuje ich pozycje do `picking_order_items`
- przebudowuje `picking_batch_items`

### Co znaczy „aktywny order”
Aktywne są wszystkie ordery poza:
- `dropped`

### `selection_mode` przy refill
Refill używa trybu zapisanego w batchu:
- po `open`
- albo po `selection-mode`

---

## Zmiana trybu w locie

Endpoint:
- `POST /api/v1/picking/batches/{batchId}/selection-mode`

Logika:
- przyjmuje tylko:
  - `cutoff`
  - `cutoff_cluster`
  - `emergency_single`
- zapisuje nowy `selection_mode`
- nie przebudowuje już przypisanych orderów
- wpływa dopiero na kolejne refill

---

## Zamknięcie batcha

Endpoint:
- `POST /api/v1/picking/batches/{batchId}/close`

Warunek:
- batch można zamknąć tylko wtedy, gdy nie ma już orderów `assigned`

To oznacza, że:
- wszystkie aktywne ordery muszą być rozliczone jako `picked`
- dropped nie blokują zamknięcia

Response status:
- `completed`

---

## Porzucenie batcha

Endpoint:
- `POST /api/v1/picking/batches/{batchId}/abandon`

Logika:
- działa tylko dla batcha `open`
- kończy batch statusem `abandoned`
- zapisuje event `batch_abandoned`

---

## Event log

Tabela:
- `picking_events`

Najważniejsze typy eventów:
- `batch_opened`
- `batch_refilled`
- `selection_mode_changed`
- `item_picked`
- `item_missing`
- `order_dropped`
- `batch_closed`
- `batch_abandoned`

Payload eventów zawiera już rozszerzone dane produktu, m.in.:
- `subiekt_tow_id`
- `product_code`
- `uom`
- `is_unmapped`

---

## Najważniejsze różnice względem starych docs

- dokumentacja musi rozróżniać default `cutoff_cluster` od fallbacku błędnej wartości do `cutoff`
- `missing` nie dropuje automatycznie orderu
- manualny `drop` robi refill od razu
- `orders` nie pokazuje orderów dropped
- `uom` istnieje w modelu, ale dziś jest w praktyce puste w danych źródłowych
