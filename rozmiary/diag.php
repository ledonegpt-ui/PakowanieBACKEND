<?php
echo "1 OK<br>";
require_once __DIR__ . '/helpers.php';
echo "2 helpers OK<br>";
$boot = sizes_bootstrap();
echo "3 bootstrap OK<br>";
$db = $boot['db'];
echo "4 db OK<br>";
[$items, $total] = sizes_fetch_all_items($db, 1, 5, '', '');
echo "5 fetch OK, total=$total<br>";