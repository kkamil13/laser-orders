<?php
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/parser_rules.php';

header('Content-Type: application/json');

$wooUrl = defined('WC_URL') ? WC_URL : (defined('WOO_URL') ? WOO_URL : ($woo_url ?? null));
$wooCk  = defined('WC_CONSUMER_KEY') ? WC_CONSUMER_KEY : (defined('WOO_CK') ? WOO_CK : ($woo_ck ?? null));
$wooCs  = defined('WC_CONSUMER_SECRET') ? WC_CONSUMER_SECRET : (defined('WOO_CS') ? WOO_CS : ($woo_cs ?? null));

if (!$wooUrl || !$wooCk || !$wooCs) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Brak danych API WooCommerce w config.php.'
    ]);
    exit;
}

function apiFetch($url, $ck, $cs) {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $ck . ':' . $cs);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        
        $httpCode = 200;
        if (defined('CURLINFO_HTTP_CODE')) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } elseif (defined('CURLINFO_RESPONSE_CODE')) {
            $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        }

        curl_close($ch);

        if (!$curlError && $response !== false) {
            return ['code' => $httpCode, 'body' => $response];
        }
    }

    $auth = base64_encode($ck . ':' . $cs);
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Basic " . $auth . "\r\nUser-Agent: LaserCutManager/1.0\r\n",
            'timeout' => 30,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];

    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);

    $httpCode = 200;
    if (isset($http_response_header) && is_array($http_response_header)) {
        if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#i', $http_response_header[0], $matches)) {
            $httpCode = (int)$matches[1];
        }
    }

    if ($response === false) {
        throw new Exception("Błąd połączenia HTTP ze sklepem.");
    }

    return ['code' => $httpCode, 'body' => $response];
}

try {
    $allOrders = [];
    $page = 1;
    $perPage = 100;
    $maxPages = 20;

    do {
        $url = rtrim($wooUrl, '/') . '/wp-json/wc/v3/orders?status=processing&per_page=' . $perPage . '&page=' . $page;
        $res = apiFetch($url, $wooCk, $wooCs);

        if ($res['code'] !== 200 || !$res['body']) {
            throw new Exception("Błąd API WooCommerce (Kod HTTP: {$res['code']})");
        }

        $ordersData = json_decode($res['body'], true);
        if (empty($ordersData) || !is_array($ordersData)) {
            break;
        }

        $allOrders = array_merge($allOrders, $ordersData);
        $page++;
    } while (count($ordersData) === $perPage && $page <= $maxPages);

    $importedCount = 0;
    $updatedCount = 0;
    $deletedCount = 0;
    $lastOrderId = 0;

    $activeFetchedOrderNumbers = [];

    $stmtInsert = $db->prepare("
        INSERT INTO orders (
            order_number, customer_name, names_field, ordered_quantity, 
            is_target_product, is_other_product, raw_payload, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");

    // Celowo brak `names_field` w UPDATE, aby chronić ręczne poprawki literówek!
    $stmtUpdate = $db->prepare("
        UPDATE orders SET 
            customer_name = ?, 
            ordered_quantity = ?, 
            is_target_product = ?, 
            is_other_product = ?,
            raw_payload = ?,
            status = ? 
        WHERE order_number = ?
    ");

    $stmtCheck = $db->prepare("SELECT id FROM orders WHERE order_number = ?");

    $otherProductKeywords = ['literki', 'literka', 'wesołych świąt', 'wesolych swiat', 'zestaw', 'mix', 'ozdób', 'skarby'];

    foreach ($allOrders as $wOrder) {
        $orderNum = (string)$wOrder['id'];
        $activeFetchedOrderNumbers[] = (int)$wOrder['id'];
        $lastOrderId = max($lastOrderId, (int)$wOrder['id']);
        $orderStatus = (string)($wOrder['status'] ?? '');

        $customerName = trim(($wOrder['billing']['first_name'] ?? '') . ' ' . ($wOrder['billing']['last_name'] ?? ''));
        if (empty($customerName)) {
            $customerName = 'Klient #' . $orderNum;
        }

        $targetFieldText = '';
        $orderedQuantity = 0;
        $isTarget = false;
        $isOtherProduct = 0;

        if (isset($wOrder['line_items']) && is_array($wOrder['line_items'])) {
            foreach ($wOrder['line_items'] as $item) {
                $itemName = trim($item['name'] ?? '');
                $itemNameLower = mb_strtolower($itemName);

                // Sprawdzenie słów kluczowych dla "innych zamówień"
                foreach ($otherProductKeywords as $kw) {
                    if (str_contains($itemNameLower, $kw)) {
                        $isOtherProduct = 1;
                        break;
                    }
                }
                
                // Sprawdzenie czy to standardowa "Drewniana bombka z imieniem"
                if (mb_strtolower($itemName) === 'drewniana bombka z imieniem') {
                    $isTarget = true;
                    $orderedQuantity += (int)($item['quantity'] ?? 0);

                    if (isset($item['meta_data']) && is_array($item['meta_data'])) {
                        foreach ($item['meta_data'] as $meta) {
                            $metaKey = mb_strtolower($meta['key'] ?? '');
                            if (strpos($metaKey, 'imię') !== false || strpos($metaKey, 'imiona') !== false || strpos($metaKey, 'tekst') !== false || strpos($metaKey, 'grawer') !== false) {
                                $targetFieldText .= ' ' . ($meta['value'] ?? '');
                            }
                        }
                    }
                }
            }
        }

        // Jeśli produkt ma słowa kluczowe "innego zamówienia", nadpisujemy status docelowości
        if ($isOtherProduct) {
            $isTarget = false;
        }

        $stmtCheck->execute([$orderNum]);
        $existingId = $stmtCheck->fetchColumn();

        if ($existingId) {
            $stmtUpdate->execute([
                $customerName,
                $orderedQuantity,
                $isTarget ? 1 : 0,
                $isOtherProduct,
                json_encode($wOrder, JSON_UNESCAPED_UNICODE),
                $orderStatus,
                $orderNum
            ]);
            $updatedCount++;
        } else {
            $stmtInsert->execute([
                $orderNum,
                $customerName,
                trim($targetFieldText),
                $orderedQuantity,
                $isTarget ? 1 : 0,
                $isOtherProduct,
                json_encode($wOrder, JSON_UNESCAPED_UNICODE),
                $orderStatus
            ]);
            $importedCount++;
        }
    }

    if (!empty($activeFetchedOrderNumbers)) {
        $inClause = implode(',', array_map('intval', $activeFetchedOrderNumbers));
        
        // --- AUTOMATYCZNE CZYSZCZENIE STOCKU DLA ZAMÓWIEŃ ZNIKAJĄCYCH Z WOOCOMMERCE ---
        $ordersToDeleteStmt = $db->query("SELECT order_number FROM orders WHERE CAST(order_number AS INTEGER) NOT IN ($inClause)");
        $ordersToRemove = $ordersToDeleteStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($ordersToRemove)) {
            foreach ($ordersToRemove as $orderNum) {
                // Pobieramy dokładne ID sztuk ze stocku zarezerwowane przez to konkretne zamówienie
                $stmtStock = $db->prepare("SELECT stock_id FROM order_reservations WHERE order_number = ?");
                $stmtStock->execute([$orderNum]);
                $stockIds = $stmtStock->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($stockIds)) {
                    $placeholders = implode(',', array_fill(0, count($stockIds), '?'));
                    
                    // Usuwamy ze stocku TYLKO te konkretne fizyczne sztuki, które pojechały w paczce
                    $db->prepare("DELETE FROM stock_baubles WHERE id IN ($placeholders)")->execute($stockIds);
                    
                    // Usuwamy wpisy rezerwacji dla tego zamówienia
                    $db->prepare("DELETE FROM order_reservations WHERE order_number = ?")->execute([$orderNum]);
                }
            }
        }
        // --------------------------------------------------------------------------

        $deletedCount = $db->exec("DELETE FROM orders WHERE CAST(order_number AS INTEGER) NOT IN ($inClause)");
    } else {
        $deletedCount = $db->exec("DELETE FROM orders");
    }

    if ($lastOrderId > 0) {
        $db->prepare("UPDATE sync_state SET last_order_id = ?, last_sync_at = CURRENT_TIMESTAMP WHERE id = 1")->execute([$lastOrderId]);
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'imported_count' => $importedCount,
        'updated_count' => $updatedCount,
        'deleted_count' => $deletedCount,
        'total_fetched' => count($allOrders),
        'last_order_id' => $lastOrderId
    ]);

} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
