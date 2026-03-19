#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import os, sys, shutil, argparse
from datetime import datetime

BACKUP_SUFFIX = datetime.now().strftime("%Y-%m-%d-%H%M%S")
FILES_TO_UPDATE = ["docs/60_api_v1_endpoints.md","docs/10_target_modules.md","docs/00_current_state.md"]
NEW_FILE = "docs/65_legacy_api_endpoints.md"
CONTENT = {}

CONTENT["docs/60_api_v1_endpoints.md"] = """# API v1 Endpoints

Base path: `/api/v1`

Źródło prawdy: `api/v1/index.php` (router).

---

## Health

- `GET /api/v1/health`
  - Publiczny (brak auth)
  - Odpowiedź: `{ system, version, status }`

---

## Auth

- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `GET  /api/v1/auth/me`

---

## Stations

- `GET  /api/v1/stations`
  - Zwraca listę aktywnych stanowisk z bazy.

- `POST /api/v1/stations/select`
  - Status: **stub techniczny** — odbiera `station_code`, nie modyfikuje sesji.
  - Odpowiedź: `{ module, action, status: "stub", received: { station_code } }`

- `POST /api/v1/stations/package-mode`
  - Status: **zaimplementowany**
  - Wymaga: Bearer token (aktywna sesja stacji)
  - Body: `{ "package_mode": "small" | "large" }`
  - Aktualizuje `package_mode` w aktywnej sesji stacji (`user_station_sessions`).
  - Odpowiedź: `{ ok, data: { station: { station_id, station_code, package_mode, package_mode_default } } }`
  - Błędy: 400 jeśli brak tokenu, brak aktywnej sesji lub nieprawidłowa wartość `package_mode`

---

## Carriers

- `GET /api/v1/carriers`

---

## Picking

### Batch lifecycle

- `POST /api/v1/picking/batches/open`
- `GET  /api/v1/picking/batches/current`
- `GET  /api/v1/picking/batches/{batchId}`
- `POST /api/v1/picking/batches/{batchId}/refill`
- `POST /api/v1/picking/batches/{batchId}/selection-mode`
- `POST /api/v1/picking/batches/{batchId}/close`
- `POST /api/v1/picking/batches/{batchId}/abandon`

### Batch content

- `GET /api/v1/picking/batches/{batchId}/orders`
- `GET /api/v1/picking/batches/{batchId}/products`

### Picking operations

- `POST /api/v1/picking/orders/{orderId}/items/{itemId}/picked`
- `POST /api/v1/picking/orders/{orderId}/items/{itemId}/missing`
- `POST /api/v1/picking/orders/{orderId}/drop`

---

## Packing

Uwaga: parametr `{orderId}` w route faktycznie niesie `order_code`.

- `POST /api/v1/packing/orders/{orderId}/open`
- `GET  /api/v1/packing/orders/{orderId}`
- `POST /api/v1/packing/orders/{orderId}/finish`
- `POST /api/v1/packing/orders/{orderId}/cancel`
- `POST /api/v1/packing/orders/{orderId}/heartbeat`

---

## Shipping

- `GET  /api/v1/shipping/rules`
- `POST /api/v1/shipping/resolve-method`
- `GET  /api/v1/shipping/orders/{orderId}/options`
- `POST /api/v1/shipping/orders/{orderId}/generate-label`
- `GET  /api/v1/shipping/orders/{orderId}/label`
- `POST /api/v1/shipping/orders/{orderId}/reprint`

---

## Heartbeat

- `POST /api/v1/heartbeat`
"""

CONTENT["docs/10_target_modules.md"] = """# Moduły docelowe i aktywne

## Cel tego pliku

Ten dokument opisuje **co realnie istnieje dziś w repo** oraz jak traktować to w dokumentacji.

Repo zawiera:
1. **aktywny modułowy backend `/api/v1`**
2. **aktywny legacy flow oparty o `api/*.php` oraz stare widoki**

---

## Aktywne moduły nowego API

### Auth
Status: **wdrożony**

### Stations
Status: **wdrożony częściowo**

Endpointy:
- `GET /api/v1/stations` — lista stacji z bazy
- `POST /api/v1/stations/select` — stub techniczny (nie modyfikuje sesji)
- `POST /api/v1/stations/package-mode` — **zaimplementowany**
  - Aktualizuje `package_mode` (`small` | `large`) dla aktywnej sesji stacji
  - Wymaga aktywnej sesji w `user_station_sessions`

### Carriers
Status: **wdrożony**

### Picking
Status: **wdrożony**

Obsługuje:
- open batch, refill, selection_mode
- mark item picked / missing
- manual drop order
- close / abandon
- event log

### Packing
Status: **wdrożony**

Snapshoty sesji: `packing_sessions`, `packing_session_items`

### Shipping
Status: **wdrożony**

Adaptery: Allegro, BaseLinker, DPD, GLS, InPost

### Heartbeat
Status: **wdrożony**

---

## Legacy flow

Status: **nadal obecny w repo i nadal istotny dokumentacyjnie**

Legacy warstwa: `queue.php`, `order.php`, `api/*.php` (18 plików), `assets/js/queue.js`

Szczegółowy opis endpointów legacy: `docs/65_legacy_api_endpoints.md`
"""

CONTENT["docs/00_current_state.md"] = """# System Status

System modules currently implemented:

Auth, Stations, Carriers, Picking, Packing, Shipping, Heartbeat, Import

Architecture: API → Controllers → Services → Repositories → MySQL

---

# Picking module

Status: IMPLEMENTED

Features:
- batch creation, order selection, product picking
- missing items, manual order drop, batch refill
- batch close, batch abandon, event log, aggregated product list

Database tables:
picking_batches, picking_batch_orders, picking_order_items, picking_batch_items, picking_events

Workflow: IMPORT → PICKING → PACKING → SHIPPING

Orders used for picking must have: pak_orders.status = 10

## Korekta 2026-03-13 — picking data model

- nagłówek zamówienia pochodzi z `pak_orders`
- pozycje do pickingu pochodzą z Subiekta i są zapisane w `pak_order_items`
- przy otwarciu batcha są kopiowane do `picking_order_items`
- po cleanupie pozycje pickingu są subiektowe (`line_key` = `SUB-*`)
- `offer_id` pochodzi z `ob_SyncId` i jest głównym łącznikiem z marketplace
- wcześniejsze opisy sugerujące równoległe aktywne źródła `EU-*` / `BL-*` są nieaktualne

---

## Stations — aktualizacja 2026-03-19

Moduł Stations posiada teraz trzy endpointy:
- `GET /api/v1/stations` — lista aktywnych stacji
- `POST /api/v1/stations/select` — stub techniczny
- `POST /api/v1/stations/package-mode` — **zaimplementowany**
  - aktualizuje tryb pakowania (`small` | `large`) w aktywnej sesji stacji
  - źródło: `StationsController::packageMode()` → `StationsService::updatePackageMode()`

---

## Legacy flow nadal istnieje

Repo zawiera nie tylko nowy modułowy backend `/api/v1`, ale również starszą warstwę plikową.

Legacy: `queue.php`, `order.php`, `api/*.php`, `assets/js/queue.js`

Aktualny stan repo to współistnienie obu tych warstw.
Szczegółowy opis endpointów legacy: `docs/65_legacy_api_endpoints.md`
"""

CONTENT["docs/65_legacy_api_endpoints.md"] = """# Legacy API Endpoints

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
"""

def log(msg): print(f"  {msg}")

def backup_file(path, dry_run):
    bp = path + "." + BACKUP_SUFFIX
    if dry_run: log(f"[DRY] Backup: {path} → {bp}")
    else:
        shutil.copy2(path, bp)
        log(f"[OK ] Backup: {bp}")

def write_file(path, content, dry_run):
    if dry_run: log(f"[DRY] Zapisałbym: {path} ({len(content)} znaków)")
    else:
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "w", encoding="utf-8") as f: f.write(content)
        log(f"[OK ] Zapisano: {path} ({len(content)} znaków)")

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", default=".")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    root = os.path.abspath(args.root)
    dry_run = args.dry_run

    print(f"\n{'='*60}")
    print(f"  Root: {root}")
    print(f"  Tryb: {'DRY-RUN' if dry_run else 'LIVE'}")
    print(f"{'='*60}\n")

    if not os.path.exists(os.path.join(root, "api", "v1", "index.php")):
        print("[BŁĄD] Nie znaleziono api/v1/index.php — sprawdź --root")
        sys.exit(1)

    print("✔  Znaleziono router\n")

    all_files = FILES_TO_UPDATE + [NEW_FILE]
    for rel_path in all_files:
        full_path = os.path.join(root, rel_path)
        print(f"  → {rel_path}")
        if os.path.exists(full_path): backup_file(full_path, dry_run)
        write_file(full_path, CONTENT[rel_path], dry_run)
        print()

    print("="*60)
    if dry_run: print("  DRY-RUN — nic nie zapisano. Uruchom bez --dry-run.")
    else:
        print("  Gotowe! Zaktualizowane pliki:")
        for f in all_files: print(f"    ✔  {f}")
        print(f"  Backupy: suffix .{BACKUP_SUFFIX}")
    print("="*60+"\n")

if __name__ == "__main__": main()
