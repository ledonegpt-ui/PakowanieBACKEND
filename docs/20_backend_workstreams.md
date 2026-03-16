# Backend workstreams

## Założenie

Repo ma dziś dwa równoległe nurty:
- nowy modułowy backend `/api/v1`
- starszy plikowy flow w `api/*.php` i starych widokach

---

## Workstream 1 — import zamówień

Źródło danych:
- `pak_orders`
- `pak_order_items`

---

## Workstream 2 — picking (`/api/v1`)

Główne tabele:
- `picking_batches`
- `picking_batch_orders`
- `picking_order_items`
- `picking_batch_items`
- `picking_events`

Ważne:
- `picking_batch_orders` jest aktywnie używana
- `missing` działa na poziomie itemu
- manual drop order jest osobną akcją

---

## Workstream 3 — packing (`/api/v1`)

Główne tabele:
- `packing_sessions`
- `packing_session_items`
- `packing_events`

Ważne:
- `finish` nie jest głównym miejscem generowania etykiety
- przed zamknięciem sesji musi istnieć paczka i etykieta

---

## Workstream 4 — shipping (`/api/v1`)

Shipping odpowiada za:
- resolve dostawcy
- adapter przewoźnika
- `packages`
- `package_labels`
- wygenerowanie etykiety

---

## Workstream 5 — heartbeat

Endpointy:
- `POST /api/v1/heartbeat`
- `POST /api/v1/packing/orders/{orderId}/heartbeat`

Global heartbeat jest głównym mechanizmem keepalive.

---

## Workstream 6 — legacy queue i legacy packing

Główne pliki:
- `queue.php`
- `order.php`
- `api/login.php`
- `api/login_queue.php`
- `api/session.php`
- `api/queue.php`
- `api/start_pack.php`
- `api/finish_pack.php`
- `api/cancel_pack.php`
- `api/scan.php`
- `api/events.php`

Legacy flow nadal jest częścią projektu i musi być dokumentowany osobno od nowego API.
