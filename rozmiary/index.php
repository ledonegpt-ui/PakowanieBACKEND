<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$boot = sizes_bootstrap();
$db = $boot['db'];

$currentOperator = sizes_current_operator();
$flash = sizes_get_flash();
$counts = sizes_fetch_counts($db, $currentOperator);
$items = sizes_fetch_assigned_items($db, $currentOperator);
$operators = sizes_operators();
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Rozmiary produktów</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 20px;
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f6f8;
            color: #1f2937;
        }
        .wrap {
            max-width: 1100px;
            margin: 0 auto;
        }
        .panel {
            background: #ffffff;
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
        }
        h1 {
            margin: 0 0 12px;
            font-size: 28px;
        }
        .sub {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 18px;
        }
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .toolbar form {
            margin: 0;
        }
        select, button {
            height: 40px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            padding: 0 12px;
            font-size: 14px;
        }
        button {
            cursor: pointer;
            background: #ffffff;
        }
        button.primary {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
        }
        button.success {
            background: #16a34a;
            color: #ffffff;
            border-color: #16a34a;
        }
        button.warn {
            background: #ea580c;
            color: #ffffff;
            border-color: #ea580c;
        }
        .stats {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 16px;
        }
        .stat {
            min-width: 180px;
            background: #f8fafc;
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            padding: 12px 14px;
        }
        .stat .label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .stat .value {
            font-size: 28px;
            font-weight: bold;
            margin-top: 6px;
        }
        .flash {
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 16px;
            border: 1px solid transparent;
        }
        .flash.success {
            background: #ecfdf5;
            border-color: #86efac;
            color: #166534;
        }
        .flash.error {
            background: #fef2f2;
            border-color: #fca5a5;
            color: #991b1b;
        }
        .flash.info {
            background: #eff6ff;
            border-color: #93c5fd;
            color: #1d4ed8;
        }
        .list-title {
            font-size: 20px;
            font-weight: bold;
            margin: 0 0 12px;
        }
        .empty {
            padding: 20px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            color: #64748b;
        }
        .item {
            background: #ffffff;
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 12px;
        }
        .item-name {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        .item-desc {
            font-size: 15px;
            color: #374151;
            margin-bottom: 10px;
            white-space: pre-wrap;
        }
        .meta {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 12px;
        }
        .classify-form {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .btn-small {
            background: #0f766e;
            color: #ffffff;
            border-color: #0f766e;
            font-weight: bold;
        }
        .btn-large {
            background: #b91c1c;
            color: #ffffff;
            border-color: #b91c1c;
            font-weight: bold;
        }
        .btn-other {
            background: #6b7280;
            color: #ffffff;
            border-color: #6b7280;
            font-weight: bold;
        }
        .operator-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            font-size: 13px;
            margin-bottom: 14px;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="panel">
        <h1>Rozmiary produktów</h1>
        <div class="sub">Prosty moduł roboczy do oznaczania produktów jako mała / duża / inne.</div>

        <div class="operator-badge">
            Operator:
            <strong><?php echo $currentOperator ? sizes_h($currentOperator) : 'nie ustawiono'; ?></strong>
        </div>

        <?php if ($flash !== null): ?>
            <div class="flash <?php echo sizes_h($flash['type']); ?>">
                <?php echo sizes_h($flash['message']); ?>
            </div>
        <?php endif; ?>

        <div class="toolbar">
            <a href="browse.php" style="…">Przeglądaj wszystkie</a>
            <form method="post" action="handle.php?action=set_operator">
                <select name="login" required>
                    <option value="">-- wybierz operatora --</option>
                    <?php foreach ($operators as $operator): ?>
                        <option value="<?php echo sizes_h($operator); ?>" <?php echo ($currentOperator === $operator) ? 'selected' : ''; ?>>
                            <?php echo sizes_h($operator); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="primary">Ustaw operatora</button>
            </form>

            <form method="post" action="handle.php?action=import_all" onsubmit="return confirm('Importować wszystkie produkty z Subiekta?');">
                <button type="submit" class="success">Importuj wszystkie produkty z Subiekta</button>
            </form>

            <form method="post" action="handle.php?action=claim_20">
                <button type="submit" class="warn" <?php echo $currentOperator === null ? 'disabled' : ''; ?>>Pobierz 20</button>
            </form>
        </div>

        <div class="stats">
            <div class="stat">
                <div class="label">ile mam przypisanych</div>
                <div class="value"><?php echo (int)$counts['assigned_to_me']; ?></div>
            </div>
            <div class="stat">
                <div class="label">ile zostało nieoznaczonych globalnie</div>
                <div class="value"><?php echo (int)$counts['global_unclassified']; ?></div>
            </div>
            <div class="stat">
                <div class="label">łącznie w lokalnej bazie</div>
                <div class="value"><?php echo (int)$counts['total_local']; ?></div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="list-title">Moje przypisane produkty</div>

        <?php if (empty($items)): ?>
            <div class="empty">
                Brak przypisanych produktów. Ustaw operatora i kliknij „Pobierz 20”.
            </div>
        <?php else: ?>
            <?php foreach ($items as $item): ?>
                <div class="item">
                    <div class="item-name"><?php echo sizes_h($item['name']); ?></div>
                    <div class="item-desc"><?php echo sizes_h($item['subiekt_desc'] !== null ? $item['subiekt_desc'] : '—'); ?></div>
                    <div class="meta">
                        symbol: <?php echo sizes_h($item['subiekt_symbol']); ?>
                        &nbsp; | &nbsp;
                        subiekt_tow_id: <?php echo (int)$item['subiekt_tow_id']; ?>
                        &nbsp; | &nbsp;
                        local_id: <?php echo (int)$item['id']; ?>
                    </div>

                    <form method="post" action="handle.php?action=classify" class="classify-form">
                        <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                        <button type="submit" name="size_status" value="small" class="btn-small">MAŁA</button>
                        <button type="submit" name="size_status" value="large" class="btn-large">DUŻA</button>
                        <button type="submit" name="size_status" value="other" class="btn-other">INNE</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
