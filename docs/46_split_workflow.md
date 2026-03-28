# Split Workflow — tryb rozdzielny picker/packer

## Status
ZAIMPLEMENTOWANE — zweryfikowane 2026-03-28

---

## Czym jest tryb split

System obsługuje dwa tryby pracy operatora:

### `integrated` (domyślny)
Jedna osoba zbiera i od razu pakuje.
- operator = picker i packer jednocześnie
- brak koszyków workflow

### `split`
Dwie osoby — zbieracz i pakowacz pracują rozdzielnie.
- picker zbiera zamówienia do koszyka fizycznego
- packer odbiera gotowy koszyk i pakuje zamówienia
- koszyki workflow zarządzają przekazaniem między nimi

---

## Pola sesji operatora (`user_station_sessions`)

| Pole | Typ | Opis |
|---|---|---|
| `workflow_mode` | `integrated` / `split` | Tryb pracy stacji |
| `work_mode` | `picker` / `packer` | Rola operatora w trybie split |
| `package_mode` | `small` / `large` | Rozmiar paczek |

---

## Tabela `workflow_baskets`

Fizyczne koszyki robocze używane w trybie split.

### Kluczowe pola
- `id`
- `basket_no` — numer koszyka (1–20)
- `package_mode` — `small` lub `large`
- `status` — aktualny stan koszyka
- `reserved_batch_id` — batch który zajmuje koszyk
- `reserved_by_user_id` — operator który zarezerwował
- `reserved_at`
- `picked_ready_at`
- `packing_started_at`
- `updated_at`

### Statusy koszyka

| Status | Znaczenie |
|---|---|
| `empty` | Koszyk wolny, gotowy do użycia |
| `reserved` | Picker otworzył batch, koszyk przypisany |
| `picked_ready` | Picker zamknął batch, koszyk czeka na packera |
| `packing_in_progress` | Packer otworzył pierwsze zamówienie z koszyka |

### Pule koszyków
- Koszyki 1–20 dla `small`
- Koszyki 1–20 dla `large`

---

## Powiązanie batcha z koszykiem

Tabela `picking_batches` posiada pole `basket_id` (FK do `workflow_baskets`).
Jeden batch = jeden koszyk.

---

## Flow pickera w trybie split

1. Operator ustawia `workflow_mode = split`, `work_mode = picker`
2. `POST /api/v1/picking/batches/open`
   - backend szuka wolnego koszyka dla danego `package_mode`
   - rezerwuje koszyk (`status => reserved`)
   - przypisuje koszyk do batcha
   - jeśli brak wolnych koszyków — blad `no_free_baskets`
3. Picker zbiera produkty normalnie
4. `POST /api/v1/picking/batches/{batchId}/close`
   - sa spikowane zamowienia — koszyk przechodzi na `picked_ready`
   - nic nie zostalo po brakach — koszyk wraca na `empty`

---

## Flow packera w trybie split

1. Operator ustawia `workflow_mode = split`, `work_mode = packer`
2. `GET /api/v1/packing/next-ready-batch` — sprawdza czy jest gotowy koszyk
3. `POST /api/v1/packing/open-next-ready-batch`
   - atomowo otwiera pierwszy gotowy koszyk (FIFO po `completed_at`)
   - koszyk przechodzi na `packing_in_progress`
   - otwiera sesje pakowania dla pierwszego zamowienia
4. Packer pakuje kolejne zamowienia normalnie
5. Po ostatnim zamowieniu koszyk wraca na `empty`

---

## Blokady rol

W trybie split picker moze wywolywac tylko endpointy pickingu, packer tylko packingu.

Blad przy zlej roli:
```
HTTP 400
{"ok":false,"error":"Current user is not in picker mode",
 "details":{"reason":"invalid_work_mode","required_work_mode":"picker","work_mode":"packer"}}
```

---

## Dane koszyka w odpowiedziach API
```
basket_id, basket_no, basket_status
```

`basket_no` to numer do wyswietlenia operatorowi (1–20).

---

## Endpointy sesji — split workflow

### POST /api/v1/stations/workflow-mode
Body: `{"workflow_mode": "integrated"|"split"}`
Zwraca: station z polami workflow_mode, work_mode, package_mode, package_mode_default, picking_batch_size

### POST /api/v1/stations/work-mode
Body: `{"work_mode": "picker"|"packer"}`
Zwraca: station z polami workflow_mode, work_mode, package_mode, package_mode_default, picking_batch_size

### GET /api/v1/packing/next-ready-batch
Sprawdza czy jest gotowy koszyk dla packera.
Wymaga work_mode=packer w split mode.
Zwraca: batch_id, basket_id, basket_no, package_mode, ready (bool), reason

### POST /api/v1/packing/open-next-ready-batch
Atomowo otwiera pierwszy gotowy koszyk i pierwsza sesje pakowania.
Wymaga work_mode=packer w split mode.
Zwraca: dane sesji pakowania + auto_loaded_batch=true, batch_id, basket_id, basket_no

---

## Uwagi implementacyjne

- `open-next-ready-batch` jest w transakcji z FOR UPDATE — bezpieczny przy rownolegych packerach
- `findNextOrderToPack` wyklucza sesje open i completed — nie mozna otworzyc tego samego zamowienia dwa razy
- Kolejnosc otwierania koszyki: FIFO po picking_batches.completed_at ASC
