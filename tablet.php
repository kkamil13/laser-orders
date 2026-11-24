<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/parser_rules.php';

$selectedDate = $_GET['report_date'] ?? date('Y-m-d');
$viewMode = $_GET['view'] ?? 'orders';

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

// Pastelowa paleta kolorów dla pakowaczy w jasnej wersji
$packerColorPalette = [
    ['bg' => '#ecfdf5', 'border' => '#34d399', 'badge' => '#059669', 'text' => '#047857'], // Zielony
    ['bg' => '#f5f3ff', 'border' => '#a78bfa', 'badge' => '#7c3aed', 'text' => '#6d28d9'], // Fioletowy
    ['bg' => '#eff6ff', 'border' => '#60a5fa', 'badge' => '#2563eb', 'text' => '#1d4ed8'], // Niebieski
    ['bg' => '#fffbeb', 'border' => '#fbbf24', 'badge' => '#d97706', 'text' => '#b45309'], // Bursztynowy
    ['bg' => '#fff1f2', 'border' => '#fb7185', 'badge' => '#e11d48', 'text' => '#be123c'], // Różowy/Czerwony
    ['bg' => '#f0fdfa', 'border' => '#2dd4bf', 'badge' => '#0d9488', 'text' => '#0f766e'], // Cyjan/Morski
];

$packersColorMap = [];
foreach ($packers as $index => $p) {
    $packersColorMap[$p['name']] = $packerColorPalette[$index % count($packerColorPalette)];
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

$latestLogsRaw = $db->query("SELECT order_number, packer_name, packed_at FROM packing_logs ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$latestPackingMap = [];
foreach ($latestLogsRaw as $log) {
    $latestPackingMap[$log['order_number']] = [
        'packer_name' => $log['packer_name'],
        'packed_at' => $log['packed_at']
    ];
}

$processedOrders = [];
foreach ($rawOrders as $order) {
    $isOtherProduct = (bool)($order['is_other_product'] ?? false) || !$order['is_target_product'];
    $packingInfo = $latestPackingMap[$order['order_number']] ?? null;

    if ($isOtherProduct) {
        $processedOrders[] = [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'customer_name' => $order['customer_name'],
            'is_other_product' => true,
            'items_count' => (int)$order['ordered_quantity'],
            'parsed_names' => [],
            'names_with_stock' => [],
            'found_svgs' => [],
            'missing_svgs' => [],
            'packing_info' => $packingInfo
        ];
        continue;
    }
    
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

        // POPRAWKA: Sprawdzamy tylko, czy pula globalna ma sztuki > 0 (bez niszczenia licznika w pętli)
        $canReserve = false;
        if (!$isThisReserved && isset($stockPool[$lookupKey]) && $stockPool[$lookupKey] > 0) {
            $canReserve = true;
        }

        if ($hasSvg || $isThisReserved) {
            $foundSvgs[] = ['name' => $name, 'file' => $hasSvg ? $availableSvgs[$lookupKey] : 'stock'];
        } else {
            $missingSvgs[] = $name;
        }

        $namesWithStockStatus[] = [
            'name' => $name,
            'is_reserved' => $isThisReserved,
            'can_reserve' => $canReserve,
            'has_svg' => $hasSvg
        ];
    }

    $orderedQty = (int)$order['ordered_quantity'];
    $itemsCount = ($orderedQty > 0) ? $orderedQty : count($parsedNames);

    $processedOrders[] = [
        'id' => $order['id'],
        'order_number' => $order['order_number'],
        'customer_name' => $order['customer_name'],
        'is_other_product' => false,
        'items_count' => $itemsCount,
        'parsed_names' => $parsedNames,
        'names_with_stock' => $namesWithStockStatus,
        'found_svgs' => $foundSvgs,
        'missing_svgs' => $missingSvgs,
        'packing_info' => $packingInfo
    ];
}

// LOGIKA DLA RAPORTÓW NA TABLECIE
$packingReports = [];
$dateTotalOrders = 0;
$dateTotalItems = 0;

if ($viewMode === 'reports') {
    $stmtReport = $db->prepare("SELECT * FROM packing_logs WHERE DATE(packed_at) = ? ORDER BY packed_at DESC");
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
    <title>Pakowanie — Drewnolove</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css?v=<?= time(); ?>">
    <style>
        body { font-size: 15px; }
        .touch-btn { min-height: 48px; font-size: 16px; }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 antialiased">
    <div class="max-w-6xl mx-auto px-3 py-3 space-y-3">
        
        <!-- NAGŁÓWEK -->
        <header class="bg-white border border-slate-300 shadow-sm rounded-2xl p-4 flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="flex items-center space-x-3">
                <span class="w-3.5 h-3.5 rounded-full bg-emerald-500 animate-pulse"></span>
                <div>
                    <h1 class="text-lg font-black tracking-tight text-slate-900 leading-tight">STANOWISKO PAKOWANIA</h1>
                    <p class="text-xs text-slate-500">Tryb tabletowy • Drewnolove.pl</p>
                </div>
            </div>

            <div class="flex items-center space-x-2 w-full sm:w-auto justify-end flex-wrap gap-2">
                <a href="?view=orders" class="px-4 py-2.5 rounded-xl font-bold text-xs transition-all <?= $viewMode === 'orders' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 border border-slate-300 hover:bg-slate-200' ?>">
                    📦 Zamówienia (<?= count($processedOrders) ?>)
                </a>
                <a href="?view=reports" class="px-4 py-2.5 rounded-xl font-bold text-xs transition-all <?= $viewMode === 'reports' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 border border-slate-300 hover:bg-slate-200' ?>">
                    📊 Raporty Dnia
                </a>
            </div>
        </header>

        <?php if ($viewMode === 'orders'): ?>
            <!-- LISTA ZAMÓWIEŃ -->
            <div class="space-y-3">
                <div class="text-xs uppercase tracking-wider font-bold text-slate-600 px-1">Weryfikuj imiona i kliknij „PAKUJ”:</div>
                
                <div class="grid grid-cols-1 gap-3">
                    <?php foreach ($processedOrders as $order): ?>
                        <?php 
                            $pInfo = $order['packing_info'];
                            $isPacked = (bool)$pInfo;
                            $isOtherProduct = $order['is_other_product'];
                            $hasBoard = isset($generatedOrderNumbers[$order['order_number']]);
                            
                            $inlineStyle = '';
                            if ($isOtherProduct) {
                                $inlineStyle = 'background-color: #fff1f2; border-left: 8px solid #f43f5e; border-color: #f43f5e;';
                            } elseif ($isPacked) {
                                $packerName = $pInfo['packer_name'];
                                $cConfig = $packersColorMap[$packerName] ?? ['bg' => '#ecfdf5', 'border' => '#34d399', 'badge' => '#059669'];
                                $inlineStyle = 'background-color: ' . $cConfig['bg'] . '; border-left: 8px solid ' . $cConfig['border'] . '; border-color: ' . $cConfig['border'] . ';';
                            } else {
                                $inlineStyle = 'background-color: #ffffff; border: 1px solid #cbd5e1;';
                            }
                        ?>
                        <div class="shadow-sm rounded-2xl p-4 flex flex-col gap-3 transition-all" style="<?= $inlineStyle ?>">
                            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 border-b border-slate-200 pb-2.5">
                                <div class="flex items-center gap-3">
                                    <span class="text-2xl font-black font-mono text-slate-900">#<?= htmlspecialchars($order['order_number']) ?></span>
                                    <span class="text-base text-slate-700 font-semibold"><?= htmlspecialchars($order['customer_name']) ?></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <?php if ($isOtherProduct): ?>
                                        <span class="px-3 py-1 rounded-lg text-xs font-black bg-rose-500 text-white shadow-xs animate-pulse">
                                            ⚠️ Inne zamówienie - sprawdź!
                                        </span>
                                    <?php elseif ($isPacked): ?>
                                        <?php 
                                            $badgeBg = $packersColorMap[$pInfo['packer_name']]['badge'] ?? '#059669';
                                        ?>
                                        <span class="px-3 py-1 rounded-lg text-xs font-black text-white shadow-xs" style="background-color: <?= $badgeBg ?>;">
                                            ✓ SPAKOWANE: <?= htmlspecialchars($pInfo['packer_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="px-3 py-1 rounded-lg text-xs font-semibold bg-slate-100 text-slate-600 border border-slate-300">
                                            Oczekuje
                                        </span>
                                    <?php endif; ?>
                                    <span class="text-xs font-bold text-slate-800 font-mono bg-slate-200/60 px-3 py-1 rounded-lg border border-slate-300">
                                        <?= $order['items_count'] ?> szt.
                                    </span>
                                </div>
                            </div>

                            <!-- POWIĘKSZONE IMIONA LUB OSTRZEŻENIE DLA INNYCH PRODUKTÓW -->
                            <?php if ($isOtherProduct): ?>
                                <div class="py-2 text-rose-800 font-medium text-sm">
                                    Produkt niestandardowy / inny – sprawdź szczegóły w panelu WooCommerce.
                                </div>
                            <?php else: ?>
                                <div class="flex flex-col gap-1.5">
                                    <span class="text-[11px] uppercase tracking-wider font-bold text-slate-500">Imiona do spakowania:</span>
                                    <div class="flex flex-wrap gap-2 items-center">
                                        <?php foreach ($order['names_with_stock'] as $item): ?>
                                            <?php 
                                                // Rezerwacja dozwolona tylko wtedy, gdy zamówienie nie ma jeszcze wygenerowanej planszy i nie jest spakowane
                                                $canShowReserve = (!$hasBoard && !$pInfo);
                                            ?>
                                            <?php if ($item['is_reserved']): ?>
                                                <!-- Zarezerwowane ze stocku -->
                                                <span class="bg-amber-100 text-amber-900 border border-amber-300 text-sm md:text-base px-3.5 py-1.5 rounded-lg font-bold inline-flex items-center gap-2 shadow-xs">
                                                    ⭐ <?= htmlspecialchars($item['name']) ?> (Zarezerwowane!)
                                                    <?php if ($canShowReserve): ?>
                                                        <button type="button" onclick="reserveStockAction('unreserve_stock', '<?= htmlspecialchars($order['order_number']) ?>', '<?= htmlspecialchars($item['name']) ?>')" class="text-xs text-amber-800 hover:text-amber-950 ml-1.5 underline">Cofnij</button>
                                                    <?php endif; ?>
                                                </span>
                                            <?php elseif ($item['can_reserve'] && $canShowReserve): ?>
                                                <!-- Dostępne na stocku do rezerwacji -->
                                                <span class="bg-amber-100 text-amber-950 border border-amber-300 text-sm md:text-base px-3.5 py-1.5 rounded-lg font-bold inline-flex items-center gap-2 shadow-xs">
                                                    📦 <?= htmlspecialchars($item['name']) ?>
                                                    <button type="button" onclick="reserveStockAction('reserve_stock', '<?= htmlspecialchars($order['order_number']) ?>', '<?= htmlspecialchars($item['name']) ?>')" class="bg-amber-500 hover:bg-amber-600 text-white font-bold px-2.5 py-1 rounded text-xs ml-1.5 transition-colors shadow-xs">Zarezerwuj</button>
                                                </span>
                                            <?php else: ?>
                                                <!-- Zwykłe SVG / Brakujące -->
                                                <?php if ($item['has_svg']): ?>
                                                    <span class="bg-emerald-50 text-emerald-900 border border-emerald-300 text-sm md:text-base px-3.5 py-1.5 rounded-lg font-bold shadow-xs">
                                                        ✓ <?= htmlspecialchars($item['name']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="bg-rose-50 text-rose-900 border border-rose-300 text-sm md:text-base px-3.5 py-1.5 rounded-lg font-black shadow-xs">
                                                        ✗ <?= htmlspecialchars($item['name']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="pt-2 flex justify-end">
                                <button type="button" onclick="openTabletModal('<?= htmlspecialchars($order['order_number']) ?>')" class="touch-btn w-full sm:w-auto bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-8 py-3 rounded-xl shadow-md transition-transform active:scale-95 flex items-center justify-center space-x-2 text-base">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                                    <span>PAKUJ / ZMIEŃ OSOBĘ</span>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php else: ?>
            <!-- RAPORTY DNIA -->
            <div class="space-y-4">
                <div class="bg-white border border-slate-300 shadow-sm rounded-2xl p-4 flex flex-col sm:flex-row items-center justify-between gap-3">
                    <form method="GET" action="tablet.php" class="flex items-center space-x-2.5 w-full sm:w-auto">
                        <input type="hidden" name="view" value="reports">
                        <label class="text-xs font-bold text-slate-700 uppercase">Wybierz dzień:</label>
                        <input type="date" name="report_date" value="<?= htmlspecialchars($selectedDate) ?>" onchange="this.form.submit()" class="bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm text-slate-900 font-mono focus:outline-none focus:border-indigo-500">
                    </form>

                    <div class="flex items-center space-x-3 w-full sm:w-auto justify-end">
                        <div class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-2 text-right">
                            <span class="text-[9px] text-slate-500 uppercase tracking-wider block font-semibold">Zamówienia spakowane (Dzień)</span>
                            <span class="text-base font-bold text-indigo-600 font-mono"><?= $dateTotalOrders ?> <span class="text-xs text-slate-500 font-normal">zlec.</span></span>
                        </div>
                        <div class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-2 text-right">
                            <span class="text-[9px] text-slate-500 uppercase tracking-wider block font-semibold">Bombki spakowane w sumie</span>
                            <span class="text-base font-bold text-emerald-600 font-mono"><?= $dateTotalItems ?> <span class="text-xs text-slate-500 font-normal">szt.</span></span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($packers as $packer): ?>
                        <?php 
                            $pName = $packer['name'];
                            $rep = $packingReports[$pName] ?? ['orders_count' => 0, 'items_sum_day' => 0, 'items_sum_total' => 0, 'logs' => []];
                            $pColor = $packersColorMap[$pName]['text'] ?? '#059669';
                        ?>
                        <div class="bg-white border border-slate-300 shadow-sm rounded-2xl overflow-hidden flex flex-col">
                            <div class="px-4 py-3 bg-slate-100 border-b border-slate-300 flex justify-between items-center">
                                <div class="flex items-center space-x-2.5">
                                    <span class="w-3 h-3 rounded-full" style="background-color: <?= $pColor ?>;"></span>
                                    <h3 class="font-bold text-slate-900 text-base"><?= htmlspecialchars($pName) ?></h3>
                                </div>
                            </div>

                            <div class="p-4 space-y-3 flex-1">
                                <div class="grid grid-cols-3 gap-2 text-center">
                                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-2.5">
                                        <span class="text-[9px] text-slate-500 uppercase tracking-wider block font-semibold">Zamówień</span>
                                        <span class="text-lg font-bold text-slate-900 font-mono"><?= $rep['orders_count'] ?></span>
                                    </div>
                                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-2.5">
                                        <span class="text-[9px] text-slate-500 uppercase tracking-wider block font-semibold">Bombki (Dzień)</span>
                                        <span class="text-lg font-bold text-emerald-600 font-mono"><?= $rep['items_sum_day'] ?></span>
                                    </div>
                                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-2.5">
                                        <span class="text-[9px] text-slate-500 uppercase tracking-wider block font-semibold">Bombki (Total)</span>
                                        <span class="text-lg font-bold text-amber-600 font-mono"><?= $rep['items_sum_total'] ?></span>
                                    </div>
                                </div>

                                <div class="space-y-1.5">
                                    <span class="text-[11px] font-bold text-slate-600 uppercase tracking-wider block">Lista w tym dniu:</span>
                                    <?php if (empty($rep['logs'])): ?>
                                        <p class="text-xs text-slate-400 italic text-center py-4">Brak spakowanych paczek dzisiaj</p>
                                    <?php else: ?>
                                        <div class="max-h-48 overflow-y-auto space-y-1.5 pr-1">
                                            <?php foreach ($rep['logs'] as $l): ?>
                                                <div class="bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs flex justify-between items-center">
                                                    <span class="font-mono font-bold text-slate-900">#<?= htmlspecialchars($l['order_number']) ?></span>
                                                    <span class="text-slate-500 text-[11px]"><?= date('H:i', strtotime($l['packed_at'])) ?></span>
                                                    <span class="font-bold text-amber-600 font-mono"><?= $l['items_count'] ?> szt.</span>
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
        <?php endif; ?>

    </div>

    <!-- MODAL WYBORU NA TABLECIE -->
    <div id="tablet-modal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs hidden flex items-center justify-center p-4">
        <div class="bg-white border border-slate-300 shadow-xl rounded-2xl max-w-md w-full p-6 space-y-5 relative">
            <div class="flex justify-between items-center border-b border-slate-200 pb-3">
                <div>
                    <h3 class="text-xl font-black text-slate-900 flex items-center gap-2">
                        <span>Zamówienie</span>
                        <span id="tablet-modal-order" class="text-indigo-600 font-mono">#0</span>
                    </h3>
                    <p class="text-xs text-slate-500 mt-0.5">Wybierz kto pakuje paczkę:</p>
                </div>
                <button type="button" onclick="closeTabletModal()" class="text-slate-400 hover:text-slate-700 p-1.5">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <?php foreach ($packers as $p): ?>
                    <button type="button" onclick="tabletAssignPacker('<?= htmlspecialchars($p['name']) ?>')" class="touch-btn bg-slate-50 border border-slate-200 hover:border-indigo-500 hover:bg-indigo-50 transition-all font-black text-lg text-slate-800 flex flex-col items-center justify-center gap-1.5 group py-4 rounded-xl cursor-pointer shadow-xs">
                        <span class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 group-hover:bg-indigo-600 group-hover:text-white transition-colors flex items-center justify-center text-base font-mono">
                            <?= mb_substr($p['name'], 0, 1) ?>
                        </span>
                        <span><?= htmlspecialchars($p['name']) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="pt-3 border-t border-slate-200 text-center">
                <button type="button" onclick="tabletRemovePacking()" class="text-rose-600 hover:text-rose-700 text-xs font-bold py-2 px-4 rounded-xl transition-colors">
                    ✕ Usuń status pakowania z tego zamówienia
                </button>
            </div>
        </div>
    </div>

    <script>
    let activeTabletOrder = null;

    function reserveStockAction(actionType, orderNumber, baubleName) {
        fetch('handle_packing.php', {
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

    function openTabletModal(orderNumber) {
        activeTabletOrder = orderNumber;
        document.getElementById('tablet-modal-order').innerText = '#' + orderNumber;
        document.getElementById('tablet-modal').classList.remove('hidden');
    }

    function closeTabletModal() {
        document.getElementById('tablet-modal').classList.add('hidden');
        activeTabletOrder = null;
    }

    function tabletAssignPacker(packerName) {
        if (!activeTabletOrder) return;

        fetch('handle_packing.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=assign_packer&order_number=' + encodeURIComponent(activeTabletOrder) + '&packer_name=' + encodeURIComponent(packerName)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                closeTabletModal();
                location.reload();
            } else {
                alert('Błąd: ' + (data.message || 'Nieznany błąd'));
            }
        })
        .catch(err => {
            alert('Błąd połączenia z serwerem.');
        });
    }

    function tabletRemovePacking() {
        if (!activeTabletOrder) return;
        if (!confirm('Usunąć status pakowania?')) return;

        fetch('handle_packing.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=remove_packer&order_number=' + encodeURIComponent(activeTabletOrder)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                closeTabletModal();
                location.reload();
            } else {
                alert('Błąd: ' + (data.message || 'Nieznany błąd'));
            }
        })
        .catch(err => {
            alert('Błąd połączenia z serwerem.');
        });
    }
    </script>
</body>
</html>