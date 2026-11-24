<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$orderNumber = trim($_POST['order_number'] ?? '');
$baubleName = trim($_POST['bauble_name'] ?? '');

if (empty($orderNumber) || empty($baubleName)) {
    echo json_encode(['success' => false, 'message' => 'Brak danych.']);
    exit;
}

if ($action === 'reserve') {
    // Sprawdzamy czy jest wolna sztuka na stocku o tej nazwie
    $stmt = $db->prepare("SELECT id FROM stock_baubles WHERE LOWER(bauble_name) = LOWER(?) AND status = 'available' LIMIT 1");
    $stmt->execute([$baubleName]);
    $stockItem = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stockItem) {
        echo json_encode(['success' => false, 'message' => 'Brak wolnej sztuki tego imienia na stocku! Została już zarezerwowana w innym zamówieniu.']);
        exit;
    }

    $stockId = $stockItem['id'];

    // Zmieniamy status na stocku na zarezerwowany
    $db->prepare("UPDATE stock_baubles SET status = 'reserved', reserved_order_number = ?, reserved_at = CURRENT_TIMESTAMP WHERE id = ?")
       ->execute([$orderNumber, $stockId]);

    // Zapisujemy powiązanie w tabeli rezerwacji
    $db->prepare("INSERT INTO order_reservations (order_number, bauble_name, stock_id) VALUES (?, ?, ?)")
       ->execute([$orderNumber, $baubleName, $stockId]);

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'unreserve') {
    // Cofnięcie rezerwacji
    $stmt = $db->prepare("SELECT stock_id FROM order_reservations WHERE order_number = ? AND LOWER(bauble_name) = LOWER(?) LIMIT 1");
    $stmt->execute([$orderNumber, $baubleName]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($res) {
        $stockId = $res['stock_id'];
        $db->prepare("UPDATE stock_baubles SET status = 'available', reserved_order_number = NULL, reserved_at = NULL WHERE id = ?")->execute([$stockId]);
        $db->prepare("DELETE FROM order_reservations WHERE order_number = ? AND LOWER(bauble_name) = LOWER(?)")->execute([$orderNumber, $baubleName]);
    }

    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Nieznana akcja.']);