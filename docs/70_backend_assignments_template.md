# Zadania dla developerów — aktualizacja 2026-03-11 (sesja 2)

## ZADANIE A — DpdAdapter XML fix
**Assignee:** dev 1
**Plik:** `app/Modules/Shipping/Adapters/DpdAdapter.php`
**Problem:** `Premature end of file` w odpowiedzi SOAP DPD
**Przyczyna:** `xmlns=""` na węzłach wewnętrznych resetuje namespace, parser DPD (Java) gubi zawartość
**Fix:** usunąć `xmlns=""` z węzłów: `openUMLFeV5`, `pkgNumsGenerationPolicyV1`, `langCode`, `authDataV1`
**Credentials:** `shipping_providers.provider_code = 'dpd_contract'` — wpisane w DB
**Test:** `php bin/debug_shipping_resolver.php` + zamówienie `1873400`

---

## ZADANIE B — GlsAdapter
**Assignee:** dev 2
**Plik:** `app/Modules/Shipping/Adapters/GlsAdapter.php`

### Interfejs
```php
public function generateLabel(array $order, array $package, array $resolved, array $providerCfg): array
```

### Credentials (z $providerCfg)
```json
{
    "wsdl": "https://adeplus.gls-poland.com/adeplus/pm1/ade_webapi2.php?wsdl",
    "username": "42000341",
    "password": "Mateusz184"
}
```

### Dane zamówienia ($order)
Kluczowe pola:
- `order_code` — numer zamówienia
- `delivery_fullname` — imię i nazwisko odbiorcy
- `delivery_address` — ulica i numer (np. `Przylesie 9/8`)
- `delivery_city` — miasto
- `delivery_postcode` — kod pocztowy
- `phone` — telefon (format +48XXXXXXXXX)
- `payment_method` — `Płatność przy odbiorze` = COD
- `pickup_point_id` — dla GLS Parcel Shop (może być null)

### GLS SOAP flow
```php
$hClient = new SoapClient($wsdl);

// 1. Login
$oCredit = new stdClass();
$oCredit->user_name     = $username;
$oCredit->user_password = $password;
$session = $hClient->adeLogin($oCredit)->return->session;

// 2. Utwórz przesyłkę (jeśli $package['external_shipment_id'] puste)
// sprawdź metody: $hClient->__getFunctions()

// 3. Pobierz etykietę ZPL
$oInput          = new stdClass();
$oInput->session = $session;
$oInput->id      = $parcelId;
$oInput->mode    = 'roll_160x100_zebra';
$zplData = base64_decode($hClient->adePreparingBox_GetConsignLabels($oInput)->return->labels);

// 4. Logout
$oSess = new stdClass(); $oSess->session = $session;
$hClient->adeLogout($oSess);
```

### Zwracana tablica
```php
return [
    'tracking_number'      => '123456789',
    'external_shipment_id' => '987654',        // parcel_id z GLS
    'label_format'         => 'zpl',
    'label_status'         => 'ok',
    'file_token'           => 'gls_{order_code}_{datetime}.zpl',
    'file_path'            => 'gls_{order_code}_{datetime}.zpl',
    'raw_response'         => [],
];
```

### Zapis etykiety
```php
$dir = BASE_PATH . '/storage/labels';
if (!is_dir($dir)) mkdir($dir, 0755, true);
$filename = 'gls_' . $orderCode . '_' . date('Ymd_His') . '.zpl';
file_put_contents($dir . '/' . $filename, $zplData);
```

### Test
```bash
php -r "
define('BASE_PATH', __DIR__);
\$cfg = require 'app/bootstrap.php';
require 'app/Lib/Db.php';
require 'app/Modules/Packing/Repositories/PackingRepository.php';
require 'app/Support/ShippingMethodResolver.php';
require 'app/Modules/Shipping/Contracts/ShippingAdapterInterface.php';
require 'app/Modules/Shipping/Adapters/GlsAdapter.php';
\$db = Db::mysql(\$cfg);
\$repo = new PackingRepository(\$db);
\$order = \$repo->findOrder('1873406');
\$mapCfg = require 'app/Config/shipping_map.php';
\$resolver = new ShippingMethodResolver(\$mapCfg);
\$resolved = \$resolver->resolve(['delivery_method'=>\$order['delivery_method'],'carrier_code'=>'','courier_code'=>'']);
\$cfg2 = json_decode(\$db->query(\"SELECT config_json FROM shipping_providers WHERE provider_code='gls'\")->fetchColumn(), true);
\$adapter = new GlsAdapter();
\$result = \$adapter->generateLabel(\$order, [], \$resolved, \$cfg2);
echo json_encode(\$result, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
"
```

Następnie test z pobraniem: zamówienie `1873409` (`payment_method = 'Płatność przy odbiorze'`).

---

## ZADANIE C — BaseLinkerAdapter (ERLI)
**Assignee:** dev 3
**Plik:** `app/Modules/Shipping/Adapters/BaseLinkerAdapter.php`
**Dotyczy:** zamówienia z `courier_code = erlipro` (ERLI)
**Credentials:** `shipping_providers.provider_code = 'baselinker'` — token w DB
**Wzorzec:** stary system, funkcja `GLS($nrpaczki, $idpaczki, $mysql, 'bl')` + sekcja BaseLinker w starym kodzie
**Uwaga:** BaseLinker zwraca etykietę przez `getOrderPackages` → `courier_code` → odpowiedni adapter

---

## Jak połączyć się z bazą
```php
$cfg = require 'app/bootstrap.php';
require_once 'app/Lib/Db.php';
$db = Db::mysql($cfg);
```
Wzorzec zapytań: `app/Modules/Packing/Repositories/PackingRepository.php`
Zawsze prepared statements — nigdy interpolacja zmiennych w SQL.

## Jak uruchomić test z CLI
```bash
cd ~/web/pakowanie.led-one.pl/public_html
php -r "define('BASE_PATH', __DIR__); /* ... kod testu ... */"
```

## Wzorzec zwracanej tablicy z adaptera
Każdy adapter musi zwrócić dokładnie tę strukturę:
```php
[
    'tracking_number'      => string,   // numer listu przewozowego
    'external_shipment_id' => string,   // ID przesyłki u kuriera
    'label_format'         => 'zpl',    // zawsze zpl dla Zebry
    'label_status'         => 'ok',
    'file_token'           => string,   // nazwa pliku w storage/labels/
    'file_path'            => string,   // ta sama nazwa
    'raw_response'         => array,    // surowa odpowiedź do logów
]
```
