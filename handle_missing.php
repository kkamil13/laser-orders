<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/parser_rules.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$orderNumber = trim($_POST['order_number'] ?? '');
$targetName = trim($_POST['target_name'] ?? '');

if (empty($orderNumber) || empty($targetName)) {
    echo json_encode(['success' => false, 'message' => 'Brakujące dane zamówienia lub imienia.']);
    exit;
}

// Pobieramy zamówienie z bazy
$stmt = $db->prepare("SELECT id, names_field FROM orders WHERE order_number = ?");
$stmt->execute([$orderNumber]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo json_encode(['success' => false, 'message' => 'Nie znaleziono zamówienia.']);
    exit;
}

$namesField = $order['names_field'];
$parsedNames = parseNamesWithQuantities($namesField);

// 1. POPRAWA LITERÓWKI
if ($action === 'fix_typo') {
    $newCorrectName = trim($_POST['new_name'] ?? '');
    if (empty($newCorrectName)) {
        echo json_encode(['success' => false, 'message' => 'Podaj poprawną nazwę.']);
        exit;
    }

    // Podmieniamy błędną nazwę na poprawną w surowym tekście zamówienia
    // Szukamy elastycznie w tekście i zastępujemy
    $updatedNamesField = str_ireplace($targetName, $newCorrectName, $namesField);

    $stmtUp = $db->prepare("UPDATE orders SET names_field = ? WHERE id = ?");
    $stmtUp->execute([$updatedNamesField, $order['id']]);

    echo json_encode(['success' => true]);
    exit;
}

// 2. USUNIĘCIE POZYCJI Z ZAMÓWIENIA
if ($action === 'remove_item') {
    // Usuwamy wystąpienie tej pozycji z surowego pola imion
    // Dzielimy po przecinkach lub nowej linii i filtrujemy
    $lines = preg_split('/[,\n\r]+/', $namesField);
    $filteredLines = [];

    foreach ($lines as $line) {
        if (mb_stripos(trim($line), $targetName) === false) {
            $filteredLines[] = trim($line);
        }
    }

    $updatedNamesField = implode(', ', array_filter($filteredLines));

    $stmtUp = $db->prepare("UPDATE orders SET names_field = ? WHERE id = ?");
    $stmtUp->execute([$updatedNamesField, $order['id']]);

    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Nieznana akcja.']);