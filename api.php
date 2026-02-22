<?php
header("Content-Type: application/json");
// Välimuisti selaimessa 60 minuuttia
header("Cache-Control: public, max-age=3600");

$cacheResult = __DIR__ . "/data/cache/result_cache.json";

// Palautetaan tulos heti välimuistitiedostosta, jos se löytyy
if(file_exists($cacheResult)) {
    readfile($cacheResult);
    exit();
}


require __DIR__.'/lib/GeoLookup.php';
$geo = new GeoLookup();

$dir = __DIR__ . "/data/raw/";
$cacheFile = __DIR__ . "/data/cache/geo_cache.json";

// Luodaan kansiot, jos ei ole
if (!is_dir($dir)) mkdir($dir, 0755, true);
if (!is_dir(dirname($cacheFile))) mkdir(dirname($cacheFile), 0755, true);

ini_set('memory_limit','5048M');
set_time_limit(200);

$files = glob($dir."*.json");

$cache = [];
$result = [];


// Ladataan välimuisti levylta
if(file_exists($cacheFile)) {
    $cache = json_decode(file_get_contents($cacheFile), true) ?: [];
}

function locate($ip, $geo, &$cache){
    $ip = explode("/",$ip)[0];

    if(isset($cache[$ip])) return $cache[$ip];

    $res = $geo->find($ip);
    return $cache[$ip] = $res;
}

function resolveDomainToIP($domain, $maxDepth = 5, $cacheTime = 3600) {
    // Poista wildcard ja "autodiscover." prefix
    $domainClean = preg_replace('/^\*\./', '', $domain);
    $domainClean = preg_replace('/^autodiscover\./i', '', $domain);

    // Välimuistihakemisto
    $cacheDir = __DIR__ . '/data/dns_cache';
    if(!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

    // Välimuistifilen nimi
    $cacheFile = $cacheDir . '/' . preg_replace('/[^a-z0-9_\-]/i', '_', $domainClean) . '.json';

    // Jos välimuisti on voimassa, palauta se
    if(file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime){
        $cached = file_get_contents($cacheFile);
        $ips = json_decode($cached, true);
        if(is_array($ips)) return $ips;
    }

    // Hae IP:t rekursiivisesti
    $ips = resolveRecursive($domainClean, $maxDepth);

    // Poista duplikaatit
    $ips = array_values(array_unique($ips));

    // Tallenna välimuistiin
    file_put_contents($cacheFile, json_encode($ips));

    return $ips;
}

function resolveRecursive($domain, $depth) {
    if ($depth <= 0) return [];

    $result = [];

    // Hae A, AAAA ja CNAME rekordeja oikein
    $records = @dns_get_record($domain, DNS_A | DNS_AAAA | DNS_CNAME);

    if (is_array($records)) {
        foreach ($records as $rec) {
            // IPv4
            if (!empty($rec['ip']) && filter_var($rec['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $result[] = $rec['ip'];
            }

            // IPv6
            if (!empty($rec['ipv6']) && filter_var($rec['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $result[] = $rec['ipv6'];
            }

            // Joissain PHP-versioissa AAAA tulee 'ip' kenttään
            if (!empty($rec['type']) && $rec['type'] === 'AAAA' && !empty($rec['ip'])) {
                if (filter_var($rec['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $result[] = $rec['ip'];
                }
            }

            // CNAME
            if (!empty($rec['target'])) {
                $cnameResults = resolveRecursive($rec['target'], $depth - 1);
                $result = array_merge($result, $cnameResults);
            }
        }
    }

    return array_values(array_unique($result));
}

foreach ($files as $file) {

    $data = json_decode(file_get_contents($file), true);
    if (!$data) continue;

    $dataset = [
        "file" => basename($file),
        "endpoints" => []
    ];

    foreach ($data as $row) {

        $ips = [];

        if (!empty($row["ips"])) {

            foreach ($row["ips"] as $ip) {
                $pos = locate($ip,$geo,$cache);
                $ips[] = [
                    "cidr"=>$ip,
                    "geo"=>$pos
                ];
            }

        } else if (!empty($row["urls"])) {
            // Jos ei ole IP:tä, yritetään resolvata URL:ista
            foreach($row["urls"] as $url){
                $resolvedIPs = resolveDomainToIP($url);
                foreach($resolvedIPs as $ip){
                    $pos = locate($ip,$geo,$cache);
                    $ips[] = [
                        "cidr"=>$ip,
                        "geo"=>$pos
                    ];
                }
            }
        }

        $dataset["endpoints"][] = [

            "id" => $row["id"] ?? null,
            "service" => $row["serviceArea"] ?? "",
            "display" => $row["serviceAreaDisplayName"] ?? "",
            "category" => $row["category"] ?? "",
            "required" => $row["required"] ?? false,
            "express" => $row["expressRoute"] ?? false,
            "notes" => $row["notes"] ?? "",

            "tcp" => $row["tcpPorts"] ?? "",
            "udp" => $row["udpPorts"] ?? "",

            "urls" => $row["urls"] ?? [],
            "ips" => $ips
        ];
    }

    $result[] = $dataset;
}

// Tallennetaan välimuisti levylle
file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT));

// Koko tulos välimuistiin
file_put_contents($cacheResult, json_encode([
    "generated" => date("c"),
    "datasets" => $result
], JSON_PRETTY_PRINT));

echo json_encode([
    "generated" => date("c"),
    "datasets" => $result
], JSON_PRETTY_PRINT);