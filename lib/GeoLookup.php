<?php
class GeoLookup {

    private array $ipIndex = [];
    private array $centroids = [];

    public function __construct(string $file = null) {
        ini_set('memory_limit','2048M'); // riittävästi muistia suurelle taulukolle

        // Ladataan IP-index PHP-taulukosta
        $file = $file ?? __DIR__ . "/../data/geo/index.php";
        if (!file_exists($file)) {
            throw new Exception("Geo index file not found: {$file}");
        }

        $data = require $file;
        $this->ipIndex   = $data['ipIndex'] ?? [];

        // Ladataan centroidit erikseen JSON:stä
        $centroidsFile = __DIR__ . '/../data/geo/country_centroids.json';
        $this->centroids = file_exists($centroidsFile)
            ? json_decode(file_get_contents($centroidsFile), true)
            : [];

        // Järjestetään taulukko start-arvon mukaan (IPv4 int, IPv6 desimaalimuoto string)
        usort($this->ipIndex, function($a, $b) {
            $aStart = $a['s'] ?? $a['sVal'] ?? null;
            $bStart = $b['s'] ?? $b['sVal'] ?? null;

            if ($aStart === null || $bStart === null) return 0;

            if (is_int($aStart) && is_int($bStart)) return $aStart <=> $bStart;
            return bccomp((string)$aStart, (string)$bStart);
        });
    }


    private function ipToNum(string $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $num = ip2long($ip);
            return $num === false ? null : $num;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->ipv6ToDecimal($ip);
        }
        return null;
    }

    private function ipv6ToDecimal(string $ip) : ?string {
        $bin = inet_pton($ip);
        if ($bin === false) return null;

        $hex = bin2hex($bin);
        $dec = '0';
        for ($i=0; $i<strlen($hex); $i++) {
            $dec = bcmul($dec,'16');
            $dec = bcadd($dec,(string)hexdec($hex[$i]));
        }
        return $dec;
    }

    private function compare($a, $b): int {
        if (is_int($a) && is_int($b)) return $a <=> $b;
        return bccomp((string)$a, (string)$b);
    }

    public function find(string $ip): ?array {
        $ip = explode("/", $ip)[0];
        $ipNum = $this->ipToNum($ip);
        if ($ipNum === null) return null;

        $low = 0;
        $high = count($this->ipIndex) - 1;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $r = $this->ipIndex[$mid];

            $start = $r['s'] ?? $r['sVal'];
            $end   = $r['e'] ?? $r['eVal'];

            if ($start === null || $end === null) {
                $low = $mid + 1;
                continue;
            }

            if (!is_int($start)) $start = (string)$start;
            if (!is_int($end))   $end   = (string)$end;

            if ($this->compare($ipNum, $start) < 0) {
                $high = $mid - 1;
            } elseif ($this->compare($ipNum, $end) > 0) {
                $low = $mid + 1;
            } else {
                $cc  = $r['cc'];
                $lat = $this->centroids[$cc][0] ?? null;
                $lon = $this->centroids[$cc][1] ?? null;
                $country = $this->centroids[$cc][2] ?? null;
                $gdpr = $this->centroids[$cc][3] ?? null;

                return [
                    'lat' => $lat,
                    'lon' => $lon,
                    'cc'  => $cc,
                    'country' => $country,
                    'gdpr' => $gdpr
                ];
            }
        }

        return null;
    }
}