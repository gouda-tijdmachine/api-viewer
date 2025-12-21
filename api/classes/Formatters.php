<?php

class Formatters {

    private $months = [
        "01" => "januari", "02" => "februari", "03" => "maart", 
        "04" => "april",   "05" => "mei",      "06" => "juni", 
        "07" => "juli",    "08" => "augustus", "09" => "september", 
        "10" => "oktober", "11" => "november", "12" => "december"
    ];

    public function adrestypeFormatter($string) {
        if (!empty($string)) {
            $string = str_replace("https://www.goudatijdmachine.nl/def#","", $string);
        }
        return $string;
    }

    public function beroepFormatter($beroep) {
        if (!empty($beroep)) {
            $beroep = strtolower($beroep);
            $beroep = str_replace("https://iisg.amsterdam/resource/hsn/occupation/","", $beroep);
            #$beroep = ucfirst($beroep);
            if ($beroep == "geen" || $beroep == "zonder") { 
                $beroep .= " beroep"; 
            }
        }
        return $beroep;
    }

    public function datumFormatter($dateString) {
        if ($dateString === null) {
            return null;
        }

        // Detect format YYYY-MM-DD
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateString, $matches)) {
            $day   = (int)$matches[3];
            $month = $matches[2];
            $year  = $matches[1];
        } 
        // Detect format DD-MM-YYYY
        elseif (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $dateString, $matches)) {
            $day   = (int)$matches[1];
            $month = $matches[2];
            $year  = $matches[3];
        } 
        else {
            error_log("WARN: ongeldig datum in API-viewer::datumFormatter > ".$dateString);
            return $dateString;
        }

        $monthName = isset($this->months[$month]) ? $this->months[$month] : $month;

        return "$day $monthName $year";
    }

    public function locatiepuntFormatter($pandStr) {
        if ($pandStr === null) {
            return null;
        }
        $pandStr = preg_replace("/Locatiepunt L[0-9]+,/","Pand", $pandStr);
        return $pandStr;
    }

    public function recenteAdressenFormatter($adressen) {
        if (empty($adressen)) return "";

        $currentYear = (int)date("Y");
        $rawAdresses = explode('|', $adressen);
        $parsedAdresses = [];

        foreach ($rawAdresses as $item) {
            // 1. Check for the existence of years in format (start-end) or (start-)
            // This regex captures the address part and the end year specifically.
            if (preg_match('/^(.*?)\s*\((\d{4})-(\d*)\)/', $item, $matches)) {
                $addressName = trim($matches[1]);
                #$startYear = (int)$matches[2];
                $endYearRaw = $matches[3];

                // If end year is empty, use current year
                $endYear = ($endYearRaw === '') ? $currentYear : (int)$endYearRaw;

                // 2. Clean the address: remove ", wijk .*$" or "/ wijk .*$"
                $cleanAddress = preg_replace('/[,\/]\s*wijk\s.*$/i', '', $addressName);

                $parsedAdresses[] = [
                    'name' => trim($cleanAddress),
                    'endYear' => $endYear
                ];
            }
        }

        if (empty($parsedAdresses)) return "";

        // 3. Sort the array based on endYear descending
        usort($parsedAdresses, function($a, $b) {
            return $b['endYear'] <=> $a['endYear'];
        });

        // 4. Find the highest year present in the set
        $maxYear = $parsedAdresses[0]['endYear'];
        $recentAdresses = [];

        // 5. Collect all addresses that share this maximum year
        foreach ($parsedAdresses as $entry) {
            if ($entry['endYear'] === $maxYear) {
                $recentAdresses[] = $entry['name'];
            } else {
                // Since it's sorted, we can stop once the year drops
                break;
            }
        }

        // 6. Return unique names (to handle potential duplicates) joined by ", "
        return "Pand recent bekend als ".implode(', ', array_unique($recentAdresses));       
    }

}