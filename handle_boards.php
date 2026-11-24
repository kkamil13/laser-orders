<?php
require_once __DIR__ . '/config.php';

$actionType = $_POST['action_type'] ?? $_GET['action_type'] ?? '';

// Pobieranie pojedynczego pliku SVG
if ($actionType === 'download_single' && isset($_GET['id'])) {
    $bId = (int)$_GET['id'];
    $stmt = $db->prepare("SELECT * FROM boards WHERE id = ?");
    $stmt->execute([$bId]);
    $board = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($board && file_exists(__DIR__ . '/plansze/' . $board['filename'])) {
        // Oznaczenie arkusza jako pobrany
        $db->prepare("UPDATE boards SET is_downloaded = 1 WHERE id = ?")->execute([$bId]);

        $filePath = __DIR__ . '/plansze/' . $board['filename'];
        header('Content-Type: image/svg+xml');
        header('Content-Disposition: attachment; filename="' . $board['filename'] . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
    header('Location: index.php?tab=boards');
    exit;
}

$boardIds = $_POST['board_ids'] ?? [];

if (empty($boardIds)) {
    header('Location: index.php?tab=boards');
    exit;
}

$inClause = implode(',', array_map('intval', $boardIds));

// Usuwanie arkuszy
if ($actionType === 'delete') {
    $stmt = $db->query("SELECT filename FROM boards WHERE id IN ($inClause)");
    $files = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($files as $file) {
        if (file_exists(__DIR__ . '/plansze/' . $file)) {
            unlink(__DIR__ . '/plansze/' . $file);
        }
    }

    $db->exec("DELETE FROM boards WHERE id IN ($inClause)");
    header('Location: index.php?tab=boards');
    exit;
}

// Pobieranie paczki ZIP
if ($actionType === 'download_zip') {
    // Oznaczenie zaznaczonych jako pobrane
    $db->exec("UPDATE boards SET is_downloaded = 1 WHERE id IN ($inClause)");

    $stmt = $db->query("SELECT filename FROM boards WHERE id IN ($inClause)");
    $files = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $zip = new ZipArchive();
    $zipName = 'arkusze_svg_' . date('Y-m-d_H-i-s') . '.zip';
    $zipPath = sys_get_temp_dir() . '/' . $zipName;

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        foreach ($files as $file) {
            $fullPath = __DIR__ . '/plansze/' . $file;
            if (file_exists($fullPath)) {
                $zip->addFile($fullPath, $file);
            }
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        unlink($zipPath);
        exit;
    }
}

header('Location: index.php?tab=boards');
exit;