<?php
/**
 * Logika parsowania imion, czyszczenia tekstu oraz skanowania plików SVG
 */

// Słowa szumowe / ignorowane
define('IGNORED_WORDS', [
    'szt', 'sztuk', 'sztuki', 'sztuka', 'pc', 'pcs',
    'bombka', 'bombki', 'bombek', 'imię', 'imie', 'imiona',
    'napis', 'napisy', 'komplet', 'zestaw', 'dla', 'oraz', 'w'
]);

// Frazy wielowyrazowe traktowane jako 1 pojedyncza bombka
define('SPECIAL_PHRASES', [
    'wesołych świąt',
    'wesolych swiat',
    'pierwsza gwiazdka',
    'pierwsze święta'
]);

/**
 * Parsuje ciąg znaków z pola imion i zwraca czystą tablicę imion z uwzględnieniem mnożników oraz fraz specjalnych.
 */
function parseNamesWithQuantities(string $rawString): array {
    if (empty(trim($rawString))) {
        return [];
    }

    $text = $rawString;
    $ignoredWords = IGNORED_WORDS;

    // KROK 0: Zabezpieczenie fraz wielowyrazowych (np. "Wesołych Świąt" -> "WesołychXSPACEXŚwiąt")
    foreach (SPECIAL_PHRASES as $phrase) {
        $pattern = '/\b' . preg_quote($phrase, '/') . '\b/ui';
        $text = preg_replace_callback($pattern, function($m) {
            return str_replace(' ', 'XSPACEX', $m[0]);
        }, $text);
    }

    // KROK 1: Mnożniki prefiksowe: "2xAnia", "2x Wesołych Świąt", "2 Bartek"
    $text = preg_replace_callback(
        '/\b(\d+)\s*[xX*]?\s*([a-zA-ZąćęłńóśźżĄĆĘŁŃÓŚŹŻXSPACEX]+)\b/u',
        function($m) use ($ignoredWords) {
            $qty = (int)$m[1];
            $name = $m[2];
            $checkName = str_replace('XSPACEX', ' ', mb_strtolower($name));
            if (in_array($checkName, $ignoredWords)) return '';
            return implode(', ', array_fill(0, $qty, $name));
        },
        $text
    );

    // KROK 2: Mnożniki sufiksowe: "Ania-x2", "Wesołych Świąt-2", "Ania 2szt"
    $text = preg_replace_callback(
        '/\b([a-zA-ZąćęłńóśźżĄĆĘŁŃÓŚŹŻXSPACEX]+)\s*[-_]?\s*(?:[xX*]|szt\.?)?\s*(\d+)\s*(?:szt\.?)?\b/u',
        function($m) use ($ignoredWords) {
            $name = $m[1];
            $qty = (int)$m[2];
            $checkName = str_replace('XSPACEX', ' ', mb_strtolower($name));
            if (in_array($checkName, $ignoredWords)) return '';
            return implode(', ', array_fill(0, $qty, $name));
        },
        $text
    );

    // KROK 3: Zamiana spójnika "i" / "I" / "oraz" (jako osobnego słowa) na przecinek
    $text = preg_replace('/(?<=\s|^)[iI](?=\s|$)/u', ',', $text);

    // KROK 4: Podział po separatorach (przecinek, spacja, średnik, kropka, dwukropek, myślnik, podłoga, ukośnik, enter)
    $tokens = preg_split('/[,\s;\.\:\/_\-\r\n]+/u', $text);

    $finalNames = [];
    foreach ($tokens as $token) {
        // Czyszczenie interpunkcji z brzegów oraz cyfr ze środka
        $clean = preg_replace('/^[^\p{L}]+|[^\p{L}]+$/u', '', trim($token));
        $clean = preg_replace('/[0-9]+/u', '', $clean);
        
        // Przywrócenie właściwej spacji wewnątrz frazy
        $clean = str_replace('XSPACEX', ' ', $clean);
        $cleanLower = mb_strtolower($clean);
        
        if ($clean !== '' && !in_array($cleanLower, $ignoredWords) && mb_strlen($clean) > 1) {
            $finalNames[] = mb_convert_case($clean, MB_CASE_TITLE, "UTF-8");
        }
    }

    return $finalNames;
}

/**
 * Skanuje katalog /imiona/ i zwraca mapę plików SVG [lowercase_name => exact_filename]
 */
function getAvailableSvgs(string $folderPath): array {
    $available = [];
    if (is_dir($folderPath)) {
        $files = scandir($folderPath);
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'svg') {
                $nameWithoutExt = pathinfo($file, PATHINFO_FILENAME);
                $key = mb_strtolower(trim($nameWithoutExt));
                $available[$key] = $file;
            }
        }
    }
    return $available;
}