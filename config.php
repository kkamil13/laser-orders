<?php
// Konfiguracja WooCommerce API — klucze trzymamy w config.local.php (poza repozytorium).
// Szablon: config.local.example.php
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

try {
    $db = new PDO('sqlite:' . __DIR__ . '/database.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Stan synchronizacji
    $db->exec("CREATE TABLE IF NOT EXISTS sync_state (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        last_order_id INTEGER NOT NULL DEFAULT 0,
        last_sync_at DATETIME
    )");

    // Tabela zamówień
    $db->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY,
        order_number TEXT UNIQUE,
        customer_name TEXT,
        names_field TEXT,
        ordered_quantity INTEGER DEFAULT 0,
        is_target_product INTEGER DEFAULT 0,
        is_other_product INTEGER DEFAULT 0,
        is_processed INTEGER DEFAULT 0,
        status TEXT,
        raw_payload TEXT,
        created_at DATETIME
    )");

    // Tabela wygenerowanych arkuszy
    $db->exec("CREATE TABLE IF NOT EXISTS boards (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename TEXT UNIQUE,
        orders_summary TEXT,
        names_list TEXT,
        is_downloaded INTEGER DEFAULT 0,
        created_at DATETIME
    )");

    // Tabela osób pakujących
    $db->exec("CREATE TABLE IF NOT EXISTS packers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL
    )");
    
    // Tabela stocku gotowych bombek z poprzednich lat
    $db->exec("CREATE TABLE IF NOT EXISTS stock_baubles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        bauble_name TEXT NOT NULL,
        normalized_name TEXT NOT NULL,
        status TEXT DEFAULT 'available', -- 'available' lub 'reserved'
        reserved_order_number TEXT DEFAULT NULL,
        reserved_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Tabela powiązań rezerwacji ze stocku do zamówień
    $db->exec("CREATE TABLE IF NOT EXISTS order_reservations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_number TEXT NOT NULL,
        bauble_name TEXT NOT NULL,
        stock_id INTEGER NOT NULL
    )");

    // Tabela rejestru pakowania (Trwała historia do raportów)
    $db->exec("CREATE TABLE IF NOT EXISTS packing_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_number TEXT NOT NULL,
        packer_name TEXT NOT NULL,
        items_count INTEGER DEFAULT 0,
        packed_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Domyślne dodanie 4 osób pakujących przy pierwszym uruchomieniu
    $defaultPackers = ['Mama', 'Daria', 'Anka', 'Kinga'];
    $stmtCheckPacker = $db->prepare("SELECT COUNT(*) FROM packers WHERE name = ?");
    $stmtAddPacker = $db->prepare("INSERT INTO packers (name) VALUES (?)");
    foreach ($defaultPackers as $packer) {
        $stmtCheckPacker->execute([$packer]);
        if ($stmtCheckPacker->fetchColumn() == 0) {
            $stmtAddPacker->execute([$packer]);
        }
    }

    // Migracje kolumn
    $columns = $db->query("PRAGMA table_info(orders)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('is_processed', $columns)) {
        $db->exec("ALTER TABLE orders ADD COLUMN is_processed INTEGER DEFAULT 0");
    }
    if (!in_array('raw_payload', $columns)) {
        $db->exec("ALTER TABLE orders ADD COLUMN raw_payload TEXT");
    }
    if (!in_array('is_other_product', $columns)) {
        $db->exec("ALTER TABLE orders ADD COLUMN is_other_product INTEGER DEFAULT 0");
    }

    $db->exec("INSERT OR IGNORE INTO sync_state (id, last_order_id) VALUES (1, 0)");
} catch (PDOException $e) {
    die("Błąd połączenia z bazą danych: " . $e->getMessage());
}