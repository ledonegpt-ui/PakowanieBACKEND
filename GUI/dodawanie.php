<?php
declare(strict_types=1);

// ── AJAX handler ─────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'import') {

    ob_start(); // łapie wszelkie stray output / notices PHP

    header('Content-Type: application/json; charset=utf-8');

    $rawCode = trim((string)($_POST['code'] ?? ''));
    if ($rawCode === '') {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => 'Podaj kod zamówienia']);
        exit;
    }

    // GUI/dodawanie.php -> __DIR__ = .../public_html/GUI
    // dirname(__DIR__)           = .../public_html
    if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));

    if (!function_exists('env_val')) {
        function env_val(string $key, $default = null) {
            $v = getenv($key);
            if ($v === false || $v === '') {
                if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
                if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
                return $default;
            }
            return $v;
        }
    }
    if (!function_exists('env_int_val')) {
        function env_int_val(string $key, int $default = 0): int {
            $v = env_val($key, null);
            if ($v === null) return $default;
            $v = trim((string)$v);
            if ($v === '' || !preg_match('/^-?\d+$/', $v)) return $default;
            return (int)$v;
        }
    }

    try {
        $cfg = require BASE_PATH . '/app/bootstrap.php';

        require_once BASE_PATH . '/app/Lib/Db.php';
        require_once BASE_PATH . '/app/Services/ImportState.php';
        require_once BASE_PATH . '/app/Services/SubiektReaderV2.php';
        require_once BASE_PATH . '/app/Services/FirebirdEUReader.php';
        require_once BASE_PATH . '/app/Services/BaselinkerBatchReader.php';
        require_once BASE_PATH . '/app/Services/OrderRepositoryV2.php';
        require_once BASE_PATH . '/app/Services/LegacyAuctionPhotoMap.php';
        require_once BASE_PATH . '/app/Services/ImporterMasterV2.php';

        $logFile = BASE_PATH . '/storage/logs/import_single.log';
        $logger = function(string $m) use ($logFile): void {
            @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $m . PHP_EOL, FILE_APPEND);
        };

        $mysql = Db::mysql($cfg);
        $mssql = Db::mssql($cfg);
        $fb    = Db::firebird($cfg);

        $state     = new ImportState($mysql);
        $repo      = new OrderRepositoryV2($mysql);
        $subReader = new SubiektReaderV2($mssql, $cfg, $logger);
        $euReader  = new FirebirdEUReader($fb);

        $blToken = (string)env_val('BASELINKER_TOKEN', (string)($cfg['baselinker']['token'] ?? ''));
        if ($blToken === '') throw new RuntimeException('Brak BASELINKER_TOKEN');

        $blReader = new BaselinkerBatchReader($blToken, $logger);

        $legacyImg = null;
        try {
            $mysql2    = Db::mysql2($cfg);
            $legacyImg = new LegacyAuctionPhotoMap($mysql2, $logger);
        } catch (\Throwable $e) {
            $logger('SINGLE: legacy image map disabled: ' . $e->getMessage());
        }

        $importer = new ImporterMasterV2(
            $mysql, $state, $repo, $subReader, $euReader, $blReader, $cfg, $logger, $legacyImg
        );
        $result = $importer->runSingle($rawCode);

        $stray = ob_get_clean();
        if ($stray !== '' && $stray !== false) {
            $logger('SINGLE: stray output: ' . $stray);
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);

    } catch (\Throwable $e) {
        $stray = ob_get_clean();
        echo json_encode([
            'status'  => 'error',
            'message' => 'Błąd serwera: ' . $e->getMessage(),
            'debug'   => ($stray !== '' && $stray !== false) ? $stray : null,
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dodawanie zamówień</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Syne:wght@700;800&display=swap');

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:        #0d0f12;
            --surface:   #161a1f;
            --border:    #2a2f38;
            --muted:     #3d4451;
            --text:      #c8cdd6;
            --bright:    #edf0f5;
            --green:     #3ddc84;
            --green-dim: #1a4d32;
            --yellow:    #f5c842;
            --yellow-dim:#3d3010;
            --red:       #ff5c5c;
            --red-dim:   #3d1212;
            --mono:      'JetBrains Mono', monospace;
            --head:      'Syne', sans-serif;
        }

        html, body {
            height: 100%;
            background: var(--bg);
            color: var(--text);
            font-family: var(--mono);
            font-size: 14px;
        }

        body {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 40px 20px;
            min-height: 100vh;
        }

        .header { width: 100%; max-width: 640px; margin-bottom: 40px; }
        .header-label {
            font-size: 11px;
            letter-spacing: .18em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 6px;
        }
        .header h1 {
            font-family: var(--head);
            font-size: 28px;
            font-weight: 800;
            color: var(--bright);
            letter-spacing: -.02em;
        }
        .header h1 span { color: var(--green); }

        .scanner-box {
            width: 100%;
            max-width: 640px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 28px;
            margin-bottom: 24px;
        }

        .input-row { display: flex; gap: 10px; align-items: stretch; }

        #codeInput {
            flex: 1;
            background: var(--bg);
            border: 1.5px solid var(--border);
            border-radius: 8px;
            color: var(--bright);
            font-family: var(--mono);
            font-size: 22px;
            font-weight: 600;
            letter-spacing: .04em;
            padding: 14px 18px;
            outline: none;
            transition: border-color .15s;
        }
        #codeInput::placeholder { color: var(--muted); font-size: 14px; font-weight: 400; }
        #codeInput:focus { border-color: var(--green); }

        #submitBtn {
            background: var(--green);
            color: #000;
            border: none;
            border-radius: 8px;
            font-family: var(--mono);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            padding: 14px 22px;
            cursor: pointer;
            transition: opacity .15s, transform .1s;
            white-space: nowrap;
        }
        #submitBtn:hover  { opacity: .85; }
        #submitBtn:active { transform: scale(.97); }
        #submitBtn:disabled { opacity: .4; cursor: not-allowed; }

        .hint { margin-top: 10px; font-size: 11px; color: var(--muted); }
        .hint code {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 1px 5px;
            color: var(--text);
        }

        #result {
            width: 100%;
            max-width: 640px;
            display: none;
            border-radius: 10px;
            padding: 18px 22px;
            margin-bottom: 24px;
            border: 1px solid;
            animation: slideIn .2s ease;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        #result.saved      { background: var(--green-dim);  border-color: var(--green); }
        #result.updated    { background: var(--yellow-dim); border-color: var(--yellow); }
        #result.incomplete { background: var(--yellow-dim); border-color: var(--yellow); }
        #result.error      { background: var(--red-dim);    border-color: var(--red); }

        .result-top { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
        .result-badge {
            font-size: 10px; font-weight: 700;
            letter-spacing: .12em; text-transform: uppercase;
            padding: 3px 8px; border-radius: 4px;
        }
        #result.saved      .result-badge { background: var(--green);  color: #000; }
        #result.updated    .result-badge { background: var(--yellow); color: #000; }
        #result.incomplete .result-badge { background: var(--yellow); color: #000; }
        #result.error      .result-badge { background: var(--red);    color: #fff; }

        .result-code    { font-size: 16px; font-weight: 700; color: var(--bright); }
        .result-msg     { font-size: 13px; color: var(--text); line-height: 1.6; }
        .result-details { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 6px 16px; }
        .result-detail  { font-size: 11px; color: var(--muted); }
        .result-detail span { color: var(--text); }

        .spinner {
            display: inline-block; width: 14px; height: 14px;
            border: 2px solid var(--muted); border-top-color: var(--green);
            border-radius: 50%; animation: spin .6s linear infinite;
            vertical-align: middle; margin-right: 6px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .history-header {
            width: 100%; max-width: 640px;
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 10px;
        }
        .history-label { font-size: 11px; letter-spacing: .14em; text-transform: uppercase; color: var(--muted); }

        #clearBtn {
            background: none; border: 1px solid var(--border); border-radius: 5px;
            color: var(--muted); font-family: var(--mono); font-size: 10px;
            letter-spacing: .1em; text-transform: uppercase;
            padding: 4px 10px; cursor: pointer; transition: color .15s, border-color .15s;
        }
        #clearBtn:hover { color: var(--red); border-color: var(--red); }

        #historyList { width: 100%; max-width: 640px; display: flex; flex-direction: column; gap: 6px; }

        .history-item {
            background: var(--surface); border: 1px solid var(--border); border-left-width: 3px;
            border-radius: 8px; padding: 10px 14px;
            display: flex; align-items: center; gap: 12px;
            animation: slideIn .15s ease;
        }
        .history-item.saved      { border-left-color: var(--green); }
        .history-item.updated    { border-left-color: var(--yellow); }
        .history-item.incomplete { border-left-color: var(--yellow); }
        .history-item.error      { border-left-color: var(--red); }

        .hi-time { font-size: 10px; color: var(--muted); min-width: 50px; }
        .hi-code { font-size: 13px; font-weight: 700; color: var(--bright); min-width: 80px; }
        .hi-msg  { font-size: 12px; color: var(--text); flex: 1; }

        .empty-history {
            width: 100%; max-width: 640px;
            text-align: center; color: var(--muted);
            font-size: 12px; padding: 24px 0; letter-spacing: .08em;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="header-label">pakowanie.led-one.pl</div>
    <h1>Dodawanie <span>zamówień</span></h1>
</div>

<div class="scanner-box">
    <div class="input-row">
        <input
            type="text"
            id="codeInput"
            placeholder="Zeskanuj lub wpisz kod zamówienia..."
            autocomplete="off"
            autofocus
            spellcheck="false"
        >
        <button id="submitBtn" onclick="doImport()">IMPORTUJ</button>
    </div>
    <div class="hint">
        Akceptowane formaty:
        <code>12345</code> &nbsp;
        <code>*12345*</code> &nbsp;
        <code>B456</code> &nbsp;
        <code>*B456*</code> &nbsp;
        <code>E789</code>
    </div>
</div>

<div id="result"></div>

<div class="history-header" id="historyHeader" style="display:none">
    <div class="history-label">Historia sesji</div>
    <button id="clearBtn" onclick="clearHistory()">Wyczyść</button>
</div>
<div id="historyList"></div>
<div class="empty-history" id="emptyHistory">Brak skanowań w tej sesji</div>

<script>
    const input     = document.getElementById('codeInput');
    const resultBox = document.getElementById('result');
    const histList  = document.getElementById('historyList');
    const histHead  = document.getElementById('historyHeader');
    const emptyMsg  = document.getElementById('emptyHistory');
    const btn       = document.getElementById('submitBtn');

    const LABELS = {
        saved:      'ZAPISANO',
        updated:    'ZAKTUALIZOWANO',
        incomplete: 'NIEKOMPLETNE',
        error:      'BŁĄD',
    };

    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') doImport();
    });

    function doImport() {
        const raw = input.value.trim();
        if (!raw) return;

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span>IMPORTUJĘ...';
        resultBox.style.display = 'none';

        const fd = new FormData();
        fd.append('action', 'import');
        fd.append('code', raw);

        fetch(window.location.href, { method: 'POST', body: fd })
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status + ' ' + r.statusText);
                return r.json();
            })
            .then(data => showResult(data, raw))
            .catch(err => showResult({ status: 'error', message: 'Błąd: ' + err.message }, raw))
            .finally(() => {
                btn.disabled = false;
                btn.textContent = 'IMPORTUJ';
            });
    }

    function showResult(data, rawInput) {
        const status = data.status || 'error';
        const code   = data.order_code || rawInput;
        const label  = LABELS[status] || status.toUpperCase();

        resultBox.className = status;
        resultBox.style.display = 'block';

        let details = '';
        if (data.delivery_fullname) details += `<div class="result-detail">Odbiorca: <span>${esc(data.delivery_fullname)}</span></div>`;
        if (data.delivery_city)     details += `<div class="result-detail">Miasto: <span>${esc(data.delivery_city)}</span></div>`;
        if (data.doc_no)            details += `<div class="result-detail">Nr dok: <span>${esc(data.doc_no)}</span></div>`;
        if (data.items_count !== undefined) details += `<div class="result-detail">Pozycji: <span>${data.items_count}</span></div>`;
        if (data.debug)             details += `<div class="result-detail" style="width:100%;word-break:break-all">Debug: <span>${esc(data.debug)}</span></div>`;

        resultBox.innerHTML = `
    <div class="result-top">
      <span class="result-badge">${label}</span>
      <span class="result-code">${esc(code)}</span>
    </div>
    <div class="result-msg">${esc(data.message || '')}</div>
    ${details ? `<div class="result-details">${details}</div>` : ''}
  `;

        const now = new Date();
        const t   = now.toTimeString().slice(0, 5);
        const item = document.createElement('div');
        item.className = 'history-item ' + status;
        item.innerHTML = `
    <div class="hi-time">${t}</div>
    <div class="hi-code">${esc(code)}</div>
    <div class="hi-msg">${esc(data.message || '')}</div>
  `;
        histList.prepend(item);

        histHead.style.display = 'flex';
        emptyMsg.style.display  = 'none';

        input.value = '';
        input.focus();
    }

    function clearHistory() {
        histList.innerHTML      = '';
        histHead.style.display  = 'none';
        emptyMsg.style.display  = 'block';
        resultBox.style.display = 'none';
    }

    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
</script>
</body>
</html>