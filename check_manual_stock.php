<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/parser_rules.php';

header('Content-Type: application/json');

$rawText = $_POST['names'] ?? '';
if (empty($rawText)) {
    echo json_encode(['found_on_stock' => []]);
    exit;
}

$parsedNames = parseNamesWithQuantities($rawText);
$foundOnStock = [];

// Pobieramy wszystkie aktualnie dostępne sztuki ze stocku
$availableStock = $db->query("SELECT id, bauble_name FROM stock_baubles WHERE status = 'available'")->fetchAll(PDO::FETCH_ASSOC);

$stockPool = [];
foreach ($availableStock as $st) {
    $norm = mb_strtolower(trim($st['bauble_name']));
    $stockPool[$norm][] = $st['id'];
}

// Sprawdzamy w locie bez trwałego modyfikowania stanu w bazie na tym etapie
foreach ($parsedNames as $name) {
    $lookupKey = mb_strtolower(trim($name));
    if (isset($stockPool[$lookupKey]) && count($stockPool[$lookupKey]) > 0) {
        $foundOnStock[] = $name;
        array_shift($stockPool[$lookupKey]);
    }
}

echo json_encode(['found_on_stock' => array_unique($foundOnStock)]);