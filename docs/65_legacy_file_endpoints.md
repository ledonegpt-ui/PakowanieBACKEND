# Legacy file endpoints

## Cel dokumentu

Ten plik opisuje **starą warstwę plikową**, która nadal istnieje w repo i nadal jest częścią projektu.

Nie należy jej mylić z nowym routerem:
- `/api/v1/...`

---

## Główne pliki legacy

### Widoki
- `queue.php`
- `order.php`

### Endpointy
- `api/login.php`
- `api/login_queue.php`
- `api/session.php`
- `api/queue.php`
- `api/start_pack.php`
- `api/finish_pack.php`
- `api/cancel_pack.php`
- `api/scan.php`
- `api/events.php`

### Front legacy
- `assets/js/queue.js`

---

## Charakterystyka legacy flow

Legacy flow:
- używa klasycznych skryptów PHP
- nie przechodzi przez router `/api/v1`
- opiera się o sesję PHP
- operuje bezpośrednio na liczbowych statusach `pak_orders`

Typowe statusy:
- `10` — new
- `40` — packing
- `50` — packed
- `60` — cancelled

---

## Rola poszczególnych endpointów

### `api/login.php`
Logowanie operatora / stanowiska dla starego flow.

### `api/login_queue.php`
Obsługa logowania do kolejki w starym interfejsie.

### `api/session.php`
Zwraca informacje o aktualnej sesji legacy.

### `api/queue.php`
Zwraca kolejkę zamówień dla starego ekranu `queue.php`.

### `api/start_pack.php`
Przełącza order do stanu pakowania w starym modelu.

### `api/finish_pack.php`
Kończy pakowanie w starym modelu i aktualizuje legacy status zamówienia.

### `api/cancel_pack.php`
Anuluje pakowanie w starym modelu.

### `api/scan.php`
Obsługuje skanowanie w starym przepływie.

### `api/events.php`
Zwraca lub zapisuje zdarzenia dla legacy UI.

---

## Ważne rozróżnienie

Legacy flow i nowe `/api/v1` współistnieją w repo.

To oznacza, że dokumentacja projektu musi rozdzielać:
1. nowy modułowy backend
2. starą warstwę plikową

Nie można opisywać projektu tak, jakby legacy zostało już całkowicie usunięte.
