<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/parser_rules.php';

// Dodawanie nowych imion na stock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_stock') {
    $rawText = $_POST['stock_names'] ?? '';
    $parsedNames = parseNamesWithQuantities($rawText);

    $stmtIns = $db->prepare("INSERT INTO stock_baubles (bauble_name, normalized_name, status) VALUES (?, ?, 'available')");
    foreach ($parsedNames as $name) {
        $cleanName = trim($name);
        if (!empty($cleanName)) {
            $norm = mb_strtolower($cleanName);
            $stmtIns->execute([$cleanName, $norm]);
        }
    }
    header('Location: stock.php');
    exit;
}

// Usuwanie ze stocku
if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    $id = (int)$_GET['id'];
    // Jeśli była rezerwacja, usuwamy też wpis rezerwacji
    $db->prepare("DELETE FROM order_reservations WHERE stock_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM stock_baubles WHERE id = ?")->execute([$id]);
    header('Location: stock.php');
    exit;
}

$stockItems = $db->query("SELECT * FROM stock_baubles ORDER BY status ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Gotowych Bombek — FlowWorks CNC</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css?v=<?= time(); ?>">
</head>
<body class="min-h-screen antialiased">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
        
        <header class="app-card p-6 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-bold text-white">Stock Gotowych Bombek</h1>
                <p class="text-xs text-slate-400 mt-0.5">Zarządzaj zapasami bombek z poprzednich lat gotowych do rezerwacji.</p>
            </div>
            <a href="index.php?tab=orders" class="bg-slate-900 hover:bg-slate-800 text-slate-200 border border-slate-700 font-medium px-4 py-2 rounded-xl text-xs transition-colors">
                ← Powrót do Zamówień
            </a>
        </header>

        <div class="app-card p-6 space-y-4">
            <h2 class="text-sm font-bold text-slate-200 uppercase tracking-wider">Dodaj imiona na stock</h2>
            <form action="stock.php" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="add_stock">
                <textarea name="stock_names" rows="4" class="w-full p-4 app-subcard focus:border-indigo-500 text-sm font-mono text-slate-100 placeholder-slate-500 outline-none transition-all" placeholder="Wklej imiona (np. Kasia, 3x Bartek, Ania)..."></textarea>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 text-white font-medium py-2.5 px-6 rounded-xl text-xs transition-colors">
                    + Dodaj na stock
                </button>
            </form>
        </div>

        <div class="app-card overflow-hidden">
            <div class="px-6 py-4 app-card-header flex justify-between items-center">
                <h2 class="text-xs font-bold text-slate-300 uppercase tracking-wider">Stan magazynowy stocku (<?= count($stockItems) ?> poz.)</h2>
            </div>
            <div class="p-6">
                <?php if (empty($stockItems)): ?>
                    <p class="text-xs text-slate-500 italic text-center py-8">Brak bombek na stocku.</p>
                <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                        <?php foreach ($stockItems as $item): ?>
                            <?php 
                                $isReserved = ($item['status'] === 'reserved');
                                $cardStyle = $isReserved ? 'bg-amber-950/40 border-amber-500/50 text-amber-200' : 'app-subcard text-slate-200';
                            ?>
                            <div class="p-3.5 rounded-xl border flex items-center justify-between text-xs <?= $cardStyle ?>">
                                <div>
                                    <span class="font-bold text-sm block"><?= htmlspecialchars($item['bauble_name']) ?></span>
                                    <?php if ($isReserved): ?>
                                        <span class="text-[11px] text-amber-400 font-bold block mt-0.5">Zarezerwowane: #<?= htmlspecialchars($item['reserved_order_number']) ?></span>
                                    <?php else: ?>
                                        <span class="text-[11px] text-emerald-400 font-semibold block mt-0.5">Dostępne na stocku</span>
                                    <?php endif; ?>
                                </div>
                                <a href="stock.php?action=delete&id=<?= $item['id'] ?>" onclick="return confirm('Usunąć tę pozycję ze stocku?')" class="text-slate-500 hover:text-rose-400 p-1 transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</body>
</html>