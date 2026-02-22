<?php
ini_set('memory_limit','2048M');

// Polut CSV:ihin
$ip2File  = __DIR__ . "/../data/geo/ip2location.csv";      // IPv4
$ip2File6 = __DIR__ . "/../data/geo/ip2location-ipv6.csv"; // IPv6 (numerot stringinä)
$dbiFile  = __DIR__ . "/../data/geo/dbip.csv";             // IPv4 fallback
$outFile  = __DIR__ . "/../data/geo/index.php";

// Centroidit JSON
$centroidsFile = __DIR__ . "/../data/geo/country_centroids.json";
$centroids = file_exists($centroidsFile) ? json_decode(file_get_contents($centroidsFile), true) : [];

$index = [];

// Helper funktio: normalize country code
function normalizeCC($cc) {
    $cc = strtoupper(substr($cc ?? '-',0,2));
    return str_pad($cc,2,'-');
}

// --- IPv4 IP2Location ---
if (($f = fopen($ip2File, "r")) !== false) {
    while (($r = fgetcsv($f)) !== false) {
        if (!isset($r[0], $r[1], $r[2])) continue;
        if (!is_numeric($r[0]) || !is_numeric($r[1])) continue;

        $start = (int)$r[0];
        $end   = (int)$r[1];
        $cc    = normalizeCC($r[2]);

        $index[] = ['s'=>$start, 'e'=>$end, 'cc'=>$cc];
    }
    fclose($f);
}

// --- IPv6 IP2Location ---
if (($f = fopen($ip2File6, "r")) !== false) {
    while (($r = fgetcsv($f)) !== false) {
        if (!isset($r[0], $r[1], $r[2])) continue;

        $start = trim($r[0]);
        $end   = trim($r[1]);
        $cc    = normalizeCC($r[2]);

        // Varmistetaan, että arvo on numero-muotoinen string
        if (!is_numeric($start) || !is_numeric($end)) continue;

        $index[] = ['sVal'=>$start, 'eVal'=>$end, 'cc'=>$cc];
    }
    fclose($f);
}

// --- DB-IP IPv4 fallback ---
if (($f = fopen($dbiFile, "r")) !== false) {
    while (($r = fgetcsv($f)) !== false) {
        if (!isset($r[0], $r[1], $r[2])) continue;

        $start = ip2long($r[0]);
        $end   = ip2long($r[1]);
        if ($start === false || $end === false) continue;

        $cc = normalizeCC($r[2]);
        $index[] = ['s'=>$start, 'e'=>$end, 'cc'=>$cc];
    }
    fclose($f);
}

// Sortataan IPv4 int ja IPv6 string oikein
usort($index, function($a, $b) {
    $aVal = $a['s'] ?? $a['sVal'];
    $bVal = $b['s'] ?? $b['sVal'];

    if (is_int($aVal) && is_int($bVal)) return $aVal <=> $bVal;
    return bccomp((string)$aVal, (string)$bVal);
});

// Tallennetaan PHP-taulukkona
file_put_contents($outFile, "<?php\nreturn " . var_export([
    'ipIndex' => $index,
    'centroids' => $centroids
], true) . ";\n");

echo "Index built successfully: $outFile\n";