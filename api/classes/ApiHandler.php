<?php

require_once 'ResponseHelper.php';
require_once 'DataService.php';

class ApiHandler
{
    private DataService $dataService;

    public function __construct() {
        $this->dataService = new DataService();
    }

    public function getStraten(array $params = []): void {
        try {
            $straten = $this->dataService->getStraten();
            ResponseHelper::json($straten);
        } catch (Exception $e) {
            $this->logAndReturnError($e, 'getStraten');
        }
    }

    public function getTijdvakken(array $params = []): void {
        try {
            $tijdvakken = $this->dataService->getTijdvakken();
            ResponseHelper::json($tijdvakken);
        } catch (Exception $e) {
            $this->logAndReturnError($e, 'getTijdvakken');
        }
    }

    public function getJaarPanden(array $params = []): void {
        try {
            $jaar = $params['jaar'] ?? null;

            if (empty($jaar) || intval($jaar) == 0) {
                ResponseHelper::error('Missend of ongeldig jaartal.', 400, 'MISSING_JAAR');
                return;
            }

            $polygonen = $this->dataService->getJaarPanden(intval($jaar));
            ResponseHelper::geoJson($polygonen);
        } catch (Exception $e) {
            $this->logAndReturnError($e, 'getJaarPanden');
        }
    }

    public function getPanden(array $params = []): void {
        try {
            $q = ResponseHelper::getQueryParam('q');
            $straatidentifier = ResponseHelper::getQueryParam('straat');
            $status = ResponseHelper::getQueryParam('status', 'alle');
            $limit = ResponseHelper::getIntQueryParam('limit', 10);
            $offset = ResponseHelper::getIntQueryParam('offset', 0);
            $tijdvakidentifier = ResponseHelper::getQueryParam('tijdvak');

            if (!$this->validateStraat($straatidentifier) || !$this->validateTijdvak($tijdvakidentifier)) {
                return;
            }

            if (!in_array($status, ['alle', 'afgebroken', 'bestaand'], true)) {
                ResponseHelper::error('Ongeldige zoekvraag.', 422, 'INVALID_QUERY');
                return;
            }

            $result = $this->dataService->getPanden($q, $straatidentifier, $tijdvakidentifier, $status, $limit, $offset);

            if (empty($result['panden'])) {
                ResponseHelper::error('Geen panden gevonden.', 404, 'NOT_FOUND');
                return;
            }

            ResponseHelper::json($result);
        } catch (Exception $e) {
            $this->logAndReturnError($e, 'getPanden');
        }
    }

    public function getPersonen(array $params = []): void {
        try {
            $q = ResponseHelper::getQueryParam('q');
            $straat = ResponseHelper::getQueryParam('straat');
            $tijdvak = ResponseHelper::getQueryParam('tijdvak');
            $limit = ResponseHelper::getIntQueryParam('limit', 10);
            $offset = ResponseHelper::getIntQueryParam('offset', 0);

            if (!$this->validateStraat($straat) || !$this->validateTijdvak($tijdvak)) {
                return;
            }

            $result = $this->dataService->getPersonen($q, $straat, $tijdvak, $limit, $offset);

            if (empty($result['personen'])) {
                ResponseHelper::error('Geen personen gevonden.', 404, 'NOT_FOUND');
                return;
            }

            ResponseHelper::json($result);
        } catch (Exception $e) {
            $this->logAndReturnError($e, 'getPersonen');
        }
    }

    public function getFotos(array $params = []): void {
        try {
            $q = ResponseHelper::getQueryParam('q');
            $straat = ResponseHelper::getQueryParam('straat');
            $tijdvak = ResponseHelper::getQueryParam('tijdvak');
            $limit = ResponseHelper::getIntQueryParam('limit', 10);
            $offset = ResponseHelper::getIntQueryParam('offset', 0);

            if (!$this->validateStraat($straat) || !$this->validateTijdvak($tijdvak)) {
                return;
            }

            $result = $this->dataService->getFotos($q, $straat, $tijdvak, $limit, $offset);

            if (empty($result['fotos'])) {
                ResponseHelper::error('Geen foto\'s gevonden.', 404, 'NOT_FOUND');
                return;
            }

            ResponseHelper::json($result);
        } catch (Exception $e) {
            $this->logAndReturnError($e, 'getFotos');
        }
    }

    public function getPersoon(array $params): void {
        try {
            $identifier = $this->validateAndDecodeIdentifier($params['identifier'] ?? null);
            if ($identifier === null) {
                return;
            }

            $persoon = $this->dataService->getPersoon($identifier);

            if (!$persoon) {
                ResponseHelper::error('Persoon niet gevonden.', 404, 'NOT_FOUND');
                return;
            }

            ResponseHelper::json($persoon);
        } catch (Exception $e) {
            $this->logAndReturnError($e, 'getPersoon');
        }
    }

    public function getPand(array $params): void {
        try {
            $identifier = $this->validateAndDecodeIdentifier($params['identifier'] ?? null);
            if ($identifier === null) {
                return;
            }

            $pand = $this->dataService->getPand($identifier);

            if (!$pand) {
                ResponseHelper::error('Pand niet gevonden.', 404, 'NOT_FOUND');
                return;
            }

            ResponseHelper::json($pand);
        } catch (Exception $e) {
            $this->logAndReturnError($e, 'getPand');
        }
    }

    public function getFoto(array $params): void {
        try {
            $identifier = $this->validateAndDecodeIdentifier($params['identifier'] ?? null);
            if ($identifier === null) {
                return;
            }

            $foto = $this->dataService->getFoto($identifier);

            if (!$foto) {
                ResponseHelper::error('Foto niet gevonden.', 404, 'NOT_FOUND');
                return;
            }

            ResponseHelper::json($foto);
        } catch (Exception $e) {
            $this->logAndReturnError($e, 'getFoto');
        }
    }

    public function clearCache(array $params = []): void {
        try {
            $deletedCount = 0;
            $errors = [];

            // Clear SPARQL cache
            try {
                $deletedCount += $this->clearDirectory(CACHE_DIR . 'sparql/', '*.json');
            } catch (Exception $e) {
                $errors[] = "SPARQL cache: " . $e->getMessage();
                error_log("Failed to clear SPARQL cache: " . $e->getMessage());
            }

            // Clear panden cache
            try {
                $deletedCount += $this->clearDirectory(CACHE_DIR . 'panden/', '*.json');
            } catch (Exception $e) {
                $errors[] = "Panden cache: " . $e->getMessage();
                error_log("Failed to clear panden cache: " . $e->getMessage());
            }

            $response = [
                'success' => empty($errors),
                'deleted_files' => $deletedCount,
                'message' => empty($errors)
                    ? "Successfully cleared {$deletedCount} cache files"
                    : "Cleared {$deletedCount} files with some errors",
                'errors' => $errors
            ];

            ResponseHelper::json($response);
        } catch (Exception $e) {
            $this->logAndReturnError($e, 'clearCache');
        }
    }

    private function clearDirectory(string $directory, string $pattern = '*'): int {
        if (!is_dir($directory)) {
            throw new Exception("Directory does not exist: {$directory}");
        }

        if (!is_writable($directory)) {
            throw new Exception("Directory is not writable: {$directory}");
        }

        $files = glob($directory . $pattern);
        if ($files === false) {
            throw new Exception("Failed to read directory: {$directory}");
        }

        $deletedCount = 0;
        foreach ($files as $file) {
            if (is_file($file)) {
                if (unlink($file)) {
                    $deletedCount++;
                } else {
                    error_log("Failed to delete file: {$file}");
                }
            }
        }

        return $deletedCount;
    }

    private function validateStraat(?string $straat): bool {
        if ($straat && !filter_var($straat, FILTER_VALIDATE_URL)) {
            ResponseHelper::error('Ongeldige zoekvraag.', 422, 'INVALID_QUERY');
            return false;
        }
        return true;
    }

    private function validateTijdvak(?string $tijdvak): bool {
        if (!empty($tijdvak) && !$this->dataService->lijsten->valid_tijdvak($tijdvak)) {
            ResponseHelper::error('Ongeldige zoekvraag.', 422, 'INVALID_QUERY');
            return false;
        }
        return true;
    }

    private function validateAndDecodeIdentifier(?string $identifier): ?string {
        if (empty($identifier)) {
            ResponseHelper::error('Missende of ongeldige identifier.', 400, 'MISSING_IDENTIFIER');
            return null;
        }

        $identifier = urldecode($identifier);

        if (!filter_var($identifier, FILTER_VALIDATE_URL) || !$this->startsWithArk($identifier)) {
            ResponseHelper::error('Missende of ongeldige identifier.', 400, 'INVALID_IDENTIFIER');
            return null;
        }

        return $identifier;
    }

    private function startsWithArk(string $url): bool {
        $prefix = "https://n2t.net/ark:/60537/";
        return str_starts_with($url, $prefix);
    }

    private function logAndReturnError(Exception $e, string $method): void {
        #error_log("ApiHandler::{$method} - " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        ResponseHelper::error('Er heeft zich een onverwachte fout voorgedaan in ApiHandler::{$method}.', 500, 'INTERNAL_ERROR');
    }
}