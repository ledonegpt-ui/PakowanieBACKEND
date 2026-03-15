# Stan systemu

## Co faktycznie jest dziś w repo

Repo zawiera **dwa równoległe sposoby działania**:

1. **nowy modułowy backend `/api/v1`**
   - `Auth`
   - `Stations`
   - `Carriers`
   - `Picking`
   - `Packing`
   - `Shipping`
   - `Heartbeat`

2. **starszy plikowy flow w `api/*.php` + stare widoki**
   - `queue.php`
   - `order.php`
   - endpointy typu `start_pack.php`, `finish_pack.php`, `scan.php`, `queue.php`

To oznacza, że dokumentacja musi opisywać nie tylko docelową architekturę modułową, ale też **aktywny legacy flow**, dopóki nadal jest obecny w kodzie i wykorzystywany przez stare ekrany.

---

## Architektura modułowa

Nowe API ma układ:

- API
- Controllers
- Services
- Repositories
- MySQL

Bazowy routing jest realizowany przez `/api/v1/index.php`.

---

## Główne źródła danych

### Nagłówki zamówień
- `pak_orders`

### Pozycje zamówień
- `pak_order_items`

### Snapshot pickingu
- `picking_batches`
- `picking_batch_orders`
- `picking_order_items`
- `picking_batch_items`
- `picking_events`

### Snapshot packingu
- `packing_sessions`
- `packing_session_items`

### Wysyłka / etykiety
- `packages`
- `package_labels`
- `shipping_providers`

---

## Aktualny przepływ danych

### Import
- import zapisuje nagłówki do `pak_orders`
- pozycje zapisują się do `pak_order_items`

### Picking
- picking działa na `pak_orders.status = 10`
- przy otwarciu batcha pozycje z `pak_order_items` są kopiowane do `picking_order_items`
- lista zbiorcza do GUI jest budowana w `picking_batch_items`

### Packing
- nowy packing działa sesyjnie
- order może wejść do packingu dopiero po zakończonym pickingu (`picking_batch_orders.status = picked`)
- przy otwarciu sesji pozycje z `pak_order_items` są kopiowane do `packing_session_items`
- zakończenie packingu wymaga istniejącej paczki i poprawnej etykiety

---

## Dwa równoległe workflow

## 1) Modułowy workflow `/api/v1`

### Picking
`pak_orders(status=10)`  
→ open batch  
→ pick / missing / drop  
→ refill  
→ close / abandon

### Packing
picked order  
→ open session  
→ shipping / generate label  
→ finish / cancel

## 2) Legacy workflow `api/*.php`

### Packing
`start_pack.php`  
→ `pack_heartbeat.php`  
→ `finish_pack.php` lub `cancel_pack.php`

Dodatkowo:
- `reprint_label.php`
- `unlock_pack.php`
- `reopen_order.php`
- `scan.php`
- `events.php`
- `api/queue.php`

---

## Statusy legacy w `pak_orders`

W starym flow nadal występują statusy liczbowe:

- `10` — NEW
- `40` — PACKING
- `50` — PACKED
- `60` — CANCELLED

Nowy modułowy packing korzysta z własnych tabel sesyjnych, ale nadal zapisuje część pól kompatybilności do `pak_orders`.

---

## Ważne doprecyzowania

### 1. `uom` jest w modelu, ale obecnie źródło daje `NULL`
Model pickingu i packingu przewiduje `uom`, jednak bieżące zapytania do `pak_order_items` pobierają:
- `NULL AS uom`

W praktyce oznacza to, że dziś agregacja działa głównie po:
- `subiekt_tow_id`

a nie po realnym `subiekt_tow_id + uom`, dopóki `uom` nie zacznie być zasilane w źródle.

### 2. Parametr route `{orderId}` nie zawsze znaczy ID liczbowe
W nowym packingu i shippingu parametr route:
- `{orderId}`

w praktyce niesie:
- `order_code`

### 3. Root aplikacji nie jest pełnym wejściem do nowego UI
`/index.php` zwraca tylko prosty komunikat tekstowy. W repo nadal istnieją osobno stare ekrany:
- `queue.php`
- `order.php`

---

## Rekomendacja dokumentacyjna

Od tego momentu dokumentacja powinna rozróżniać:

- **modułowy backend `/api/v1`**
- **legacy file endpoints `api/*.php`**
- **stare widoki `queue.php` / `order.php`**

Dopiero po fizycznym usunięciu starego flow można uprościć docs do samego `/api/v1`.
