<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Method Not Allowed\n";
    exit;
}

$boot = sizes_bootstrap();
$cfg  = $boot['cfg'];
$db   = $boot['db'];

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

try {
    switch ($action) {
        case 'set_operator':
            $login = isset($_POST['login']) ? trim((string)$_POST['login']) : '';

            if (!sizes_is_valid_operator($login)) {
                sizes_set_flash('error', 'Nieprawidłowy operator.');
                sizes_redirect();
            }

            sizes_set_operator($login);
            sizes_set_flash('success', 'Ustawiono operatora: ' . $login);
            sizes_redirect();
            break;

        case 'import_all':
            $mssql = Db::mssql($cfg);
            $result = sizes_import_all($db, $mssql);
            sizes_set_flash('success', 'Import zakończony. Przetworzono produktów: ' . (int)$result['processed']);
            sizes_redirect();
            break;

        case 'claim_20':
            $login = sizes_current_operator();
            if ($login === null) {
                sizes_set_flash('error', 'Najpierw ustaw operatora.');
                sizes_redirect();
            }

            $result = sizes_claim_20($db, $login);

            if ((int)$result['existing'] > 0) {
                sizes_set_flash('info', 'Masz już przypisane nieoznaczone rekordy: ' . (int)$result['existing']);
            } elseif ((int)$result['claimed'] > 0) {
                sizes_set_flash('success', 'Przypisano rekordów: ' . (int)$result['claimed']);
            } else {
                sizes_set_flash('info', 'Brak wolnych nieoznaczonych rekordów do przypisania.');
            }

            sizes_redirect();
            break;

        case 'classify':
            $login = sizes_current_operator();
            if ($login === null) {
                sizes_set_flash('error', 'Najpierw ustaw operatora.');
                sizes_redirect();
            }

            $id         = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $sizeStatus = isset($_POST['size_status']) ? trim((string)$_POST['size_status']) : '';

            if ($id <= 0 || !in_array($sizeStatus, ['small', 'large', 'other'], true)) {
                sizes_set_flash('error', 'Nieprawidłowe dane klasyfikacji.');
                sizes_redirect();
            }

            $ok = sizes_classify($db, $login, $id, $sizeStatus);

            if ($ok) {
                $labels = [
                    'small' => 'MAŁA',
                    'large' => 'DUŻA',
                    'other' => 'INNE',
                ];
                sizes_set_flash('success', 'Zapisano klasyfikację: ' . $labels[$sizeStatus]);
            } else {
                sizes_set_flash('error', 'Nie udało się zapisać. Rekord mógł być już oznaczony albo nie jest przypisany do Ciebie.');
            }

            sizes_redirect();
            break;

        case 'update_item':
            $login = sizes_current_operator();
            if ($login === null) {
                sizes_set_flash('error', 'Najpierw ustaw operatora.');
                sizes_redirect('browse.php');
            }

            $id   = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
            $desc = isset($_POST['subiekt_desc']) ? trim((string)$_POST['subiekt_desc']) : '';

            if ($desc === '') {
                $desc = null;
            }

            $sizeStatus = isset($_POST['size_status']) && $_POST['size_status'] !== ''
                ? trim((string)$_POST['size_status'])
                : null;

            if ($id <= 0 || $name === '') {
                sizes_set_flash('error', 'Nieprawidłowe dane — brak ID lub nazwy.');
                sizes_redirect('browse.php');
            }

            if ($sizeStatus !== null && !in_array($sizeStatus, ['small', 'large', 'other'], true)) {
                sizes_set_flash('error', 'Nieprawidłowy rozmiar.');
                sizes_redirect('browse.php');
            }

            sizes_update_item($db, $login, $id, $name, $desc, $sizeStatus);

            $labels    = ['small' => 'MAŁA', 'large' => 'DUŻA', 'other' => 'INNE'];
            $sizeLabel = $sizeStatus ? $labels[$sizeStatus] : 'bez rozmiaru';
            sizes_set_flash('success', 'Zaktualizowano produkt (rozmiar: ' . $sizeLabel . ').');
            sizes_redirect('browse.php');
            break;

        default:
            sizes_set_flash('error', 'Nieznana akcja.');
            sizes_redirect();
    }
} catch (\Throwable $e) {
    sizes_set_flash('error', 'Błąd: ' . $e->getMessage());
    sizes_redirect();
}
