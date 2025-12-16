<?php

class Lijsten {

    private array $tijdvakken = [];

    public function __construct() {
        $this->tijdvakken = [
            "https://n2t.net/ark:/60537/b01v5s3" => [1500, 1599],
            "https://n2t.net/ark:/60537/b01v5th" => [1600, 1699],
            "https://n2t.net/ark:/60537/b01v5vx" => [1700, 1799],
            "https://n2t.net/ark:/60537/b01v5wb" => [1800, 1899],
            "https://n2t.net/ark:/60537/b01v5xr" => [1900, 1949],
            "https://n2t.net/ark:/60537/b01v5z5" => [1950, 1999],
            "https://n2t.net/ark:/60537/b01v60z" => [2000, (int) date("Y")]
        ];
    }

    public function valid_tijdvak($tijdvakidentifier): bool {
        return isset($this->tijdvakken[$tijdvakidentifier]);
    }

    public function get_tijdvak($tijdvakidentifier): ?array {
        return $this->tijdvakken[$tijdvakidentifier] ?? null;
    }

}