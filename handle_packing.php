<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/parser_rules.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (empty($action)) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
}

// 1. Przypisanie zamówienia do pakowacza + usunięcie ze stocku
if ($action === 'assign_packer') {
    $orderNumber = trim($_POST['order_number'] ?? $_GET['order_number'] ?? '');
    $packerName = trim($_POST['packer_name'] ?? $_GET['packer_name'] ?? '');

    if (empty($orderNumber) || empty($packerName)) {
        echo json_encode(['success' => false, 'message' => 'Brakujące dane zamówienia lub pakowacza.']);
        exit;
    }

    $db->prepare("DELETE FROM packing_logs WHERE order_number = ?")->execute([$orderNumber]);

    $stmt = $db->prepare("SELECT names_field, ordered_quantity FROM orders WHERE order_number = ?");
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    $itemsCount = 0;
    if ($order) {
        $parsedNames = parseNamesWithQuantities($order['names_field']);
        $orderedQty = (int)$order['ordered_quantity'];
        $itemsCount = ($orderedQty > 0) ? $orderedQty : count($parsedNames);
    }

    $db->prepare("INSERT INTO packing_logs (order_number, packer_name, items_count, packed_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)")
       ->execute([$orderNumber, $packerName, $itemsCount]);

    // ZEJŚCIE ZE STOCKU: Zarezerwowane sztuki znikają ostatecznie ze stocku!
    $stmtRes = $db->prepare("SELECT stock_id FROM order_reservations WHERE order_number = ?");
    $stmtRes->execute([$orderNumber]);
    $reservedStockIds = $stmtRes->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($reservedStockIds)) {
        $inStockIds = implode(',', array_map('intval', $reservedStockIds));
        $db->exec("DELETE FROM stock_baubles WHERE id IN ($inStockIds)");
        $db->exec("DELETE FROM order_reservations WHERE order_number = ?");
    }

    echo json_encode(['success' => true, 'order_number' => $orderNumber, 'packer_name' => $packerName]);
    exit;
}

// 1.2. Ręczne dodanie wpisu
if ($action === 'manual_add_log') {
    $orderNumber = trim($_POST['order_number'] ?? '');
    $packerName = trim($_POST['packer_name'] ?? '');
    $itemsCount = (int)($_POST['items_count'] ?? 0);
    $manualDate = trim($_POST['manual_date'] ?? date('Y-m-d'));

    if (empty($orderNumber) || empty($packerName) || $itemsCount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Wypełnij poprawnie dane.']);
        exit;
    }

    $dateObj = DateTime::createFromFormat('Y-m-d', $manualDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $manualDate) {
        echo json_encode(['success' => false, 'message' => 'Niepoprawna data.']);
        exit;
    }

    $packedAt = $manualDate . ' ' . date('H:i:s');
    $db->prepare("INSERT INTO packing_logs (order_number, packer_name, items_count, packed_at) VALUES (?, ?, ?, ?)")
       ->execute([$orderNumber, $packerName, $itemsCount, $packedAt]);

    echo json_encode(['success' => true]);
    exit;
}

// 1.5. Usunięcie z pakowania
if ($action === 'remove_packer') {
    $orderNumber = trim($_POST['order_number'] ?? $_GET['order_number'] ?? '');
    if (!empty($orderNumber)) {
        $db->prepare("DELETE FROM packing_logs WHERE order_number = ?")->execute([$orderNumber]);
        
        $stmtRes = $db->prepare("SELECT stock_id FROM order_reservations WHERE order_number = ?");
        $stmtRes->execute([$orderNumber]);
        $stockIds = $stmtRes->fetchAll(PDO::FETCH_COLUMN);
        foreach ($stockIds as $sId) {
            $db->prepare("UPDATE stock_baubles SET status = 'available', reserved_order_number = NULL WHERE id = ?")->execute([$sId]);
        }
        $db->prepare("DELETE FROM order_reservations WHERE order_number = ?")->execute([$orderNumber]);

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Brak numeru zamówienia.']);
    }
    exit;
}

// 2. Dodawanie osoby pakującej
if ($action === 'add_packer') {
    $name = mb_convert_case(trim($_POST['name'] ?? ''), MB_CASE_TITLE, "UTF-8");
    if (!empty($name)) {
        try {
            $db->prepare("INSERT INTO packers (name) VALUES (?)")->execute([$name]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Taka osoba już istnieje.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Podaj imię.']);
    }
    exit;
}

// 3. Usuwanie osoby pakującej
if ($action === 'delete_packer') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->prepare("DELETE FROM packers WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Błędne ID.']);
    }
    exit;
}

// 4. Rezerwacja / cofnięcie rezerwacji ze stocku
if ($action === 'reserve_stock' || $action === 'unreserve_stock') {
    $orderNumber = trim($_POST['order_number'] ?? '');
    $baubleName = trim($_POST['bauble_name'] ?? '');

    if (empty($orderNumber) || empty($baubleName)) {
        echo json_encode(['success' => false, 'message' => 'Brak danych.']);
        exit;
    }

    if ($action === 'reserve_stock') {
        $stmt = $db->prepare("SELECT id FROM stock_baubles WHERE LOWER(bauble_name) = LOWER(?) AND status = 'available' LIMIT 1");
        $stmt->execute([$baubleName]);
        $stockItem = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$stockItem) {
            echo json_encode(['success' => false, 'message' => 'Brak wolnej sztuki tego imienia na stocku!']);
            exit;
        }

        $stockId = $stockItem['id'];
        $db->prepare("UPDATE stock_baubles SET status = 'reserved', reserved_order_number = ?, reserved_at = CURRENT_TIMESTAMP WHERE id = ?")
           ->execute([$orderNumber, $stockId]);

        $db->prepare("INSERT INTO order_reservations (order_number, bauble_name, stock_id) VALUES (?, ?, ?)")
           ->execute([$orderNumber, $baubleName, $stockId]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'unreserve_stock') {
        $stmt = $db->prepare("SELECT stock_id FROM order_reservations WHERE order_number = ? AND bauble_name = ? LIMIT 1");
        $stmt->execute([$orderNumber, $baubleName]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($res) {
            $stockId = $res['stock_id'];
            $db->prepare("UPDATE stock_baubles SET status = 'available', reserved_order_number = NULL, reserved_at = NULL WHERE id = ?")->execute([$stockId]);
            $db->prepare("DELETE FROM order_reservations WHERE order_number = ? AND bauble_name = ?")->execute([$orderNumber, $baubleName]);
        }

        echo json_encode(['success' => true]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Nieznana akcja.']);