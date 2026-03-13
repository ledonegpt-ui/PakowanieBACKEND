# Picking Data Model

## Status
POTWIERDZONE — zweryfikowane po analizie importu, modelu danych i kodu pickingu

## Model docelowy

System działa w modelu:

- nagłówek zamówienia pochodzi z source i jest zapisywany do `pak_orders`
- pozycje do pickingu pochodzą z Subiekta i są zapisywane do `pak_order_items`

Następnie przy tworzeniu batcha pozycje są kopiowane do:

- `picking_order_items`

Flow:

source → pak_orders  
Subiekt → pak_order_items  
pak_order_items → picking_order_items

---

## Źródło pozycji do pickingu

Pozycje używane przez picking pochodzą wyłącznie z Subiekta, z pozycji dokumentu.

Nie ma już równoległego modelu pozycji:
- `EU-*`
- `BL-*`

Po cleanupie system zapisuje pozycje jako:
- `SUB-*`

---

## Znaczenie `offer_id`

Pole:
- `pak_order_items.offer_id`

Źródło:
- `dok_Pozycja.ob_SyncId` z Subiekta

Semantyka:
- numer oferty
- numer aukcji
- identyfikator oferty marketplace

Przykłady:
- pozycja towarowa → `offer_id` wypełnione
- pozycja wysyłki → `offer_id = NULL`

To pole jest podstawowym łącznikiem pozycji pickingu z marketplace, zdjęciami i dodatkowymi metadanymi.

---

## Znaczenie pól w `pak_order_items`

### Identyfikacja pozycji
- `item_id` — techniczny PK
- `order_code` — identyfikator zamówienia
- `line_key` — identyfikator linii, obecnie `SUB-*`

### Powiązanie z ofertą
- `offer_id` — numer oferty / aukcji z `ob_SyncId`

### Dane subiektowe
- `subiekt_tow_id`
- `subiekt_symbol`
- `name`
- `subiekt_desc`

### Ilości
- `quantity`
- `picked_qty`
- `packed_qty`

---

## Wniosek dla GUI pickingu

GUI pickingu powinno:
- pobierać nagłówek z `pak_orders`
- pobierać pozycje z `pak_order_items` / `picking_order_items`

Czyli frontend pracuje na pozycjach subiektowych zapisanych lokalnie w systemie.

Jeżeli trzeba powiązać pozycję z marketplace lub zdjęciami, należy używać:
- `offer_id`
