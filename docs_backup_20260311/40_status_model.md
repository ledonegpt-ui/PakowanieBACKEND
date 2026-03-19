# Model statusów

## Status
POTWIERDZONE — zweryfikowane w kodzie i bazie 2026-03-11

## pak_orders.status (z importu, tinyint)
- `10` — zamówienie czeka na picking (jedyne które wchodzi do batchy)
- Inne wartości — stary system, nie używane w nowym workflow
- DECYZJA_WYMAGANA: finalne statusy pak_orders nie są jeszcze uzgodnione

## Picking batch (picking_batches.status)
| Status | Opis |
|---|---|
| `open` | Batch aktywny, operator pracuje |
| `completed` | Zamknięty przez operatora po zebraniu wszystkiego |
| `abandoned` | Porzucony ręcznie lub przez timeout heartbeatu (cron) |

## Picking batch orders (picking_batch_orders.status)
| Status | Opis |
|---|---|
| `assigned` | Aktywne, w trakcie kompletacji |
| `picked` | Wszystkie pozycje zebrane |
| `dropped` | Wypadło z batcha (missing lub ręczny drop) |

## Picking order items (picking_order_items.status)
| Status | Opis |
|---|---|
| `pending` | Czeka na zebranie |
| `picked` | Zebrana |
| `missing` | Brak na magazynie |

## Picking batch items — agregaty (picking_batch_items.status)
| Status | Opis |
|---|---|
| `pending` | Nic nie zebrano |
| `partial` | Część zebrana |
| `picked` | Wszystko zebrane |

## Packing session (packing_sessions.status)
| Status | Opis |
|---|---|
| `open` | Sesja aktywna |
| `completed` | Zakończona po wygenerowaniu etykiety i packing/finish |
| `cancelled` | Anulowana przez operatora |
| `abandoned` | Porzucona przez cron po bezczynności |

## Package (packages.status)
| Status | Opis |
|---|---|
| `pending` | Utworzona, etykieta nie wygenerowana |
| `not_requested` | Default przy tworzeniu |
| `ok` | Etykieta wygenerowana poprawnie |
| `error` | Błąd generowania |

## Package label (package_labels.label_status)
| Status | Opis |
|---|---|
| `ok` | Etykieta gotowa, plik zapisany |
| `pending` | Wygenerowano numer ale brak pliku PDF |
| `error` | Błąd |

## Tryb workflow (packing_sessions.workflow_mode / picking_batches.workflow_mode)
| Tryb | Opis |
|---|---|
| `integrated` | Ten sam operator kompletuje i pakuje — aktualny tryb |
| `separated` | Komisjoner i pakowacz to różne osoby — planowane na przyszłość |
