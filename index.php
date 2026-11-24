<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/parser_rules.php';

$activeTab = $_GET['tab'] ?? 'orders';
$selectedDate = $_GET['report_date'] ?? date('Y-m-d');

$syncState = $db->query("SELECT last_order_id, last_sync_at FROM sync_state WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
$rawOrders = $db->query("SELECT * FROM orders ORDER BY CAST(order_number AS INTEGER) DESC")->fetchAll(PDO::FETCH_ASSOC);
$availableSvgs = getAvailableSvgs(__DIR__ . '/imiona');
$boards = $db->query("SELECT * FROM boards ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$packers = $db->query("SELECT * FROM packers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Sprawdzamy, które numery zamówień mają już wygenerowane arkusze (na podstawie orders_summary w tabeli boards)
$generatedOrderNumbers = [];
foreach ($boards as $board) {
    $summaries = json_decode($board['orders_summary'], true) ?: [];
    foreach ($summaries as $sum) {
        if (!empty($sum['order_number'])) {
            $generatedOrderNumbers[$sum['order_number']] = true;
        }
    }
}

// Pobranie stanu rezerwacji stocku
$reservationsRaw = $db->query("SELECT order_number, bauble_name FROM order_reservations")->fetchAll(PDO::FETCH_ASSOC);
$orderReservationsMap = [];
foreach ($reservationsRaw as $res) {
    $orderReservationsMap[$res['order_number']][] = mb_strtolower(trim($res['bauble_name']));
}

// Pobranie dostępnych sztuk ze stocku (pula globalna)
$availableStockRaw = $db->query("SELECT bauble_name, COUNT(*) as cnt FROM stock_baubles WHERE status = 'available' GROUP BY LOWER(bauble_name)")->fetchAll(PDO::FETCH_ASSOC);
$stockPool = [];
foreach ($availableStockRaw as $st) {
    $stockPool[mb_strtolower(trim($st['bauble_name']))] = (int)$st['cnt'];
}

// Pobranie najnowszych przypisań pakowania dla aktywnych zamówień
$latestLogsRaw = $db->query("
    SELECT order_number, packer_name, packed_at 
    FROM packing_logs 
    ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$latestPackingMap = [];
foreach ($latestLogsRaw as $log) {
    $latestPackingMap[$log['order_number']] = [
        'packer_name' => $log['packer_name'],
        'packed_at' => $log['packed_at']
    ];
}

$processedOrders = [];
$missingReport = [];
$totalActiveOrders = 0;
$activeBaublesCount = 0;
$activeReservedBaublesCount = 0; // Liczba zarezerwowanych bombek w aktywnych zamówieniach
$packedOrdersCount = 0;
$packedBaublesCount = 0;
$generatedOrdersCount = 0;
$generatedBaublesCount = 0;

foreach ($rawOrders as $order) {
    if ($order['is_other_product'] || !$order['is_target_product']) {
        $processedOrders[] = array_merge($order, [
            'type' => 'other_product',
            'is_complete' => false,
            'parsed_names' => [],
            'names_with_stock' => [],
            'found_svgs' => [],
            'missing_svgs' => [],
            'packing_info' => $latestPackingMap[$order['order_number']] ?? null,
            'packer_name' => isset($latestPackingMap[$order['order_number']]) ? $latestPackingMap[$order['order_number']]['packer_name'] : ''
        ]);
        continue;
    }

    $isProcessed = (bool)($order['is_processed'] ?? false);
    $parsedNames = parseNamesWithQuantities($order['names_field']);
    $foundSvgs = [];
    $missingSvgs = [];
    
    $orderNum = $order['order_number'];
    $reservedNamesInOrder = $orderReservationsMap[$orderNum] ?? [];
    $namesWithStockStatus = [];

    foreach ($parsedNames as $name) {
        $lookupKey = mb_strtolower(trim($name));
        $hasSvg = isset($availableSvgs[$lookupKey]);
        
        $isThisReserved = false;
        if (($keyIndex = array_search($lookupKey, $reservedNamesInOrder)) !== false) {
            $isThisReserved = true;
            unset($reservedNamesInOrder[$keyIndex]);
        }

        // Każde zamówienie ma możliwość rezerwacji, dopóki na stocku są dostępne fizyczne sztuki danego imienia
        $canReserve = false;
        if (!$isThisReserved && isset($stockPool[$lookupKey]) && $stockPool[$lookupKey] > 0) {
            $canReserve = true;
        }

        if ($hasSvg || $isThisReserved) {
            $foundSvgs[] = ['name' => $name, 'file' => $hasSvg ? $availableSvgs[$lookupKey] : 'stock'];
        } else {
            $missingSvgs[] = $name;
            if (!$isProcessed) {
                $missingReport[] = [
                    'name' => $name,
                    'order_number' => $order['order_number'],
                    'customer' => $order['customer_name']
                ];
            }
        }

        $namesWithStockStatus[] = [
            'name' => $name,
            'is_reserved' => $isThisReserved,
            'can_reserve' => $canReserve
        ];
    }

    $orderedQty = (int)$order['ordered_quantity'];
    $namesCount = count($parsedNames);
    $qtyMatch = ($orderedQty > 0) ? ($orderedQty === $namesCount) : true;
    $isComplete = (count($missingSvgs) === 0 && $namesCount > 0 && $qtyMatch);

    $packingInfo = $latestPackingMap[$order['order_number']] ?? null;
    $itemBaublesQty = ($orderedQty > 0 ? $orderedQty : $namesCount);

    if ($packingInfo) {
        $packedOrdersCount++;
        $packedBaublesCount += $itemBaublesQty;
    } else {
        if (!$isProcessed) {
            $totalActiveOrders++;
            $activeBaublesCount += $itemBaublesQty;
            // Zliczamy rezerwacje dla zamówień aktywnych
            if (isset($orderReservationsMap[$orderNum])) {
                $activeReservedBaublesCount += count($orderReservationsMap[$orderNum]);
            }
        } else {
            $generatedOrdersCount++;
            $generatedBaublesCount += $itemBaublesQty;
        }
    }

    $processedOrders[] = array_merge($order, [
        'type' => 'target',
        'parsed_names' => $parsedNames,
        'names_with_stock' => $namesWithStockStatus,
        'found_svgs' => $foundSvgs,
        'missing_svgs' => $missingSvgs,
        'qty_match' => $qtyMatch,
        'is_complete' => $isComplete,
        'packing_info' => $packingInfo,
        'packer_name' => $packingInfo ? $packingInfo['packer_name'] : ''
    ]);
}

$packingReports = [];
$dateTotalOrders = 0;
$dateTotalItems = 0;

if ($activeTab === 'packing') {
    $stmtReport = $db->prepare("
        SELECT * FROM packing_logs 
        WHERE DATE(packed_at) = ? 
        ORDER BY packed_at DESC
    ");
    $stmtReport->execute([$selectedDate]);
    $logsForDate = $stmtReport->fetchAll(PDO::FETCH_ASSOC);

    $allLogs = $db->query("SELECT packer_name, items_count FROM packing_logs")->fetchAll(PDO::FETCH_ASSOC);
    $totalItemsMap = [];
    foreach ($allLogs as $al) {
        $totalItemsMap[$al['packer_name']] = ($totalItemsMap[$al['packer_name']] ?? 0) + (int)$al['items_count'];
    }

    foreach ($packers as $p) {
        $pName = $p['name'];
        $packingReports[$pName] = [
            'orders_count' => 0,
            'items_sum_day' => 0,
            'items_sum_total' => $totalItemsMap[$pName] ?? 0,
            'logs' => []
        ];
    }

    foreach ($logsForDate as $log) {
        $pName = $log['packer_name'];
        if (!isset($packingReports[$pName])) {
            $packingReports[$pName] = ['orders_count' => 0, 'items_sum_day' => 0, 'items_sum_total' => $totalItemsMap[$pName] ?? 0, 'logs' => []];
        }
        $packingReports[$pName]['orders_count']++;
        $packingReports[$pName]['items_sum_day'] += (int)$log['items_count'];
        $packingReports[$pName]['logs'][] = $log;

        $dateTotalOrders++;
        $dateTotalItems += (int)$log['items_count'];
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laser orders — Drewnolove</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css?v=<?= time(); ?>">
</head>
<body class="min-h-screen antialiased">
<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
        
        <header class="app-card p-6 flex flex-col xl:flex-row items-center justify-between gap-6">
            <div class="space-y-1 w-full xl:w-auto text-center xl:text-left">
                <div class="flex items-center justify-center xl:justify-start space-x-3">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400"></span>
                    <h1 class="text-xl font-bold tracking-tight text-white">Laser orders</h1>
                    <span class="px-2.5 py-0.5 text-xs font-medium bg-slate-900 text-indigo-400 border border-slate-700 rounded-full">drewnolove.pl</span>
                </div>
                <p class="text-xs text-slate-400 flex flex-wrap items-center justify-center xl:justify-start gap-2">
                    <span>Ostatnie ID: <strong class="text-slate-200">#<?= $syncState['last_order_id'] ?></strong></span>
                    <span>•</span>
                    <span>Sync: <strong class="text-slate-200"><?= $syncState['last_sync_at'] ?? 'Brak' ?></strong></span>
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 w-full xl:w-auto flex-1 max-w-3xl px-2">
                <div class="app-subcard p-4 text-center flex flex-col justify-center">
                    <span class="text-[11px] text-slate-400 uppercase tracking-wider font-semibold block truncate">Aktywne</span>
                    <span class="text-xs sm:text-sm font-bold text-indigo-400 font-mono mt-1">
                        <?= $totalActiveOrders ?> zlec. / <?= $activeBaublesCount ?> szt. 
                        <span class="text-xs sm:text-sm font-bold text-indigo-200 font-mono mt-1"></br>(w tym <?= $activeReservedBaublesCount ?> zarezerwowanych)</span>
                    </span>
                </div>

                <div class="app-subcard p-4 text-center flex flex-col justify-center">
                    <span class="text-[11px] text-slate-400 uppercase tracking-wider font-semibold block truncate">W pakowaniu</span>
                    <span class="text-sm font-bold text-teal-400 font-mono mt-1"><?= $packedOrdersCount ?> zlec. / <?= $packedBaublesCount ?> szt.</span>
                </div>

                <div class="app-subcard p-4 text-center flex flex-col justify-center">
                    <span class="text-[11px] text-slate-400 uppercase tracking-wider font-semibold block truncate">W wycinaniu</span>
                    <span class="text-sm font-bold text-purple-400 font-mono mt-1"><?= $generatedOrdersCount ?> zlec. / <?= $generatedBaublesCount ?> szt.</span>
                </div>
            </div>

            <div class="w-full xl:w-auto flex justify-center">
                <button id="fetch-btn" onclick="fetchOrders()" class="btn-fetch font-medium py-2.5 px-5 rounded-xl shadow-sm flex items-center space-x-2 text-sm w-full xl:w-auto justify-center whitespace-nowrap">
                    <svg class="w-4 h-4 text-indigo-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    <span>Połącz z DrewnoLove</span>
                </button>
            </div>
        </header>

        <nav class="app-nav flex space-x-1">
            <a href="?tab=orders" class="flex-1 py-2.5 px-4 text-center text-sm font-semibold rounded-lg transition-colors flex items-center justify-center space-x-2 <?= $activeTab === 'orders' ? 'app-tab-active' : 'app-tab-inactive' ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                <span>Zamówienia (Aktywne: <?= $totalActiveOrders ?>)</span>
            </a>
            <a href="?tab=boards" class="flex-1 py-2.5 px-4 text-center text-sm font-semibold rounded-lg transition-colors flex items-center justify-center space-x-2 <?= $activeTab === 'boards' ? 'app-tab-active' : 'app-tab-inactive' ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"></path></svg>
                <span>Wygenerowane Arkusze (<?= count($boards) ?>)</span>
            </a>
            <a href="?tab=packing" class="flex-1 py-2.5 px-4 text-center text-sm font-semibold rounded-lg transition-colors flex items-center justify-center space-x-2 <?= $activeTab === 'packing' ? 'app-tab-active' : 'app-tab-inactive' ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7v10l8 4"></path></svg>
                <span>Pakowanie / Raporty</span>
            </a>
           <a href="stock.php" class="flex-1 py-2.5 px-4 text-center text-sm font-semibold rounded-lg transition-colors flex items-center justify-center space-x-2 app-tab-inactive">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7v10l8 4"></path></svg>
                <span>Stock (Magazyn)</span>
            </a>
            <a href="?tab=manual" class="flex-1 py-2.5 px-4 text-center text-sm font-semibold rounded-lg transition-colors flex items-center justify-center space-x-2 <?= $activeTab === 'manual' ? 'app-tab-active' : 'app-tab-inactive' ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                <span>Ułóż braki / Ręczne</span>
            </a>
        </nav>

        <div id="status-message" class="hidden p-4 rounded-xl text-sm border font-medium"></div>

        <?php if ($activeTab === 'orders'): ?>
            
            <?php if (!empty($missingReport)): ?>
                <div class="bg-rose-950/30 border border-rose-800/50 rounded-2xl p-5 space-y-3">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center space-x-2.5 text-rose-400">
                            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                            </svg>
                            <h2 class="text-xs font-bold tracking-wider uppercase">Brakujące pliki SVG w bibliotece (<?= count($missingReport) ?>)</h2>
                        </div>
                        
                        <a href="index.php?tab=orders" class="bg-rose-900/40 hover:bg-rose-800/60 text-rose-200 border border-rose-700/60 font-medium px-3 py-1.5 rounded-xl text-xs transition-colors flex items-center space-x-1.5 whitespace-nowrap">
                            <span>Odśwież brakujące</span>
                        </a>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2.5">
                        <?php foreach ($missingReport as $item): ?>
                            <div class="app-subcard p-3 flex flex-col justify-between text-xs gap-2">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <span class="font-bold text-white block text-sm"><?= htmlspecialchars($item['name']) ?></span>
                                        <span class="text-slate-400 text-[11px]"><?= htmlspecialchars($item['customer']) ?></span>
                                    </div>
                                    <span class="bg-rose-500/10 text-rose-300 font-mono font-semibold px-2 py-1 rounded-md border border-rose-500/20 text-xs">#<?= htmlspecialchars($item['order_number']) ?></span>
                                </div>
                                <div class="flex items-center justify-end gap-2 pt-1 border-t border-slate-700/50">
                                    <button type="button" onclick="openTypoModal('<?= htmlspecialchars($item['order_number']) ?>', '<?= htmlspecialchars($item['name']) ?>')" class="text-indigo-400 hover:text-indigo-300 font-medium px-2 py-1 bg-indigo-950/40 border border-indigo-800/40 rounded-lg text-[11px]">
                                        ✏️ Popraw / Literówka
                                    </button>
                                    <button type="button" onclick="removeMissingItem('<?= htmlspecialchars($item['order_number']) ?>', '<?= htmlspecialchars($item['name']) ?>')" class="text-rose-400 hover:text-rose-300 font-medium px-2 py-1 bg-rose-950/40 border border-rose-800/40 rounded-lg text-[11px]">
                                        🗑️ Usuń
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="app-card p-4 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="w-full sm:w-96 relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </span>
                    <input type="text" id="order-search-input" onkeyup="filterOrdersTable()" placeholder="Szukaj po numerze zlecenia lub kliencie..." class="w-full bg-slate-900 border border-slate-700 rounded-xl pl-10 pr-4 py-2.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 font-sans">
                </div>

                <div class="flex items-center space-x-3 w-full sm:w-auto">
                    <label class="text-xs font-semibold text-slate-400 uppercase tracking-wider whitespace-nowrap">Pakowacz:</label>
                    <select id="packer-filter-select" onchange="filterOrdersTable()" class="bg-slate-900 border border-slate-700 rounded-xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-indigo-500 font-sans">
                        <option value="">Wszyscy / Wszystkie</option>
                        <option value="__unpacked__">⏳ Nie spakowane</option>
                        <?php foreach ($packers as $p): ?>
                            <option value="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <form action="generate_boards.php" method="POST">
                <div class="app-card overflow-hidden">
                    <div class="px-6 py-4 app-card-header flex flex-col sm:flex-row items-center justify-between gap-4">
                        <div class="flex items-center space-x-3">
                            <input type="checkbox" id="select-all-orders" onclick="toggleSelectAllOrders(this)" class="w-4 h-4 rounded border-slate-600 bg-slate-900 text-indigo-600 focus:ring-indigo-500/20">
                            <label for="select-all-orders" class="text-xs font-semibold text-slate-300 uppercase tracking-wider cursor-pointer">Zaznacz wszystkie</label>
                        </div>
                        <div class="flex items-center space-x-2 flex-wrap">
                            <button type="button" onclick="selectUnprocessedOrders()" class="text-xs text-indigo-300 hover:text-indigo-200 py-2 px-3 border border-indigo-800/60 rounded-xl bg-indigo-950/40 transition-colors">
                                Zaznacz niewygenerowane
                            </button>
                            <button type="submit" name="action_type" value="activate" class="text-xs text-emerald-400 hover:text-emerald-300 py-2 px-3 border border-emerald-800/60 rounded-xl bg-emerald-950/40 transition-colors">
                                Aktywuj zaznaczone
                            </button>
                            <button type="submit" name="action_type" value="deactivate" class="text-xs text-slate-300 hover:text-white py-2 px-3 border border-slate-700 rounded-xl bg-slate-900 transition-colors">
                                Dezaktywuj zaznaczone
                            </button>
                            <button type="submit" name="action_type" value="generate" class="bg-emerald-600 hover:bg-emerald-500 text-white font-medium py-2 px-5 rounded-xl text-sm transition-colors flex items-center space-x-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                                <span>Wygeneruj arkusze</span>
                            </button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-300" id="orders-table">
                            <thead class="app-table-header uppercase text-[11px] font-semibold tracking-wider">
                                <tr>
                                    <th class="px-5 py-3.5 w-12 text-center">Wybór</th>
                                    <th class="px-6 py-3.5">Status</th>
                                    <th class="px-6 py-3.5">Zamówienie</th>
                                    <th class="px-6 py-3.5">Klient</th>
                                    <th class="px-6 py-3.5">Ilość sztuk</th>
                                    <th class="px-6 py-3.5">Weryfikacja SVG / Stock</th>
                                    <th class="px-6 py-3.5 text-center whitespace-nowrap">Pakowanie</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700/40">
                                <?php foreach ($processedOrders as $order): ?>
                                    <?php 
                                        $isGrey = (bool)($order['is_processed'] ?? false);
                                        $pInfo = $order['packing_info'];
                                        $isOtherProduct = ($order['type'] === 'other_product');
                                        $hasBoard = isset($generatedOrderNumbers[$order['order_number']]);
                                        
                                        $rowPackedClass = '';
                                        if ($isOtherProduct) {
                                            $rowPackedClass = 'bg-red-950/60 border-l-4 border-l-red-500';
                                        } elseif ($pInfo) {
                                            $rowPackedClass = 'bg-emerald-950/40 border-l-4 border-l-emerald-500';
                                        }
                                        
                                        $packerAttr = $pInfo ? htmlspecialchars($pInfo['packer_name']) : '__unpacked__';
                                    ?>
                                    <tr class="app-table-row cursor-pointer select-none transition-all <?= $rowPackedClass ?> <?= ($isGrey && !$pInfo && !$isOtherProduct) ? 'is-processed' : '' ?>" 
                                        data-order-number="<?= htmlspecialchars($order['order_number']) ?>"
                                        data-search="<?= htmlspecialchars(mb_strtolower($order['order_number'] . ' ' . $order['customer_name'])) ?>"
                                        data-packer="<?= $packerAttr ?>"
                                        data-processed="<?= $isGrey ? '1' : '0' ?>"
                                        onclick="handleRowClick(event, this)">
                                        <td class="px-5 py-4 text-center">
                                            <?php if ($order['type'] === 'target'): ?>
                                                <input type="checkbox" name="order_ids[]" value="<?= $order['id'] ?>" class="order-checkbox w-4 h-4 rounded border-slate-600 bg-slate-900 text-indigo-600 focus:ring-indigo-500/20">
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap status-cell">
                                            <?php if ($isOtherProduct): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold bg-red-500/20 text-red-300 border border-red-500/40 shadow-sm animate-pulse">
                                                    ⚠️ Inne zamówienie - sprawdź!
                                                </span>
                                            <?php elseif ($pInfo): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 shadow-sm">
                                                    Pakowanie: <?= htmlspecialchars($pInfo['packer_name']) ?>
                                                </span>
                                            <?php elseif ($isGrey): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-slate-900 text-slate-400 border border-slate-700">
                                                    Wygenerowane
                                                </span>
                                            <?php elseif ($order['is_complete']): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 mr-2"></span> Gotowe
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-rose-500/10 text-rose-400 border border-rose-500/20">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-400 mr-2"></span> Wymaga uwagi
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 font-mono font-bold text-white whitespace-nowrap">#<?= htmlspecialchars($order['order_number']) ?></td>
                                        <td class="px-6 py-4 font-medium text-slate-200 whitespace-nowrap"><?= htmlspecialchars($order['customer_name']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-xs">
                                            <?php if ($order['type'] === 'target'): ?>
                                                <span class="text-slate-400">Kupiono: <strong class="text-white font-mono"><?= $order['ordered_quantity'] ?></strong></span>
                                                <span class="mx-1 text-slate-600">|</span>
                                                <span class="text-slate-400">Imion: <strong class="text-white font-mono"><?= count($order['parsed_names']) ?></strong></span>
                                            <?php elseif ($isOtherProduct): ?>
                                                <span class="text-slate-400">Sztuk: <strong class="text-white font-mono"><?= $order['ordered_quantity'] ?></strong></span>
                                            <?php else: ?>
                                                <span class="text-slate-600">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if ($order['type'] === 'target'): ?>
                                                <div class="flex flex-wrap gap-1.5 items-center">
                                                    <?php foreach ($order['names_with_stock'] as $item): ?>
                                                        <?php 
                                                            // Rezerwacja dozwolona tylko wtedy, gdy zamówienie nie ma jeszcze wygenerowanej planszy i nie jest spakowane
                                                            $canShowReserve = (!$hasBoard && !$pInfo);
                                                        ?>
                                                        <?php if ($item['is_reserved']): ?>
                                                            <span class="bg-amber-500/20 text-amber-300 border border-amber-500/50 text-xs px-2.5 py-1 rounded-md font-bold inline-flex items-center gap-1.5">
                                                                ⭐ <?= htmlspecialchars($item['name']) ?> (Zarezerwowane!)
                                                                <?php if ($canShowReserve): ?>
                                                                    <button type="button" onclick="event.stopPropagation(); reserveStockAction('unreserve', '<?= htmlspecialchars($order['order_number']) ?>', '<?= htmlspecialchars($item['name']) ?>')" class="text-xs text-amber-400 hover:text-white ml-1 underline">Cofnij</button>
                                                                <?php endif; ?>
                                                            </span>
                                                        <?php elseif ($item['can_reserve'] && $canShowReserve): ?>
                                                            <span class="bg-amber-400/20 text-amber-200 border border-amber-400/60 text-xs px-2.5 py-1 rounded-md font-semibold inline-flex items-center gap-1.5">
                                                                📦 <?= htmlspecialchars($item['name']) ?>
                                                                <button type="button" onclick="event.stopPropagation(); reserveStockAction('reserve', '<?= htmlspecialchars($order['order_number']) ?>', '<?= htmlspecialchars($item['name']) ?>')" class="bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold px-2 py-0.5 rounded text-[10px] ml-1 transition-colors">Zarezerwuj</button>
                                                            </span>
                                                        <?php else: ?>
                                                            <?php 
                                                                $found = false;
                                                                foreach ($order['found_svgs'] as $fs) {
                                                                    if ($fs['name'] === $item['name']) $found = true;
                                                                }
                                                            ?>
                                                            <?php if ($found): ?>
                                                                <span class="bg-emerald-950/40 text-emerald-300 border border-emerald-500/30 text-xs px-2.5 py-0.5 rounded-md font-medium">✓ <?= htmlspecialchars($item['name']) ?></span>
                                                            <?php else: ?>
                                                                <span class="bg-rose-950/40 text-rose-300 border border-rose-500/30 text-xs px-2.5 py-0.5 rounded-bold">✗ <?= htmlspecialchars($item['name']) ?></span>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php elseif ($isOtherProduct): ?>
                                                <span class="text-xs text-red-300 font-medium">Produkt niestandardowy (sprawdź w WooCommerce)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-right whitespace-nowrap">
                                            <button type="button" onclick="event.stopPropagation(); openPackerModal('<?= htmlspecialchars($order['order_number']) ?>')" class="bg-indigo-600 hover:bg-indigo-500 text-white font-medium py-1.5 px-4 rounded-xl text-xs transition-colors shadow-sm inline-flex items-center space-x-1.5">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7v10l8 4"></path></svg>
                                                <span>Pakuj</span>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>

        <?php elseif ($activeTab === 'boards'): ?>
            <form id="boards-form" action="handle_boards.php" method="POST" class="space-y-6">
                <input type="hidden" name="action_type" id="boards-action-type" value="download_zip">

                <div class="app-card p-4 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="flex items-center space-x-3">
                        <input type="checkbox" id="select-all-boards" onclick="toggleSelectAllBoards(this)" class="w-4 h-4 rounded border-slate-600 bg-slate-900 text-indigo-600 focus:ring-indigo-500/20">
                        <label for="select-all-boards" class="text-xs font-semibold text-slate-300 uppercase tracking-wider cursor-pointer">Zaznacz wszystkie arkusze</label>
                    </div>
                    <div class="flex items-center space-x-3">
                        <a href="?action=reset_downloaded_boards" class="text-xs text-slate-300 hover:text-white py-2 px-3 border border-slate-700 hover:border-slate-600 rounded-xl bg-slate-900 transition-colors">
                            Przywróć wyszarzone
                        </a>
                        <button type="button" onclick="submitBoardsForm('download_zip')" class="bg-indigo-600 hover:bg-indigo-500 text-white font-medium py-2 px-4 rounded-xl text-sm transition-colors flex items-center space-x-2">
                            <span>Pobierz zaznaczone (.zip)</span>
                        </button>
                        <button type="button" onclick="submitBoardsForm('delete')" class="bg-rose-950/30 hover:bg-rose-900/50 text-rose-300 border border-rose-800/50 font-medium py-2 px-4 rounded-xl text-sm transition-colors flex items-center space-x-2">
                            <span>Usuń zaznaczone</span>
                        </button>
                    </div>
                </div>

                <?php if (empty($boards)): ?>
                    <div class="app-card p-16 text-center text-slate-400">
                        Brak wygenerowanych arkuszy w bazie.
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <?php foreach ($boards as $board): ?>
                            <?php 
                                $summaries = json_decode($board['orders_summary'], true) ?: [];
                                $namesArray = array_filter(array_map('trim', explode(',', $board['names_list'])));
                                $itemsCount = count($namesArray);
                                $isDownloaded = (bool)($board['is_downloaded'] ?? false);
                            ?>
                            <div class="app-card overflow-hidden flex flex-col transition-colors <?= $isDownloaded ? 'is-processed opacity-50' : 'hover:border-slate-600' ?>">
                                <div class="px-5 py-3.5 app-card-header flex justify-between items-center">
                                    <div class="flex items-center space-x-3">
                                        <input type="checkbox" name="board_ids[]" value="<?= $board['id'] ?>" class="board-checkbox w-4 h-4 rounded border-slate-600 bg-slate-900 text-indigo-600 focus:ring-indigo-500/20">
                                        <h3 class="font-mono font-bold text-white text-sm">
                                            <?= htmlspecialchars($board['filename']) ?>
                                        </h3>
                                    </div>
                                    <div class="flex items-center space-x-3">
                                        <?php if ($isDownloaded): ?>
                                            <span class="px-2.5 py-0.5 rounded-lg text-xs font-medium bg-slate-900 text-slate-400 border border-slate-700">Pobrany</span>
                                        <?php else: ?>
                                            <span class="px-2.5 py-0.5 rounded-lg text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Nowy</span>
                                        <?php endif; ?>
                                        <a href="handle_boards.php?action_type=delete&board_ids[]=<?= $board['id'] ?>" onclick="return confirm('Usunąć ten arkusz?')" class="text-slate-400 hover:text-rose-400 text-xs transition-colors p-1">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                        </a>
                                    </div>
                                </div>

                                <div class="p-5 space-y-4 flex-1">
                                    <div class="space-y-2 text-xs">
                                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Statusy zleceń na arkuszu:</span>
                                        <div class="grid grid-cols-1 gap-2">
                                            <?php foreach ($summaries as $sum): ?>
                                                <div class="flex items-center justify-between app-subcard p-2.5">
                                                    <span class="font-mono font-bold text-slate-200">#<?= htmlspecialchars($sum['order_number']) ?></span>
                                                    <span class="inline-flex items-center text-xs font-semibold px-2 py-0.5 rounded border <?= $sum['status_type'] === 'complete' ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20' : 'text-amber-400 bg-amber-500/10 border-amber-500/20' ?>">
                                                        <?= htmlspecialchars($sum['text']) ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="px-5 py-3.5 app-card-header text-xs flex justify-between items-center border-t border-slate-700/60">
                                    <div class="text-slate-400 break-words flex-1 leading-relaxed">
                                        <strong class="text-slate-200">Imiona (<?= $itemsCount ?> szt.):</strong> <?= htmlspecialchars($board['names_list']) ?>
                                    </div>
                                    <a href="handle_boards.php?action_type=download_single&id=<?= $board['id'] ?>" class="bg-indigo-600 hover:bg-indigo-500 text-white font-medium px-3.5 py-1.5 rounded-lg text-xs transition-colors ml-3 whitespace-nowrap">
                                        Pobierz SVG
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </form>

        <?php elseif ($activeTab === 'packing'): ?>
            <div class="space-y-6">
                <div class="app-card p-6 flex flex-col md:flex-row items-center justify-between gap-4">
                    <form method="GET" action="index.php" class="flex items-center space-x-3">
                        <input type="hidden" name="tab" value="packing">
                        <label class="text-xs font-semibold text-slate-300 uppercase tracking-wider">Wybierz dzień:</label>
                        <input type="date" name="report_date" value="<?= htmlspecialchars($selectedDate) ?>" onchange="this.form.submit()" class="bg-slate-900 border border-slate-700 rounded-xl px-4 py-2 text-sm text-white font-mono focus:outline-none focus:border-indigo-500">
                    </form>
                    <button type="button" onclick="openManualAddModal()" class="bg-emerald-600 hover:bg-emerald-500 text-white font-medium py-2 px-4 rounded-xl text-xs transition-colors">
                        + Dodaj wpis ręcznie
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <?php foreach ($packers as $packer): ?>
                        <?php 
                            $pName = $packer['name'];
                            $rep = $packingReports[$pName] ?? ['orders_count' => 0, 'items_sum_day' => 0, 'items_sum_total' => 0, 'logs' => []];
                        ?>
                        <div class="app-card overflow-hidden flex flex-col">
                            <div class="px-5 py-4 app-card-header flex justify-between items-center border-b border-slate-700/60">
                                <h3 class="font-bold text-white text-base"><?= htmlspecialchars($pName) ?></h3>
                            </div>
                            <div class="p-5 space-y-4 flex-1 flex flex-col">
                                <div class="grid grid-cols-3 gap-2 text-center">
                                    <div class="app-subcard p-2.5">
                                        <span class="text-[9px] text-slate-400 uppercase tracking-wider block font-semibold">Zamówień</span>
                                        <span class="text-base font-bold text-white font-mono"><?= $rep['orders_count'] ?></span>
                                    </div>
                                    <div class="app-subcard p-2.5">
                                        <span class="text-[9px] text-slate-400 uppercase tracking-wider block font-semibold">Dzień</span>
                                        <span class="text-base font-bold text-emerald-400 font-mono"><?= $rep['items_sum_day'] ?></span>
                                    </div>
                                    <div class="app-subcard p-2.5">
                                        <span class="text-[9px] text-slate-400 uppercase tracking-wider block font-semibold">Total</span>
                                        <span class="text-base font-bold text-amber-400 font-mono"><?= $rep['items_sum_total'] ?></span>
                                    </div>
                                </div>

                                <!-- Lista zleceń spakowanych w danym dniu -->
                                <div class="space-y-2 mt-2 flex-1">
                                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Spakowane zlecenia (<?= count($rep['logs']) ?>):</span>
                                    <?php if (empty($rep['logs'])): ?>
                                        <div class="text-xs text-slate-500 italic py-2 text-center">Brak wpisów w tym dniu</div>
                                    <?php else: ?>
                                        <div class="space-y-1.5 max-h-60 overflow-y-auto pr-1">
                                            <?php foreach ($rep['logs'] as $log): ?>
                                                <div class="app-subcard p-2.5 flex items-center justify-between text-xs">
                                                    <div>
                                                        <span class="font-mono font-bold text-indigo-300 block">#<?= htmlspecialchars($log['order_number']) ?></span>
                                                        <span class="text-[10px] text-slate-400"><?= date('H:i:s', strtotime($log['packed_at'])) ?></span>
                                                    </div>
                                                    <span class="bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 px-2 py-0.5 rounded font-mono font-semibold">
                                                        <?= htmlspecialchars($log['items_count']) ?> szt.
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- MODAL: Ręczne dodanie wpisu pakowania -->
            <div id="manual-add-modal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
                <div class="app-card max-w-md w-full p-6 space-y-5">
                    <div class="flex justify-between items-center border-b border-slate-700 pb-3">
                        <h3 class="text-base font-bold text-white">Dodaj wpis pakowania ręcznie</h3>
                        <button type="button" onclick="closeManualAddModal()" class="text-slate-400 hover:text-white">✕</button>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider block mb-1.5">Numer zamówienia</label>
                            <input type="text" id="manual-add-order" placeholder="np. 8930" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2.5 text-white font-mono text-sm focus:outline-none focus:border-indigo-500">
                        </div>
                        <div>
                            <label class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider block mb-1.5">Osoba pakująca</label>
                            <select id="manual-add-packer" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2.5 text-white text-sm focus:outline-none focus:border-indigo-500">
                                <?php foreach ($packers as $p): ?>
                                    <option value="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider block mb-1.5">Liczba sztuk</label>
                                <input type="number" id="manual-add-count" min="1" value="1" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2.5 text-white font-mono text-sm focus:outline-none focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider block mb-1.5">Data</label>
                                <input type="date" id="manual-add-date" value="<?= htmlspecialchars($selectedDate) ?>" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2.5 text-white font-mono text-sm focus:outline-none focus:border-indigo-500">
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" onclick="closeManualAddModal()" class="bg-slate-800 hover:bg-slate-700 text-slate-300 px-4 py-2 rounded-xl text-xs font-medium transition-colors">Anuluj</button>
                        <button type="button" id="manual-add-submit" onclick="submitManualAdd()" class="bg-emerald-600 hover:bg-emerald-500 text-white px-5 py-2 rounded-xl text-xs font-bold transition-colors">Zapisz wpis</button>
                    </div>
                </div>
            </div>

            <script>
            function openManualAddModal() {
                document.getElementById('manual-add-order').value = '';
                document.getElementById('manual-add-count').value = 1;
                document.getElementById('manual-add-modal').classList.remove('hidden');
                document.getElementById('manual-add-order').focus();
            }
            function closeManualAddModal() {
                document.getElementById('manual-add-modal').classList.add('hidden');
            }
            function submitManualAdd() {
                const orderNumber = document.getElementById('manual-add-order').value.trim().replace(/^#/, '');
                const packerName = document.getElementById('manual-add-packer').value;
                const itemsCount = parseInt(document.getElementById('manual-add-count').value, 10);
                const manualDate = document.getElementById('manual-add-date').value;

                if (!orderNumber || !packerName || !(itemsCount > 0) || !manualDate) {
                    alert('Uzupełnij numer zamówienia, osobę, liczbę sztuk (> 0) i datę.');
                    return;
                }

                const btn = document.getElementById('manual-add-submit');
                btn.disabled = true;

                const body = new URLSearchParams({
                    action: 'manual_add_log',
                    order_number: orderNumber,
                    packer_name: packerName,
                    items_count: itemsCount,
                    manual_date: manualDate
                });

                fetch('handle_packing.php', { method: 'POST', body: body })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            window.location.href = 'index.php?tab=packing&report_date=' + encodeURIComponent(manualDate);
                        } else {
                            alert('Błąd: ' + (data.message || 'Nieznany błąd'));
                        }
                    })
                    .catch(() => alert('Błąd połączenia z serwerem.'))
                    .finally(() => { btn.disabled = false; });
            }
            </script>

        <?php elseif ($activeTab === 'manual'): ?>
            <div class="app-card p-8 max-w-2xl mx-auto space-y-6">
                <div>
                    <h2 class="text-lg font-bold text-white">Ręczne generowanie arkuszy / Doróbki</h2>
                    <p class="text-xs text-slate-400 mt-1">Wklej listę imion oddzielonych przecinkami lub nową linią. System sprawdzi stan stocku.</p>
                </div>
                <form id="manual-form" action="generate_boards.php" method="POST" class="space-y-5">
                    <textarea id="manual-names-input" name="manual_names" rows="7" class="w-full p-4 app-subcard focus:border-indigo-500 text-sm font-mono text-slate-100 outline-none" placeholder="np. Kasia, Bartek..."></textarea>
                    <button type="button" onclick="checkStockBeforeManualGenerate()" class="bg-emerald-600 hover:bg-emerald-500 text-white font-medium py-3 px-6 rounded-xl text-sm w-full transition-colors">Wygeneruj arkusze</button>
                </form>
            </div>

            <div id="stock-popup-modal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
                <div class="app-card max-w-md w-full p-6 space-y-5">
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <span>📦</span> Znaleziono bombki na stocku!
                    </h3>
                    <p class="text-xs text-slate-300 leading-relaxed">
                        Następujące bombki z wpisanych braków mamy już gotowe w magazynie stockowym:
                    </p>
                    <div id="stock-popup-list" class="app-subcard p-3 text-xs font-mono text-amber-300 max-h-36 overflow-y-auto space-y-1"></div>
                    <p class="text-xs text-slate-400">
                        Czy chcesz je automatycznie zarezerwować? Zostaną odjęte ze stocku i pominięte przy cięciu nowych arkuszy.
                    </p>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" onclick="proceedManualGenerate(false)" class="bg-slate-800 hover:bg-slate-700 text-slate-300 px-4 py-2 rounded-xl text-xs font-medium transition-colors">
                            Nie, tnij wszystko
                        </button>
                        <button type="button" onclick="proceedManualGenerate(true)" class="bg-amber-500 hover:bg-amber-400 text-slate-950 px-5 py-2 rounded-xl text-xs font-bold transition-colors">
                            Tak, zarezerwuj i pomiń
                        </button>
                    </div>
                </div>
            </div>

            <script>
            function checkStockBeforeManualGenerate() {
                const rawText = document.getElementById('manual-names-input').value;
                if (!rawText.trim()) {
                    alert('Wpisz jakieś imiona.');
                    return;
                }

                fetch('check_manual_stock.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'names=' + encodeURIComponent(rawText)
                })
                .then(res => res.json())
                .then(data => {
                    if (data.found_on_stock && data.found_on_stock.length > 0) {
                        let listHtml = '';
                        data.found_on_stock.forEach(item => {
                            listHtml += `<div>• ${item}</div>`;
                        });
                        document.getElementById('stock-popup-list').innerHTML = listHtml;
                        document.getElementById('stock-popup-modal').classList.remove('hidden');
                    } else {
                        document.getElementById('manual-form').submit();
                    }
                })
                .catch(err => {
                    document.getElementById('manual-form').submit();
                });
            }

            function proceedManualGenerate(shouldReserve) {
                document.getElementById('stock-popup-modal').classList.add('hidden');
                const form = document.getElementById('manual-form');
                
                if (shouldReserve) {
                    let input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'reserve_matched_stock';
                    input.value = '1';
                    form.appendChild(input);
                }
                form.submit();
            }
            </script>
        <?php endif; ?>

    </div>

    <div id="packer-modal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
        <div class="app-card max-w-lg w-full p-6 space-y-6 shadow-2xl relative border-slate-600">
            <div class="flex justify-between items-center border-b border-slate-700 pb-4">
                <h3 class="text-lg font-bold text-white">Pakowanie zamówienia <span id="modal-order-number" class="text-indigo-400 font-mono">#0</span></h3>
                <button type="button" onclick="closePackerModal()" class="text-slate-400 hover:text-white">✕</button>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <?php foreach ($packers as $p): ?>
                    <button type="button" onclick="selectPacker('<?= htmlspecialchars($p['name']) ?>')" class="app-subcard p-5 text-center hover:border-indigo-500 font-bold text-slate-100">
                        <?= htmlspecialchars($p['name']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="pt-4 border-t border-slate-700/60 flex justify-between">
                <button type="button" onclick="removePackingStatus()" class="text-rose-400 hover:text-rose-300 text-xs font-semibold">Usuń z pakowania</button>
            </div>
        </div>
    </div>

    <div id="typo-modal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
        <div class="app-card max-w-md w-full p-6 space-y-5">
            <h3 class="text-base font-bold text-white">Popraw literówkę</h3>
            <input type="text" id="typo-old-name" readonly class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-rose-300 font-mono text-xs">
            <input type="text" id="typo-new-name" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2.5 text-white font-mono text-xs">
            <div class="flex justify-end gap-3">
                <button type="button" onclick="closeTypoModal()" class="bg-slate-800 text-slate-300 px-4 py-2 rounded-xl text-xs">Anuluj</button>
                <button type="button" onclick="submitTypoFix()" class="bg-indigo-600 text-white px-5 py-2 rounded-xl text-xs">Zapisz</button>
            </div>
        </div>
    </div>

    <script>
    let activeModalOrderNumber = null;
    let typoOrderNumber = null;
    let typoTargetName = null;

    function handleRowClick(event, row) {
        if (event.target.tagName === 'BUTTON' || event.target.tagName === 'A' || event.target.tagName === 'INPUT' || event.target.closest('button') || event.target.closest('a')) {
            return;
        }
        const cb = row.querySelector('.order-checkbox');
        if (cb) {
            cb.checked = !cb.checked;
        }
    }

    function reserveStockAction(actionType, orderNumber, baubleName) {
        fetch('handle_stock_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=' + encodeURIComponent(actionType) + '&order_number=' + encodeURIComponent(orderNumber) + '&bauble_name=' + encodeURIComponent(baubleName)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Błąd: ' + data.message);
            }
        });
    }

    function filterOrdersTable() {
        const query = document.getElementById('order-search-input').value.toLowerCase().trim();
        const selectedPacker = document.getElementById('packer-filter-select').value;
        document.querySelectorAll('#orders-table tbody tr.app-table-row').forEach(row => {
            const searchData = row.getAttribute('data-search') || '';
            const packerData = row.getAttribute('data-packer') || '';
            const matchesSearch = query === '' || searchData.includes(query);
            let matchesPacker = true;
            if (selectedPacker !== '') {
                matchesPacker = (selectedPacker === '__unpacked__') ? (packerData === '__unpacked__') : (packerData === selectedPacker);
            }
            row.style.display = (matchesSearch && matchesPacker) ? '' : 'none';
        });
    }

    function openPackerModal(orderNumber) {
        activeModalOrderNumber = orderNumber;
        document.getElementById('modal-order-number').innerText = '#' + orderNumber;
        document.getElementById('packer-modal').classList.remove('hidden');
    }
    function closePackerModal() { document.getElementById('packer-modal').classList.add('hidden'); }

    function selectPacker(packerName) {
        if (!activeModalOrderNumber) return;
        
        fetch('handle_packing.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=assign_packer&order_number=' + encodeURIComponent(activeModalOrderNumber) + '&packer_name=' + encodeURIComponent(packerName)
        })
        .then(res => res.json())
        .then(data => { 
            if (data.success) {
                const rows = document.querySelectorAll('#orders-table tbody tr.app-table-row');
                rows.forEach(row => {
                    const orderNumAttr = row.getAttribute('data-order-number');
                    if (orderNumAttr === activeModalOrderNumber) {
                        row.setAttribute('data-packer', packerName);
                        row.classList.remove('bg-red-950/60', 'is-processed');
                        row.classList.add('bg-emerald-950/40', 'border-l-4', 'border-l-emerald-500');
                        
                        const statusCell = row.querySelector('.status-cell');
                        if (statusCell) {
                            statusCell.innerHTML = `<span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 shadow-sm">Pakowanie: ${packerName}</span>`;
                        }
                    }
                });
                
                closePackerModal();
                filterOrdersTable();
            } else {
                alert('Błąd: ' + data.message);
            }
        });
    }

    function removePackingStatus() {
        if (!activeModalOrderNumber) return;
        
        fetch('handle_packing.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=remove_packer&order_number=' + encodeURIComponent(activeModalOrderNumber)
        })
        .then(res => res.json())
        .then(data => { 
            if (data.success) {
                const rows = document.querySelectorAll('#orders-table tbody tr.app-table-row');
                rows.forEach(row => {
                    const orderNumAttr = row.getAttribute('data-order-number');
                    if (orderNumAttr === activeModalOrderNumber) {
                        row.setAttribute('data-packer', '__unpacked__');
                        row.classList.remove('bg-emerald-950/40', 'border-l-4', 'border-l-emerald-500');
                        
                        const statusCell = row.querySelector('.status-cell');
                        if (statusCell) {
                            statusCell.innerHTML = `<span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-slate-900 text-slate-400 border border-slate-700">Zdjęto pakowanie</span>`;
                        }
                    }
                });
                closePackerModal();
                filterOrdersTable();
            } else {
                alert('Błąd: ' + data.message);
            }
        });
    }

    function openTypoModal(orderNum, target) {
        typoOrderNumber = orderNum; typoTargetName = target;
        document.getElementById('typo-old-name').value = target;
        document.getElementById('typo-new-name').value = target;
        document.getElementById('typo-modal').classList.remove('hidden');
    }
    function closeTypoModal() { document.getElementById('typo-modal').classList.add('hidden'); }

    function submitTypoFix() {
        const newName = document.getElementById('typo-new-name').value.trim();
        fetch('handle_missing.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=fix_typo&order_number=' + encodeURIComponent(typoOrderNumber) + '&target_name=' + encodeURIComponent(typoTargetName) + '&new_name=' + encodeURIComponent(newName)
        }).then(res => res.json()).then(data => { if (data.success) location.reload(); });
    }

    function removeMissingItem(orderNum, target) {
        if (!confirm('Usunąć?')) return;
        fetch('handle_missing.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=remove_item&order_number=' + encodeURIComponent(orderNum) + '&target_name=' + encodeURIComponent(target)
        }).then(res => res.json()).then(data => { if (data.success) location.reload(); });
    }

    function toggleSelectAllOrders(master) {
        document.querySelectorAll('.app-table-row').forEach(row => {
            if (row.style.display !== 'none') {
                const cb = row.querySelector('.order-checkbox');
                if (cb) cb.checked = master.checked;
            }
        });
    }

    function selectUnprocessedOrders() {
        document.querySelectorAll('.app-table-row').forEach(row => {
            if (row.style.display !== 'none') {
                const cb = row.querySelector('.order-checkbox');
                const isProcessed = row.getAttribute('data-processed') === '1';
                if (cb) {
                    cb.checked = !isProcessed;
                }
            }
        });
    }

    function toggleSelectAllBoards(master) {
        document.querySelectorAll('.board-checkbox').forEach(cb => cb.checked = master.checked);
    }
    function submitBoardsForm(actionType) {
        document.getElementById('boards-action-type').value = actionType;
        document.getElementById('boards-form').submit();
    }

    function fetchOrders() {
        const btn = document.getElementById('fetch-btn');
        const statusMsg = document.getElementById('status-message');
        btn.disabled = true;
        fetch('fetch_orders.php')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    statusMsg.className = 'p-4 rounded-xl text-sm bg-emerald-950/40 border-emerald-800/60 text-emerald-300';
                    statusMsg.innerText = `Synchronizacja zakończona. Pobrano: ${data.total_fetched}.`;
                    statusMsg.classList.remove('hidden');
                    setTimeout(() => location.reload(), 800);
                } else {
                    alert('Błąd: ' + data.message);
                }
            })
            .finally(() => { btn.disabled = false; });
    }
    </script>
</body>
</html>