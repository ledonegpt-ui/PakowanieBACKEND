# System pakowania LED-ONE — dokumentacja główna

Ta dokumentacja ma służyć tak, żeby nowy programista po wejściu do projektu:
- nie zgadywał jak działa system,
- nie zgadywał jak wygląda API,
- nie zgadywał jak wygląda baza,
- nie zgadywał które elementy są już zaimplementowane,
- nie zgadywał które elementy są dopiero planowane.

## Zasada dokumentacji
Każda rzecz musi mieć jeden z trzech statusów:
- `POTWIERDZONE` — wynika z aktualnego kodu, bazy albo ustaleń biznesowych
- `PLANOWANE` — ustalony kontrakt docelowy do wdrożenia
- `DECYZJA_WYMAGANA` — temat nierozstrzygnięty, nie wolno implementować „na czuja"

## Środowisko techniczne
- PHP 7.2.24
- MySQL 5.7.42
- Brak frameworka (bez Laravel/Symfony)
- Serwer HTTP z rewrite do `/api/v1`
- Klient docelowy: tablet Android / aplikacja Kotlin

## Struktura dokumentacji

### docs/ — główne pliki
- `00_current_state.md` — aktualny stan implementacji (aktualizować po każdej sesji)
- `10_target_modules.md` — moduły i ich stan
- `20_backend_workstreams.md` — zadania do zrobienia
- `30_import_known_issues.md` — znane problemy importera
- `40_status_model.md` — statusy w całym systemie
- `50_target_tables.md` — tabele bazy danych
- `60_api_v1_endpoints.md` — wszystkie endpointy ze stanem implementacji
- `70_backend_assignments_template.md` — podział zadań
- `80_schema_alignment.md` — wyrównanie schematu bazy
- `90_shipping_and_labels.md` — logika kurierów i etykiet
- `picking_api.md` — szczegółowa dokumentacja picking API

### docs/system/ — dokumenty kontekstowe
- `01_system_context.md` — kontekst i środowisko
- `02_workflow_target.md` — docelowy workflow operatora

## Najważniejsze zasady biznesowe
1. System jest API-first — klientem jest aplikacja Kotlin, nie przeglądarka
2. Import zamówień musi działać — nie ruszać bez świadomej decyzji
3. Stary system przeglądarkowy w `starysystem/` — tylko do czytania jako referencja historyczna
4. `menu_group` != `label_provider` — to nie to samo, resolver rozdziela te pojęcia
5. `delivery_method` z importu nie wystarcza do logiki etykiety — zawsze używaj resolvera
6. Identyfikatorem zamówienia w nowym flow jest `order_code`, nie wewnętrzne ID

## Najważniejsza zasada dla nowych programistów
Nie zakładaj skrótów. Przed implementacją czegokolwiek w logice kurierów
przeczytaj `90_shipping_and_labels.md` i zrozum różnicę między
menu_group / shipment_type / label_provider / label_endpoint.
