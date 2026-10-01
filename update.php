<?php

// versio-instanssit löytyvät täältä: https://learn.microsoft.com/fi-fi/microsoft-365/enterprise/microsoft-365-ip-web-service?view=o365-worldwide
// https://endpoints.office.com/version?ClientRequestId=b10c5ed1-bad1-445f-b386-b919946339a7
// Löytyy myös Github-dokumentaatiosta: https://github.com/MicrosoftDocs/microsoft-365-docs/blob/public/.github/workflows/M365Endpoints.yml 
// Germany-palvelu on luotu 24.1.2017: https://news.microsoft.com/europe/2017/01/24/office-365-germany-provides-unparalleled-data-security-regulated-organisations-europe/
// Germany endpointit olleet saatavilla 5.9.2018: https://techcommunity.microsoft.com/blog/microsoft_365blog/announcing-office-365-endpoint-categories-and-office-365-ip-address-and-url-web-/177638/replies/300570/

$urls = [
    "worldwide" => "https://endpoints.office.com/endpoints/worldwide?clientrequestid=",
    "china"     => "https://endpoints.office.com/endpoints/China?clientrequestid=",
    "us_gov_dod"    => "https://endpoints.office.com/endpoints/USGOVDoD?clientrequestid=",
    "us_gov_gcc"       => "https://endpoints.office.com/endpoints/USGOVGCCHigh?clientrequestid="
    "france"    => "https://endpoints.office.com/endpoints/france?clientrequestid=",
    "germany"    => "https://endpoints.office.com/endpoints/germany?clientrequestid=",
 ];

$dir = __DIR__ . "/data/raw/";

if (!is_dir($dir)) mkdir($dir, 0755, true);

function generate_guid() {
    if (function_exists('com_create_guid')) {
        return trim(com_create_guid(), '{}');
    } else {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // versio 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

foreach ($urls as $name => $url) {

    $id = generate_guid();
    $fullUrl = $url . $id;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["User-Agent: PHP-cURL"]);
    // Voit käyttää joko SSL-tarkistuksen pois (testiin) tai CA-bundlen kanssa
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        echo "cURL error for $name: " . curl_error($ch) . "\n";
    } elseif ($httpCode != 200) {
        echo "HTTP $httpCode error for $name\n";
    } else {
        file_put_contents($dir . $name . ".json", $response);
        echo "Updated: $name\n";
    }

    curl_close($ch);
}