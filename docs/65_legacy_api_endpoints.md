# Legacy API Endpoints

Ścieżka bazowa: `/api/`

Autentykacja przez sesję PHP (cookie `PHPSESSID`), nie Bearer token.
Wszystkie odpowiedzi to JSON z polem `ok: true/false`.

---

## Auth (legacy)

### POST /api/login.php
Logowanie pakowacza na stanowisko.
Body: `packer_code` lub `packer`, `station_no` (1..11)
Ustawia sesję: `packer`, `station_no`, `station_name`, `printer_ip`, `role`
Odpowiedź: `{ ok, packer, role, station_name, station_no }`

### POST /api/login_queue.php
Logowanie do widoku kolejki bez stanowiska.
Body: `packer_code` lub `packer`
Odpowiedź: `{ ok, packer, role }` (role: `manager` lub `packer`)

### POST /api/logout.php
Niszczy sesję PHP.
Odpowiedź: `{ ok: true }`

### GET /api/session.php
Odczytuje aktywną sesję.
Odpowiedź: `{ ok, packer, role, has_station, station_no, station_name, printer_ip }`

---

## Zamówienia (legacy)

### GET /api/scan.php
Pobiera zamówienie i pozycje po zeskanowaniu kodu.
Parametry: `order_code` lub `code` (akceptuje format `*KOD*`), `log=1` (opcjonalnie loguje SCAN)
Odpowiedź: `{ ok, order_code, status, subiekt_doc_no, subiekt_doc_id, delivery_method,
              pack_started_at, pack_ended_at, packer, station,
              items: [ { subiekt_symbol, name, subiekt_desc, quantity, image_url } ] }`
Uwaga: items zawiera tylko pozycje z `line_key LIKE 'SUB-%'`

### POST /api/start_pack.php
Rozpoczyna pakowanie (status 10 → 40).
Wymaga sesji: packer, station. Body: `order_code`
Loguje zdarzenie `START` do `pak_events`.

### POST /api/finish_pack.php
Kończy pakowanie (status 40 → 50).
Wymaga sesji: packer, station, station_no, printer_ip. Body: `order_code`, `force` (opcjonalnie, tylko manager)
Drukuje etykietę przez LabelService (socket lub CUPS wg `PRINT_BACKEND`).
Loguje `FINISH` lub `FINISH force_finish` do `pak_events`.

### POST /api/cancel_pack.php
Anuluje zamówienie (status → 60).
Wymaga sesji: packer, station. Body: `order_code`, `reason` (opcjonalnie)
Loguje `CANCEL` do `pak_events`.

### POST /api/unlock_pack.php
Odblokowuje zawieszone zamówienie (40 → 10). Tylko manager.
Wymaga sesji: role=manager. Body: `order_code`, `reason` (opcjonalnie)
Loguje `UNLOCK` do `pak_events`.

### POST /api/reopen_order.php
Ponownie otwiera zamówienie (50|60 → 10). Tylko manager.
Body: `order_code`, `reason`, `manager_code` lub `manager_name`
Loguje `REOPEN` do `pak_events`.

### POST /api/pack_heartbeat.php
Odświeża `pack_heartbeat_at` dla aktywnego pakowania.
Wymaga sesji: packer, station. Body: `order_code`
Odpowiedź: `{ ok: true|false }`

---

## Druk (legacy)

### POST /api/reprint_label.php
Ponowny wydruk etykiety.
Wymaga sesji: packer, station, printer_ip. Body: `order_code`
Loguje `PRINT_OK` lub `PRINT_FAIL` do `pak_events`.

### POST /api/print_test.php
Testowy wydruk ZPL na drukarce Zebra (socket TCP port 9100).
Wymaga sesji: packer, station, printer_ip.
Loguje do tabeli `print_logs`.

---

## Kolejka (legacy)

### GET /api/queue.php
Lista zamówień z filtrowaniem i paginacją.
Parametry: `status` (lista po przecinku), `station`, `packer`, `q`,
           `date_from`, `date_to`, `stale=1`, `limit` (max 500), `offset`
Odpowiedź: `{ ok, total, items: [...] }`

### GET /api/queue_stats.php
Rozbudowane statystyki dla widoku managera.
Parametry: jak queue + `stale_limit` (max 30), `active_limit` (max 30)
Odpowiedź:
  summary_live: { new_count, packing_count, packed_total, cancelled_total,
                  stale_count, packed_today, cancelled_today, active_packers, active_stations }
  summary_filtered: { matching_total, matching_new, matching_packing, matching_packed,
                      matching_cancelled, matching_stale }
  performance: { packed_count, cancelled_count, avg_packing_seconds,
                 sum_packing_seconds, median_packing_seconds }
  packers_top: [ { packer, packed, avg_seconds, sum_seconds } ]
  stations_top: [ { station, packed, avg_seconds, sum_seconds } ]
  active_now: [ { order_code, subiekt_doc_no, packer, station, pack_started_at,
                  pack_heartbeat_at, age_seconds, total_packing_seconds } ]
  stale_top: [ { order_code, subiekt_doc_no, packer, station,
                 pack_started_at, pack_heartbeat_at, age_seconds } ]
  events: { unlock_count, start_count, finish_count, force_finish_count,
            cancel_count, reopen_count, print_fail_count, print_ok_count }

### GET /api/events.php
Historia zdarzeń pak_events dla zamówienia.
Parametry: `order_code` (wymagany)
Odpowiedź: `{ ok, items: [ { id, order_code, event_type, packer, station, message, created_at } ] }`
Limit: 500 rekordów, posortowane rosnąco po created_at.

---

## Raporty (legacy)

### GET /api/report_packers_summary.php
Zbiorczy raport pakowaczy — DataTables server-side.
Parametry: draw, start, length, search[value], date_from, date_to, packer
Kolumny: packer, packed_count, avg_seconds, sum_seconds

### GET /api/report_packers_details.php
Szczegółowy raport zamówień pakowacza — DataTables server-side.
Parametry: draw, start, length, search[value], date_from, date_to, packer, selected_packer
(selected_packer ma priorytet nad packer)
Kolumny: order_code, subiekt_doc_no, packer, station, pack_started_at, pack_ended_at, czas_s

---

## Uwagi ogólne

- Sesja PHP (PHPSESSID cookie), nie Bearer token.
- normalize_order_code() akceptuje format *KOD* ze skanerów i normalizuje do uppercase.
- Zdarzenia logowane do `pak_events` (nie `packing_events` — to jest tabela modułowa).
