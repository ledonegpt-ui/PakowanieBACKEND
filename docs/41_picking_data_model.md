# Picking — data model

## Cel

Dokument opisuje aktualny model danych używany przez moduł pickingu po wdrożeniu obsługi `package_mode` oraz sesyjnego `picking_batch_size` na poziomie stanowiska, sesji i batcha.

---

## Tabele powiązane

### Sesje i stanowiska

- `stations`
- `user_station_sessions`

### Picking

- `picking_batches`
- `picking_batch_orders`
- `picking_order_items`
- `picking_batch_items`
- `picking_events`

### Źródła wejściowe

- `pak_orders`
- `pak_order_items`
- `product_size_map`

---

## stations

Tabela stanowisk fizycznych.

### Kluczowe pola

- `id`
- `station_code`
- `station_name`
- `printer_ip`
- `printer_name`
- `is_active`
- `package_mode_default`

### package_mode_default

Dozwolone wartości:

- `small`
- `large`

Znaczenie:

- jest to domyślny tryb pracy stanowiska
- jest kopiowany do sesji operatora przy logowaniu
- nie zmienia się przy ręcznym przełączeniu operatora w GUI

### Domyślna konfiguracja

- stanowiska `1–6` → `small`
- stanowiska `7–9` → `large`
- stanowiska `10–11` → `small`

---

## user_station_sessions

Tabela aktywnych i historycznych sesji operatorów na stanowiskach.

### Kluczowe pola

- `id`
- `user_id`
- `station_id`
- `session_token`
- `workflow_mode`
- `package_mode`
- `started_at`
- `last_seen_at`
- `ended_at`
- `is_active`

### package_mode

Dozwolone wartości:

- `small`
- `large`

Znaczenie:

- opisuje bieżący tryb pracy stanowiska dla danej sesji
- jest używany przez picking do otwierania nowych batchy
- może być ręcznie przełączony w GUI
- zmiana dotyczy tylko tej jednej sesji

### Reguła inicjalizacji

Przy logowaniu operatora:

- `user_station_sessions.package_mode = stations.package_mode_default`

---

## picking_batches

Nagłówek batcha pickingu.

### Kluczowe pola

- `id`
- `batch_code`
- `carrier_key`
- `package_mode`
- `user_id`
- `station_id`
- `status`
- `workflow_mode`
- `selection_mode`
- `target_orders_count`
- `started_at`
- `completed_at`
- `abandoned_at`
- `last_seen_at`
- `created_at`
- `updated_at`

### package_mode

Dozwolone wartości:

- `small`
- `large`

Znaczenie:

- zapisuje tryb, dla którego batch został otwarty
- pozwala jednoznacznie odróżnić np.:
  - `dpd + small`
  - `dpd + large`

### Reguła tworzenia

Przy `createBatch(...)`:

- `package_mode` jest kopiowany z bieżącej sesji operatora
- refill korzysta z `package_mode` zapisanego już w batchu

### selection_mode

Dozwolone wartości:

- `cutoff`
- `cutoff_cluster`
- `emergency_single`

### status

Dozwolone wartości:

- `open`
- `completed`
- `abandoned`

---

## picking_batch_orders

Ordery przypisane do batcha.

### Kluczowe pola

- `id`
- `batch_id`
- `order_code`
- `status`
- `drop_reason`
- `assigned_at`
- `removed_at`

### status

Dozwolone wartości:

- `assigned`
- `picked`
- `dropped`

### Uwagi

- `dropped` pozostaje w historii batcha
- aktywne listy API nie pokazują orderów `dropped`
- refill nie może ponownie dodać orderu, który już był w tym samym batchu

---

## picking_order_items

Snapshot itemów operacyjnych per order w batchu.

### Kluczowe pola

- `id`
- `batch_order_id`
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
- `updated_by_user_id`
- `updated_at`
- `created_at`

### status

Dozwolone wartości:

- `pending`
- `picked`
- `missing`

### Znaczenie modelu

To nie jest odczyt live z `pak_order_items`.

To jest:

- snapshot wykonany przy `openBatch()`
- oraz przy kolejnych `refill()`

### Identyfikacja produktu

#### Pozycje zmapowane

Główna tożsamość produktu:

- `subiekt_tow_id`
- dodatkowo `uom`

API utrzymuje też:

- `product_code = string(subiekt_tow_id)`

#### Fallback legacy

Jeśli nie ma poprawnego `subiekt_tow_id`:

- `is_unmapped = 1`
- `product_code = legacy:{pak_order_item_id}`

To zabezpiecza przed błędnym sklejeniem pozycji niezmapowanych.

---

## picking_batch_items

Zagregowana lista produktów dla GUI.

### Kluczowe pola

- `id`
- `batch_id`
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
- `qty_breakdown_json`
- `order_breakdown_json`
- `created_at`
- `updated_at`

### status

Dozwolone wartości:

- `pending`
- `partial`
- `picked`

### Klucz agregacji

Docelowo agregacja działa po:

- `subiekt_tow_id`
- `uom`

Fallback dla pozycji legacy działa tak, aby różne rekordy bez mapowania nie połączyły się w jeden wspólny produkt.

### Uwaga o `uom`

Model i agregacja obsługują `uom`, ale aktualne źródło `pak_order_items` nadal oddaje:

- `NULL AS uom`

Czyli dziś `uom` istnieje głównie jako element modelu zgodnego z przyszłym rozszerzeniem danych.

---

## picking_events

Log audytowy działań w batchu.

### Kluczowe pola

- `id`
- `batch_id`
- `batch_order_id`
- `order_item_id`
- `event_type`
- `event_message`
- `payload_json`
- `created_by_user_id`
- `created_at`

### Typowe eventy

- `batch_opened`
- `batch_refilled`
- `selection_mode_changed`
- `item_picked`
- `item_missing`
- `order_dropped`
- `batch_closed`
- `batch_abandoned`

### package_mode w eventach

Dla zdarzeń związanych z doborem batcha payload może zawierać:

- `carrier_key`
- `package_mode`
- `selection_mode`

---

## product_size_map

Tabela klasyfikacji rozmiarów produktów.

### Kluczowe pola

- `subiekt_tow_id`
- `subiekt_symbol`
- `name`
- `subiekt_desc`
- `size_status`

### Dozwolone wartości size_status

- `small`
- `large`
- `other`
- `NULL`

### Znaczenie `other`

`other` jest neutralne:

- nie wymusza `large`
- nie blokuje klasyfikacji całego zamówienia jako `small`

### Znaczenie `NULL`

Brak klasyfikacji produktu powoduje, że zamówienie wpada do:

- `unknown`

---

## Wyliczanie package_mode zamówienia

Wyliczenie odbywa się z `pak_order_items`
po joinie:

- `pak_order_items.subiekt_tow_id`
- `product_size_map.subiekt_tow_id`

### Reguły

#### `large`

Jeśli choć jeden produkt ma:

- `size_status = large`

wynik zamówienia:

- `large`

#### `small`

Jeśli wszystkie sklasyfikowane pozycje mają:

- `small`
- albo `other`

wynik zamówienia:

- `small`

#### `unknown`

Jeśli choć jedna pozycja:

- nie ma `subiekt_tow_id`
- albo nie ma rekordu w `product_size_map`
- albo ma `size_status = NULL`

wynik zamówienia:

- `unknown`

### Ważna reguła operacyjna

`unknown` nie jest wpuszczane do automatycznego pickingu.

---

## Filtry doboru batcha

Nowe ordery są dobierane jednocześnie po:

- `carrier_key`
- `package_mode`

Czyli selekcja logicznie działa na parze:

- `(carrier_key, package_mode)`

### Przykłady

- `inpost + small`
- `dpd + large`

---

## Relacje logiczne

### stanowisko → sesja

- `stations.id = user_station_sessions.station_id`

### sesja → batch

- sesja operatora definiuje domyślny `package_mode` dla nowo otwieranego batcha

### batch → ordery

- `picking_batches.id = picking_batch_orders.batch_id`

### batch_order → items

- `picking_batch_orders.id = picking_order_items.batch_order_id`

### batch → agregaty

- `picking_batches.id = picking_batch_items.batch_id`

---

## Wnioski projektowe

Najważniejsze po wdrożeniu:

- tryb pracy stanowiska jest rozdzielony na:
  - default stanowiska
  - bieżący tryb sesji
- batch ma własny zapisany `package_mode`
- rozmiar zamówienia jest liczony backendowo z `product_size_map`
- `unknown` jest celowo odcinane od automatycznego pickingu
- GUI powinno pokazywać zarówno:
  - tryb stanowiska
  - tryb batcha

## Aktualizacja 2026-03-25 — picking_batch_size w sesji stacji

Nowe pole sesji operatora / stacji:
- `user_station_sessions.picking_batch_size`

Znaczenie:
- przechowuje domyślną liczbę zamówień pobieranych do nowo otwieranego batcha
- działa dla całej aktywnej sesji stacji
- jest niezależne od `package_mode`

Relacja do istniejących pól:
- `package_mode` nadal rozdziela flow `small` / `large`
- `target_orders_count` jest zapisywany w `picking_batches`
- przy `POST /api/v1/picking/batches/open`:
  - jeśli body zawiera `target_orders_count`, ta wartość ma priorytet
  - jeśli body nie zawiera `target_orders_count`, backend bierze wartość z `user_station_sessions.picking_batch_size`
  - jeśli sesja nie ma poprawnej wartości, używany jest fallback z konfiguracji `PICKING_BATCH_SIZE`

Skutek architektoniczny:
- sesja operatora definiuje domyślny `package_mode` dla nowo otwieranego batcha
- sesja operatora definiuje też domyślny `picking_batch_size` dla nowo otwieranego batcha
- batch po utworzeniu zachowuje własny zapisany `package_mode`
- batch po utworzeniu zachowuje własny zapisany `target_orders_count`

