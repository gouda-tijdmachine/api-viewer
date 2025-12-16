<?php

class Formatters {

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
        }
        return $beroep;
    }

    public function datumFormatter($dateStr) {
        if ($dateStr === null) {
            return null;
        }
        $date = DateTime::createFromFormat('Y-m-d', $dateStr);
        if ($date) {
            return $date->format('d-m-Y');
        }
        return $dateStr;
    }
}