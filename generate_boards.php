<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/parser_rules.php';

$actionType = $_POST['action_type'] ?? 'generate';
$selectedOrderIds = $_POST['order_ids'] ?? [];

if (in_array($actionType, ['activate', 'deactivate'])) {
    if (!empty($selectedOrderIds)) {
        $inClause = implode(',', array_map('intval', $selectedOrderIds));
        $newStatus = ($actionType === 'activate') ? 0 : 1;
        $db->exec("UPDATE orders SET is_processed = $newStatus WHERE id IN ($inClause)");
    }
    header('Location: index.php?tab=orders');
    exit;
}

$outputDir = __DIR__ . '/plansze';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$availableSvgs = getAvailableSvgs(__DIR__ . '/imiona');
$itemsToPack = [];
$isManualMode = isset($_POST['manual_names']);
$orderTotals = [];

// Pobranie aktywnych rezerwacji stocku
$reservationsRaw = $db->query("SELECT order_number, bauble_name FROM order_reservations")->fetchAll(PDO::FETCH_ASSOC);
$orderReservationsMap = [];
foreach ($reservationsRaw as $res) {
    $orderReservationsMap[$res['order_number']][] = mb_strtolower(trim($res['bauble_name']));
}

if ($isManualMode) {
    $rawNamesText = $_POST['manual_names'] ?? '';
    $parsedNames = parseNamesWithQuantities($rawNamesText);

    // Automatyczna rezerwacja dopasowanych sztuk ze stocku w trybie ręcznym (jeśli użytkownik zatwierdził popup)
    if (isset($_POST['reserve_matched_stock']) && $_POST['reserve_matched_stock'] === '1') {
        $availableStock = $db->query("SELECT id, bauble_name FROM stock_baubles WHERE status = 'available'")->fetchAll(PDO::FETCH_ASSOC);
        $stockPool = [];
        foreach ($availableStock as $st) {
            $stockPool[mb_strtolower(trim($st['bauble_name']))][] = $st['id'];
        }

        foreach ($parsedNames as $name) {
            $lookupKey = mb_strtolower(trim($name));
            if (isset($stockPool[$lookupKey]) && count($stockPool[$lookupKey]) > 0) {
                $stockId = array_shift($stockPool[$lookupKey]);
                // Sprawdzamy czy ta sztuka nie jest już zarezerwowana dla RECZNE
                $stmtCheck = $db->prepare("SELECT id FROM order_reservations WHERE order_number = 'RECZNE' AND stock_id = ?");
                $stmtCheck->execute([$stockId]);
                if (!$stmtCheck->fetch()) {
                    $db->prepare("UPDATE stock_baubles SET status = 'reserved', reserved_order_number = 'RECZNE', reserved_at = CURRENT_TIMESTAMP WHERE id = ?")
                       ->execute([$stockId]);
                    
                    $db->prepare("INSERT INTO order_reservations (order_number, bauble_name, stock_id) VALUES ('RECZNE', ?, ?)")
                       ->execute([$name, $stockId]);
                }
            }
        }
        
        // Odświeżamy mapę rezerwacji
        $reservationsRaw = $db->query("SELECT order_number, bauble_name FROM order_reservations")->fetchAll(PDO::FETCH_ASSOC);
        $orderReservationsMap = [];
        foreach ($reservationsRaw as $res) {
            $orderReservationsMap[$res['order_number']][] = mb_strtolower(trim($res['bauble_name']));
        }
    }
    
    $reservedInManual = $orderReservationsMap['RECZNE'] ?? [];
    $orderTotals['RECZNE'] = ['total' => count($parsedNames), 'placed' => 0, 'missing' => []];

    foreach ($parsedNames as $name) {
        $lookupKey = mb_strtolower(trim($name));
        
        // Jeśli imię jest ZAREZERWOWANE ze stocku, pomijamy układanie na planszy do cięcia
        if (($resIndex = array_search($lookupKey, $reservedInManual)) !== false) {
            unset($reservedInManual[$resIndex]);
            continue;
        }

        // Jeśli NIE jest zarezerwowane (użytkownik kliknął "Nie" lub nie ma na stocku), trafia na planszę do cięcia
        $hasSvg = isset($availableSvgs[$lookupKey]);
        
        if (!$hasSvg) {
            $orderTotals['RECZNE']['missing'][] = $name;
        }

        $itemsToPack[] = [
            'order_id' => 0,
            'order_number' => 'RECZNE',
            'name' => $name,
            'file' => $hasSvg ? $availableSvgs[$lookupKey] : null,
            'path' => $hasSvg ? (__DIR__ . '/imiona/' . $availableSvgs[$lookupKey]) : null,
            'is_missing' => !$hasSvg
        ];
    }
} else {
    if (empty($selectedOrderIds)) {
        header('Location: index.php?tab=orders&msg=no_selected');
        exit;
    }

    $inClause = implode(',', array_map('intval', $selectedOrderIds));
    $ordersStmt = $db->query("SELECT * FROM orders WHERE id IN ($inClause) ORDER BY CAST(order_number AS INTEGER) ASC");
    $rawOrders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawOrders as $order) {
        $ordNum = $order['order_number'];
        $reservedInThisOrder = $orderReservationsMap[$ordNum] ?? [];

        $parsedNames = parseNamesWithQuantities($order['names_field']);
        $orderTotals[$ordNum] = ['total' => count($parsedNames), 'placed' => 0, 'missing' => []];

        foreach ($parsedNames as $name) {
            $lookupKey = mb_strtolower(trim($name));

            if (($resIndex = array_search($lookupKey, $reservedInThisOrder)) !== false) {
                unset($reservedInThisOrder[$resIndex]);
                continue;
            }

            $hasSvg = isset($availableSvgs[$lookupKey]);
            
            if (!$hasSvg) {
                $orderTotals[$ordNum]['missing'][] = $name;
            }

            $itemsToPack[] = [
                'order_id' => $order['id'],
                'order_number' => $ordNum,
                'name' => $name,
                'file' => $hasSvg ? $availableSvgs[$lookupKey] : null,
                'path' => $hasSvg ? (__DIR__ . '/imiona/' . $availableSvgs[$lookupKey]) : null,
                'is_missing' => !$hasSvg
            ];
        }
    }
}

$validItemsToPack = array_filter($itemsToPack, function($item) {
    return !$item['is_missing'] && file_exists($item['path']);
});

if (empty($validItemsToPack)) {
    header('Location: index.php?tab=' . ($isManualMode ? 'manual' : 'orders') . '&msg=no_valid_svgs');
    exit;
}

function parseSvgDetails($filePath) {
    $content = file_get_contents($filePath);
    $cleanXml = preg_replace('/<\?xml.*?\?>/is', '', $content);
    $cleanXml = preg_replace('/<!DOCTYPE.*?>/is', '', $cleanXml);

    preg_match('/<svg[^>]*>/i', $cleanXml, $svgTagMatch);
    $svgTag = $svgTagMatch[0] ?? '';

    preg_match('/viewBox=["\']([^"\']+)["\']/i', $svgTag, $vbM);
    preg_match('/width=["\']([^"\']+)["\']/i', $svgTag, $wM);
    preg_match('/height=["\']([^"\']+)["\']/i', $svgTag, $hM);

    $viewBoxStr = $vbM[1] ?? '';
    $widthStr = $wM[1] ?? '';
    $heightStr = $hM[1] ?? '';

    $minX = 0; $minY = 0; $vW = 0; $vH = 0;
    if ($viewBoxStr !== '') {
        $parts = preg_split('/[\s,]+/', trim($viewBoxStr));
        if (count($parts) >= 4) {
            $minX = (float)$parts[0]; $minY = (float)$parts[1];
            $vW = (float)$parts[2]; $vH = (float)$parts[3];
        }
    }

    $toMm = function($str) {
        if (empty($str)) return null;
        $str = trim($str);
        if (preg_match('/^([0-9\.]+)\s*mm$/i', $str, $m)) return (float)$m[1];
        if (preg_match('/^([0-9\.]+)\s*cm$/i', $str, $m)) return (float)$m[1] * 10;
        if (preg_match('/^([0-9\.]+)\s*px$/i', $str, $m)) return (float)$m[1] * 0.264583;
        if (is_numeric($str)) return (float)$str * 0.264583;
        return null;
    };

    $widthMM = $toMm($widthStr) ?: (($vW > 0) ? $vW * 0.264583 : 96.0);
    $heightMM = $toMm($heightStr) ?: (($vH > 0) ? $vH * 0.264583 : 110.0);
    if ($vW <= 0) $vW = $widthMM / 0.264583;
    if ($vH <= 0) $vH = $heightMM / 0.264583;

    $innerContent = preg_replace('/<svg[^>]*>/i', '', $cleanXml);
    $innerContent = str_replace('</svg>', '', $innerContent);

    return [
        'minX' => $minX, 'minY' => $minY, 'vW' => $vW, 'vH' => $vH,
        'widthMM' => $widthMM, 'heightMM' => $heightMM,
        'scaleX' => ($vW > 0) ? ($widthMM / $vW) : 1,
        'scaleY' => ($vH > 0) ? ($heightMM / $vH) : 1,
        'innerContent' => $innerContent
    ];
}

$gridPositions = [
    ['x' => 85,  'y' => 65,  'rot' => 90],
    ['x' => 228, 'y' => 65,  'rot' => 270],
    ['x' => 370, 'y' => 65,  'rot' => 90],
    ['x' => 513, 'y' => 65,  'rot' => 270],
    ['x' => 68,  'y' => 190, 'rot' => 0],
    ['x' => 183, 'y' => 190, 'rot' => 0],
    ['x' => 298, 'y' => 190, 'rot' => 0],
    ['x' => 413, 'y' => 190, 'rot' => 0],
    ['x' => 528, 'y' => 190, 'rot' => 0],
    ['x' => 68,  'y' => 328, 'rot' => 0],
    ['x' => 183, 'y' => 328, 'rot' => 0],
    ['x' => 298, 'y' => 328, 'rot' => 0],
    ['x' => 413, 'y' => 328, 'rot' => 0],
    ['x' => 528, 'y' => 328, 'rot' => 0],
];

$db->exec("CREATE TABLE IF NOT EXISTS board_counter (id INTEGER PRIMARY KEY, next_number INTEGER)");
$counterRow = $db->query("SELECT next_number FROM board_counter WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
if (!$counterRow) {
    $db->exec("INSERT INTO board_counter (id, next_number) VALUES (1, 1)");
    $boardCounter = 1;
} else {
    $boardCounter = (int)$counterRow['next_number'];
}

$itemsPerPage = 14;
$chunkedPacks = array_chunk(array_values($validItemsToPack), $itemsPerPage);
$boardWidth = 600; 
$boardHeight = 400;
$orderPartCounters = [];

foreach ($chunkedPacks as $chunkIndex => $pack) {
    $ordersOnThisBoard = [];
    foreach ($pack as $item) {
        $ordNum = $item['order_number'];
        if (!in_array($ordNum, $ordersOnThisBoard)) {
            $ordersOnThisBoard[] = $ordNum;
        }
    }

    $namePartsString = [];
    foreach ($ordersOnThisBoard as $ordNum) {
        $totalOccurrences = 0;
        foreach ($chunkedPacks as $chk) {
            foreach ($chk as $it) {
                if ($it['order_number'] === $ordNum) {
                    $totalOccurrences++;
                    break;
                }
            }
        }

        if ($totalOccurrences > 1) {
            $orderPartCounters[$ordNum] = ($orderPartCounters[$ordNum] ?? 0) + 1;
            $partNum = $orderPartCounters[$ordNum];
            $namePartsString[] = $ordNum . '-cz' . $partNum;
        } else {
            $namePartsString[] = $ordNum;
        }
    }

    $formattedPrefix = str_pad($boardCounter, 2, '0', STR_PAD_LEFT);
    $fileName = $formattedPrefix . '-' . implode(' ', $namePartsString) . '.svg';
    
    $savePath = $outputDir . '/' . $fileName;
    while (file_exists($savePath)) {
        $boardCounter++;
        $formattedPrefix = str_pad($boardCounter, 2, '0', STR_PAD_LEFT);
        $fileName = $formattedPrefix . '-' . implode(' ', $namePartsString) . '.svg';
        $savePath = $outputDir . '/' . $fileName;
    }

    $svgContent = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' . "\n";
    $svgContent .= sprintf('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="%dmm" height="%dmm" viewBox="0 0 %d %d">' . "\n", $boardWidth, $boardHeight, $boardWidth, $boardHeight);
    
    $svgContent .= '  <style>' . "\n";
    $svgContent .= '    .bombka-item path, .bombka-item circle, .bombka-item rect, .bombka-item polygon, .bombka-item polyline { fill: #ffffff !important; stroke: #0f172a !important; stroke-width: 0.3px !important; fill-rule: evenodd !important; }' . "\n";
    $svgContent .= '  </style>' . "\n";

    $boardNames = [];
    $boardOrderCounts = [];

    foreach ($pack as $index => $item) {
        if (!isset($gridPositions[$index])) break;

        $pos = $gridPositions[$index];
        $details = parseSvgDetails($item['path']);
        $boardNames[] = $item['name'];

        $ordNum = $item['order_number'];
        $boardOrderCounts[$ordNum] = ($boardOrderCounts[$ordNum] ?? 0) + 1;
        if (isset($orderTotals[$ordNum])) {
            $orderTotals[$ordNum]['placed']++;
        }

        $transform = sprintf(
            'translate(%.3f, %.3f) rotate(%d) translate(%.3f, %.3f) scale(%.6f, %.6f) translate(%.3f, %.3f)',
            $pos['x'], $pos['y'], $pos['rot'],
            -$details['widthMM'] / 2, -$details['heightMM'] / 2,
            $details['scaleX'], $details['scaleY'],
            -$details['minX'], -$details['minY']
        );

        $svgContent .= sprintf('  <g class="bombka-item" transform="%s">' . "\n", $transform);
        $svgContent .= $details['innerContent'] . "\n";
        $svgContent .= '  </g>' . "\n";
    }

    $svgContent .= '</svg>';
    file_put_contents($savePath, $svgContent);

    $summaryList = [];
    foreach ($boardOrderCounts as $ordNum => $countOnThisBoard) {
        $tot = $orderTotals[$ordNum]['total'] ?? $countOnThisBoard;
        $placed = $orderTotals[$ordNum]['placed'] ?? $countOnThisBoard;
        $missing = $orderTotals[$ordNum]['missing'] ?? [];

        if (!empty($missing)) {
            $summaryList[] = ['order_number' => $ordNum, 'status_type' => 'missing', 'text' => 'brak ' . implode(', ', array_unique($missing))];
        } elseif ($placed < $tot) {
            $summaryList[] = ['order_number' => $ordNum, 'status_type' => 'partial', 'text' => 'częściowe (' . $placed . '/' . $tot . ' szt.)'];
        } else {
            $summaryList[] = ['order_number' => $ordNum, 'status_type' => 'complete', 'text' => 'kompletne'];
        }
    }

    $stmtB = $db->prepare("INSERT OR REPLACE INTO boards (filename, orders_summary, names_list, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
    $stmtB->execute([
        $fileName,
        json_encode($summaryList, JSON_UNESCAPED_UNICODE),
        implode(', ', $boardNames)
    ]);

    $boardCounter++;
}

$db->prepare("UPDATE board_counter SET next_number = ? WHERE id = 1")->execute([$boardCounter]);

// USUNIĘTO BŁĘDNY BLOK Z SQL UPDATE is_processed NA SAMYM KOŃCU
// Aktualizacja statusu przetworzenia dla zaznaczonych zleceń
if (!$isManualMode && !empty($selectedOrderIds)) {
    $inClause = implode(',', array_map('intval', $selectedOrderIds));
    $db->exec("UPDATE orders SET is_processed = 1 WHERE id IN ($inClause)");
}
header('Location: index.php?tab=boards');
exit;