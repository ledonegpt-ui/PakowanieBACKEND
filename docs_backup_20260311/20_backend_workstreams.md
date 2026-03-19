# Workstreamy backendu — aktualny stan

## Workstream A — Foundation + Auth
- POTWIERDZONE: GOTOWY
- bootstrap, DB, ApiResponse, auth, stations, sessions — wszystko działa

## Workstream B — Import
- POTWIERDZONE: import działa stabilnie
- Znane błędy w logach (MySQL server has gone away, legacyImg) — nie naprawiać bez weryfikacji
- Zasada: nie refaktorować importu bez świadomej decyzji

## Workstream C — Picking
- POTWIERDZONE: GOTOWY I DOMKNIĘTY
- Nie ruszać bez wyraźnego powodu (bug fix lub zmiana biznesowa)

## Workstream D — Packing
- POTWIERDZONE: szkielet gotowy
- DO ZROBIENIA: domknąć finish po działającym generate-label
- DO ZROBIENIA: upewnić się że updateOrderPackingFinished poprawnie zapisuje dane do pak_orders

## Workstream E — Shipping / Etykiety
- GŁÓWNE ZADANIE dla nowego developera
- DO ZROBIENIA (kolejność):
  1. Naprawić DpdAdapter — usunąć xmlns="" z węzłów wewnętrznych SOAP
  2. Zaimplementować GlsAdapter (credentials w DB są)
  3. Zaimplementować BaseLinkerAdapter (token w DB jest, używany przez ERLI)
  4. Uzupełnić credentials dla InPost i Allegro, potem implementować adaptery
  5. Refaktor: przenieść logikę z ShippingController do ShippingService + ShippingRepository
  6. Domknąć packing/finish po działającej ścieżce etykiet
