#!/usr/bin/env bash
set -euo pipefail

# API Testing Script for Gouda Tijdmachine Viewer API
# Tests all endpoints defined in the OpenAPI specification (except for clear cache)

TOTAL_TESTS=0
PASSED_TESTS=0
TESTHTML="qa-results/index.html"

mkdir -p qa-results

echo '<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Viewer API testresultaten &raquo; Gouda Tijdmachine</title>
    <link rel="stylesheet" type="text/css" href="https://api-viewer.goudatijdmachine.nl/assets/swagger-ui.css" >
    <style>
    html { box-sizing: border-box; }
    *, *:before, *:after { box-sizing: inherit; }
    body { margin:0; background: #fafafa; font-family: sans-serif; padding:10px}
    h1 { background: url("https://api-viewer.goudatijdmachine.nl/assets/gtm-logo-2025.svg") no-repeat right center; background-size: 100px auto; line-height: 100px }
    h2 { color: #3795ad;margin:2em 0 1em 0}
    a, a:visited { color: #3795ad; text-decoration: none}
    h3 { padding-top:1em }
    ul { line-height: 1.5em }
    .fail { border-left:5px solid red }
    .pass { border-left:5px solid green }
    xmp { white-space: pre-wrap; word-wrap: break-word; overflow-wrap: break-word; width: 100%; box-sizing: border-box; max-height:100px; overflow-y:auto }
    </style>
    <link rel="icon" type="image/svg+xml" href="https://api-viewer.goudatijdmachine.nl/assets/gtm-logo-2025.svg">
</head>
<body>
<h1>Testresultaten Gouda Tijdmachine Viewer API</h1>
<summary>
' > $TESTHTML

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Function to print test header
print_test_header() {
    echo -e "\n${CYAN}===== Testing: $1 =====${NC}"
    echo "<h2>$1</h2>" >> $TESTHTML
}

# Function to test API endpoint
test_endpoint() {
    local method=$1
    local endpoint=$2
    local expected_status=$3
    local description=$4
    local printbody="${5:-}"

    TOTAL_TESTS=$((TOTAL_TESTS + 1))

    echo -e "\n${YELLOW}Test $TOTAL_TESTS: $description${NC}"
    echo "<h3>Test $TOTAL_TESTS: $description</h3>" >> $TESTHTML

    # First run to warm up
    start_time0=$(date +%s%3N)
    response0=$(curl -s -w "\n%{http_code}" -X $method "$endpoint")
    end_time0=$(date +%s%3N)
    response_time0=$((end_time - start_time))

    # Second run with filled cache
    start_time=$(date +%s%3N)
    response=$(curl -s -w "\n%{http_code}" -X $method "$endpoint")
    end_time=$(date +%s%3N)
    response_time=$((end_time - start_time))

    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | head -n -1)

    if [ "$http_code" -eq "$expected_status" ]; then
        echo -e "${GREEN}✓ PASS${NC} (${response_time}ms)"
        PASSED_TESTS=$((PASSED_TESTS + 1))
        echo "<ul class='pass'>" >> $TESTHTML
    else
        echo -e "${RED}✗ FAIL (expected $expected_status, got $http_code)${NC} (${response_time}ms)"
        echo "<ul class='fail'>" >> $TESTHTML
    fi

    echo "Request: $method $endpoint"
    echo "<li><strong>Request</strong>: $method <a href=""$endpoint"">$endpoint</a></li>" >> $TESTHTML

    echo "Response code: $http_code"
    echo "<li><strong>Response code</strong>: $http_code (expected $expected_status)</li>" >> $TESTHTML

    echo "Response time: ${response_time0}ms (no cache) / ${response_time}ms (with cache)"
    echo "<li><strong>Response time</strong>: ${response_time0}ms (no cache) / ${response_time}ms (with cache)</li>" >> $TESTHTML

    if [ -z "$printbody" ]; then
        echo "Response body: $body" | head -c 200
        #echo "<li><strong>Response body</strong>: <pre>$body</pre>" >> $TESTHTML
        if [ ${#body} -gt 200 ]; then
            echo "..."
        fi
        #echo "</xmp></li>" >> $TESTHTML
    fi
    echo "</ul>"  >> $TESTHTML

}

# Function to test endpoint with JSON validation
test_json_endpoint() {
    local method=$1
    local endpoint=$2
    local expected_status=$3
    local description=$4

    TOTAL_TESTS=$((TOTAL_TESTS + 1))

    # First run to warm up
    start_time0=$(date +%s%3N)
    response0=$(curl -s -w "\n%{http_code}" -X $method "$endpoint")
    end_time0=$(date +%s%3N)
    response_time0=$((end_time - start_time))

    # Second run with filled cache
    start_time=$(date +%s%3N)
    response=$(curl -s -w "\n%{http_code}" -X $method "$endpoint")
    end_time=$(date +%s%3N)
    response_time=$((end_time - start_time))

    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | head -n -1)

    echo -e "\n${YELLOW}Test $TOTAL_TESTS: $description${NC}"
    echo "<h3>Test $TOTAL_TESTS: $description</h3>" >> $TESTHTML

    # Check if response is valid JSON (only if status is 200)
    json_valid=true
    if [ "$http_code" -eq 200 ]; then
        echo "$body" | jq . > /dev/null 2>&1 || json_valid=false
    fi

    if [ "$http_code" -eq "$expected_status" ] && [ "$json_valid" = true ]; then
        echo -e "${GREEN}✓ PASS${NC} (${response_time}ms)"
        echo "<ul class='pass'>" >> $TESTHTML
        PASSED_TESTS=$((PASSED_TESTS + 1))
    else
        echo "<ul class='fail'>" >> $TESTHTML
        if [ "$http_code" -ne "$expected_status" ]; then
            echo -e "${RED}✗ FAIL (expected status $expected_status, got $http_code)${NC} (${response_time}ms)"
        elif [ "$json_valid" = false ]; then
            echo -e "${RED}✗ FAIL (invalid JSON response)${NC} (${response_time}ms)"
        fi
    fi

    echo "Request: $method $endpoint"
    echo "<li><strong>Request</strong>: $method <a href=""$endpoint"">$endpoint</a></li>" >> $TESTHTML

    echo "Response code: $http_code"
    echo "<li><strong>Response code</strong>: $http_code (expected $expected_status)</li>" >> $TESTHTML

    echo "Response time: ${response_time0}ms (no cache) / ${response_time}ms (with cache)"
    echo "<li><strong>Response time</strong>: ${response_time0}ms (no cache) / ${response_time}ms (with cache)</li>" >> $TESTHTML

    body_preview="${body:0:250}"    
    echo "Response body: $body_preview"
    echo "<li><strong>Response body</strong>: <xmp>$body_preview" >> $TESTHTML
    if [ ${#body} -gt 250 ]; then
        echo "..."
        echo "..." >> $TESTHTML
    fi
    echo "</xmp></li>" >> $TESTHTML

    echo "</ul>"  >> $TESTHTML
}

echo -e "${CYAN}Starting API Tests...${NC}"
echo "Base URL: $BASE_URL"

# Clear cache before testing
echo -e "\n${CYAN}Clearing API cache...${NC}"
response=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/clear_cache")
http_code=$(echo "$response" | tail -n1)
body=$(echo "$response" | head -n -1)

if [ "$http_code" -eq 200 ]; then
    echo -e "${GREEN}✓ Cache cleared successfully${NC}"
    echo "Response: $body"
else
    echo -e "${YELLOW}⚠ Cache clear returned status $http_code${NC}"
fi

# ** Lijsten

# GET /pandgeometrien/{jaar}
print_test_header "Lijsten - GET /pandgeometrieen/{jaar}"
test_json_endpoint "GET" "$BASE_URL/pandgeometrieen/1700" 200 "Geef een lijst met pand polygonen voor 1700"
test_json_endpoint "GET" "$BASE_URL/pandgeometrieen/test" 400 "Geef een lijst met pand polygonen ongeldig jaartal"

# GET /straten 
print_test_header "Lijsten - GET /straten"
test_json_endpoint "GET" "$BASE_URL/straten" 200 "Geef een lijst van straten"


# GET /straten 
print_test_header "Lijsten - GET /tijdvakken"
test_json_endpoint "GET" "$BASE_URL/tijdvakken" 200 "Geef een lijst van tijdvakken"


# ** Zoekresultaten

# GET /fotos
print_test_header "Zoekresultaten - GET /fotos"
test_json_endpoint "GET" "$BASE_URL/fotos" 200 "Lijst met zoekresultaten met foto's (geen parameters, dus 10 interessante foto's)"
test_json_endpoint "GET" "$BASE_URL/fotos?q=Kerk" 200 "Lijst met zoekresultaten met foto's met zoekterm"
test_json_endpoint "GET" "$BASE_URL/fotos?limit=5&offset=5" 200 "Lijst met zoekresultaten met foto's en paginering"
test_json_endpoint "GET" "$BASE_URL/fotos?straat=https%3A%2F%2Fn2t.net%2Fark%3A%2F60537%2FbjMurf" 200 "Lijst met zoekresultaten met foto's met geldige straat"
test_json_endpoint "GET" "$BASE_URL/fotos?straat=ongeldig" 422 "Lijst met zoekresultaten met foto's met ongeldige straat"
test_json_endpoint "GET" "$BASE_URL/fotos?tijdvak=https%3A%2F%2Fn2t.net%2Fark%3A%2F60537%2Fb01v5wb" 200 "Lijst met zoekresultaten met foto's met geldige tijdvak"
test_json_endpoint "GET" "$BASE_URL/fotos?tijdvak=ongeldig" 422 "Lijst met zoekresultaten met foto's met ongeldige tijdvak"

# GET /panden 
print_test_header "Zoekresultaten - GET /panden "
test_json_endpoint "GET" "$BASE_URL/panden" 200 "Lijst met zoekresultaten met panden (geen parameters, dus 10 interessante panden)"
test_json_endpoint "GET" "$BASE_URL/panden?q=Kerksteeg" 200 "Lijst met zoekresultaten met panden met zoekterm"
test_json_endpoint "GET" "$BASE_URL/panden?straat=https%3A%2F%2Fn2t.net%2Fark%3A%2F60537%2FbjMurf" 200 "Lijst met zoekresultaten met panden voor straat"
#test_json_endpoint "GET" "$BASE_URL/panden?status=afgebroken" 200 "Search buildings by status 'afgebroken'"
test_json_endpoint "GET" "$BASE_URL/panden?straat=https%3A%2F%2Fn2t.net%2Fark%3A%2F60537%2FbjMurf&limit=5&offset=5" 200 "Lijst met zoekresultaten met panden voor straat met paginering"
test_json_endpoint "GET" "$BASE_URL/panden?status=invalid" 422 "Search buildings with invalid status (should return 422)"

# GET /personen - Search people
print_test_header "Zoekresultaten - GET /personen"
test_json_endpoint "GET" "$BASE_URL/personen" 200 "Lijst met zoekresultaten met personen (geen parameters, dus 10 interessante personen)"
test_json_endpoint "GET" "$BASE_URL/personen?q=Maria" 200 "Lijst met zoekresultaten met personen met zoekterm"
test_json_endpoint "GET" "$BASE_URL/personen?limit=5&offset=5" 200 "Lijst met zoekresultaten met personen met zoekterm en paginering"

# ** Detailinformatie

# GET /foto/{identifier} 
print_test_header "Detailinformatie - GET /foto/{identifier} "
test_json_endpoint "GET" "$BASE_URL/foto/https%3A%2F%2Fn2t.net%2Fark%3A%2F60537%2Fb5PMTR" 200 "Geef detailinformatie over foto"
test_json_endpoint "GET" "$BASE_URL/foto/invalid-identifier" 400 "Geef detailinformatie over foto met ongeldige identifier"
test_json_endpoint "GET" "$BASE_URL/foto/https%3A%2F%2Fn2t.net%2Fark%3A%2F60537%2FbrRgQU6" 404 "Geef detailinformatie over foto met niet bestaande identifier"

# GET /pand/{identifier} 
print_test_header "Detailinformatie - GET /pand/{identifier}"
test_json_endpoint "GET" "$BASE_URL/pand/https%3A%2F%2Fn2t.net%2Fark%3A%2F60537%2FbVQ1Wc3" 200 "Geef detailinformatie over pand"
test_json_endpoint "GET" "$BASE_URL/pand/invalid-identifier" 400 "Geef detailinformatie over pand met ongeldige identifier"
test_json_endpoint "GET" "$BASE_URL/pand/https%3A%2F%2Fn2t.net%2Fark%3A%2F60537%2Fnotfound" 404 "Geef detailinformatie over pand met niet bestaande identifier"

# GET /persoon/{identifier} 
print_test_header "Detailinformatie - GET /persoon/{identifier} "
test_json_endpoint "GET" "$BASE_URL/persoon/https%3A%2F%2Fn2t.net%2Fark%3A%2F60537%2Fb8KpBLy" 200 "Geef detailinformatie over persoon"
test_json_endpoint "GET" "$BASE_URL/persoon/invalid-identifier" 400 "Geef detailinformatie over persoon met ongeldige identifier"
test_json_endpoint "GET" "$BASE_URL/persoon/https%3A%2F%2Fn2t.net%2Fark%3A%2F60537%2Fnotfound" 404 "Geef detailinformatie over persoon met niet bestaande identifier"

# ** HTTP check

# Test undefined route (should serve docs.html)
print_test_header "HTTP check - Documentatie"
test_endpoint "GET" "$BASE_URL/undefined-route" 200 "Ongedefineerde route moet leiden naar documentatie"

#  OPTIONS request (CORS preflight)
print_test_header "HTTP check - CORS"
test_endpoint "OPTIONS" "$BASE_URL/straten" 204 "OPTIONS request"

# Test SAMH links
print_test_header "HTTP check - Test SAMH links"
test_json_endpoint "GET" "https://images.memorix.nl/sahm/iiif/c8a7c04c-a2b4-99cf-3999-18d6b6478563/info.json" 200 "IIIF Image API"
test_endpoint "GET" "https://samh.nl/bronnen/beeldbank/detail/0a6ffb0c-7959-70e8-d995-4bc3a1d0d9df/media/c8a7c04c-a2b4-99cf-3999-18d6b6478563" 200 "SAMH beeldbank"
test_endpoint "GET" "https://samh.nl/bronnen/genealogie/deeds/a634024a-cac3-98ba-3d4f-77a5e270a5ec" 200 "SAMH akte pagina"

# Test Omeka thumbnails
print_test_header "HTTP check - Test Omeka thumbnails"
test_endpoint "GET" "https://www.goudatijdmachine.nl/omeka/files/medium/05a057c0734aeb68e67b609a35473ec977521a1f.jpg" 200 "Omeka thumbnail 1 die bestaat" "no"
test_endpoint "GET" "https://www.goudatijdmachine.nl/omeka/files/medium/f166c8fbec016f567150983214a2c46e99177c1e.jpg" 404 "Omeka thumbnail 2 die niet bestaat" "no"

# Summary
echo -e "\n${CYAN}===== Test Summary =====${NC}"
echo -e "Total Tests: $TOTAL_TESTS"
echo -e "Passed: ${GREEN}$PASSED_TESTS${NC}"
echo -e "Failed: ${RED}$((TOTAL_TESTS - PASSED_TESTS))${NC}"

echo -e "</body></html>" >> $TESTHTML

SUMFILE="summary.html"

if [ $PASSED_TESTS -eq $TOTAL_TESTS ]; then
    echo -e "\n${GREEN}🎉 All tests passed!${NC}"
    echo -e "<h2>Samenvatting: alle tests geslaagd</h2>" > $SUMFILE
else
    echo -e "\n${RED}❌ Some tests failed.${NC}"
    echo -e "<h2>Samenvatting: sommige tests gefaald</h2>" > $SUMFILE
fi

if [ $TOTAL_TESTS -eq $PASSED_TESTS ]; then
    echo "<ul class='pass'>" >> $SUMFILE
else
    echo "<ul class='fail'>" >> $SUMFILE
fi
echo "<li><strong>Basis URL</strong>: ${BASE_URL}" >> $SUMFILE
echo "<li><strong>Uitgevoerd</strong>: " >> $SUMFILE
echo $(TZ="Europe/Amsterdam" date +"%d-%m-%Y (%H:%M:%S)") >> $SUMFILE
echo "</li>" >> $SUMFILE
echo -e "<li><strong>Aantal tests</strong>: $TOTAL_TESTS</li>" >> $SUMFILE
echo -e "<li><strong>Geslaagd</strong>: $PASSED_TESTS</li>" >> $SUMFILE
echo -e "<li><strong>Gefaald</strong>: $((TOTAL_TESTS - PASSED_TESTS))</li></ul><p><br></p>" >> $SUMFILE

SUMMARY=$(awk '{printf "%s",$0}' $SUMFILE)
sed -i "s|<summary>|${SUMMARY}|" $TESTHTML

rm $SUMFILE

if [ $PASSED_TESTS -ne $TOTAL_TESTS ]; then
    exit 1
fi