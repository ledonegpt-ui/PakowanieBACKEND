# Model statusów

## Główna zasada

Projekt używa kilku niezależnych modeli statusów:
1. legacy `pak_orders`
2. snapshot pickingu
3. snapshot packingu
4. dane shipping / label

Nie wolno ich mieszać.

---

## Legacy `pak_orders`

- `10` — NEW
- `40` — PACKING
- `50` — PACKED
- `60` — CANCELLED

---

## Picking batch

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

Ważne:
- `missing` nie oznacza automatycznie `dropped`
- `dropped` pojawia się przy osobnej akcji manual drop order

### `picking_batch_items.status`
- `pending`
- `partial`
- `picked`

---

## Packing

### `packing_sessions.status`
- `open`
- `completed`
- `cancelled`
- `abandoned`

`packing_session_items` nie muszą mieć osobnego statusu stringowego; stan wynika z ilości:
- `expected_qty`
- `packed_qty`

---

## Shipping / label readiness

Techniczna gotowość do zamknięcia nowego packingu wynika także z istnienia:
- `packages`
- `package_labels`
