<?php

declare(strict_types=1);

class SparqlService
{
    private CacheService $cache;

    public function __construct()
    {
        $this->cache = new CacheService();
    }

    private function doSPARQLcall($sparqlQueryString, $offset): ?string
    {
        if ($offset > 0) {
            $sparqlQueryString .= " OFFSET " . $offset;
        }
        $url = SPARQL_ENDPOINT . '?query=' . urlencode($sparqlQueryString);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_USERAGENT, SPARQL_CURL_UA);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $headers = ['Accept: application/sparql-results+json'];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            error_log("SPARQL call failed: " . curl_error($ch));
            curl_close($ch);

            return null;
        }

        curl_close($ch);

        return $response;
    }

    private function getSPARQLresults($sparqlQueryString, $offset = 0): ?array
    {
        $cache_key = md5($sparqlQueryString . $offset);
        $contents = $this->cache->get($cache_key);
        if (!$contents) {
            $contents = $this->doSPARQLcall($sparqlQueryString, $offset);
            if ($contents === null) {
                return null;
            }
            $this->cache->put($cache_key, $contents);
        }

        $result = json_decode($contents, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg());

            return null;
        }

        return $result;
    }

    private function SPARQL($sparqlQueryString, $bLog = SPARQL_LOG): array
    {
        $sparqlQueryString = preg_replace('/  /', ' ', SPARQL_PREFIX . $sparqlQueryString);

        if ($bLog == 1) {
            error_log("-1- " . $sparqlQueryString);
        }
        if ($bLog == 2) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);
            $callerFunction = $trace[1]['function'];
            $callerArgs = $trace[1]['args'];
            file_put_contents("sparql.log", "-------------\n\n" . $callerFunction . " > " . print_r($callerArgs, 1) . "\n\n" . $sparqlQueryString . "\n\n", FILE_APPEND);
        }

        $sparqlResult = $this->getSPARQLresults($sparqlQueryString);

        if ($sparqlResult === null) {
            return [];
        }

        if ($bLog == 1) {
            error_log("-2- " . json_encode($sparqlResult));
        }
        if ($bLog == 2) {
            file_put_contents("sparql.log", json_encode($sparqlResult, JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);
        }

        return $sparqlResult["results"]["bindings"] ?? [];
    }

    #--------------------

    public function get_straten(): array
    {
        return $this->SPARQL('
SELECT ?identifier ?naam (GROUP_CONCAT(DISTINCT ?altname; separator=", ") AS ?naam_alt) WHERE {
?identifier a gtm:Straat;
     schema:name ?naam ;
     schema:identifier ?id .
OPTIONAL { ?identifier schema:alternateName ?altname }
#FILTER(STR(?id) IN (
#    "https://www.goudatijdmachine.nl/id/straat/wijdstraat",  
#    "https://www.goudatijdmachine.nl/id/straat/kerksteeg",  
#    "https://www.goudatijdmachine.nl/id/straat/markt",
#    "https://www.goudatijdmachine.nl/id/straat/nieuwehaven",
#    "https://www.goudatijdmachine.nl/id/straat/achter-de-kerk"
#  ))
}
GROUP BY ?identifier ?naam
ORDER BY ?naam');
    }

    public function get_tijdvakken(): array
    {
        return $this->SPARQL('
SELECT ?identifier ?naam ?omschrijving (GROUP_CONCAT(DISTINCT ?altname; separator=", ") AS ?naam_alt) ?startjaar ?eindjaar WHERE {
  ?identifier a skos:Concept ;
              schema:name ?naam ;
              o:item_set <https://n2t.net/ark:/60537/b01v5rp> ;
              schema:startDate ?startjaar ;
              schema:description ?omschrijving .
  OPTIONAL { ?identifier schema:alternateName ?altname . }
  OPTIONAL { ?identifier schema:endDate ?jaar_eind . }
  BIND( COALESCE(?jaar_eind, YEAR(NOW())) AS ?eindjaar )
}
GROUP BY ?identifier ?naam ?omschrijving ?startjaar ?eindjaar
ORDER BY ?startjaar
');
    }

    public function get_panden_jaar($jaar): array
    {
        return $this->SPARQL('
SELECT ?identifier ?geometry ?naam ?locatiepunt (GROUP_CONCAT(?adres ; separator="|") AS ?adressen) WHERE {
  ?identifier a gtm:Pand ;
    schema:name ?naam ;
    schema:startDate ?startDate ;
    geo:hasGeometry/geo:asWKT ?geometry .	 
  OPTIONAL { ?identifier schema:endDate ?endDate }
  FILTER ( ?startDate <= "' . (int) $jaar . '" && (!BOUND(?endDate) || ?endDate >= "' . (int) $jaar . '") )
  OPTIONAL { 
    ?identifier geo:hasGeometry ?locatiepunt .
    ?s geo:hasGeometry ?locatiepunt ;
      a ?type ;
      schema:name ?adres .
    FILTER (?type IN (gtm:PlaatselijkeAanduiding, gtm:StraatNummerAanduiding, gtm:NummerAanduiding, gtm:Huisnaam))
    FILTER(ISIRI(?locatiepunt)) 
  }
} GROUP BY ?identifier ?geometry ?naam ?locatiepunt');
        # waarom zijn ?startDate en ?endDate literals en niet ^^xsd:int of ^^xsd:gYear ?
        # gaat nu goed doordat beide kanten van vergelijking ^^xsd:string zijn...
    }

    public function get_panden_index($q, $straatidentifier, $status, $tijdvak): array
    {
        # TODO: wat te doen met status (bestaand/afgebroken/alle)?

        # TODO: tijdvakfilter opnemen in SPARQL (datering mist nog)
        $tijdvakfilter = !empty($tijdvak) ? ' schema:startDate ?datering ; FILTER(?datering>="' . $tijdvak[0] . '"^^xsd:gYear && ?datering<="' . $tijdvak[1] . '"^^xsd:gYear ) ' : '';

        $searchfilter = "";
        $toptienfilter = "";
        $straatfilter = "";
        if (!empty($straatidentifier)) { // zoek op q met straat
            $straatfilter = "BIND(<" . $straatidentifier . "> AS ?straat)";
        }
        if (!empty($q)) {
            #$searchfilter=' ?text ql:contains-entity ?naam . ?text ql:contains-word "'.addslashes($q).'" . ';
            $searchfilter = ' FILTER(CONTAINS(LCASE(?naam), "' . addslashes(strtolower($q)) . '"))';
        } else {
            if (empty($straatidentifier)) {
                $topTien = [
                  "https://n2t.net/ark:/60537/bjzNjZZ",
                  "https://n2t.net/ark:/60537/bbFcwbs",
                  "https://n2t.net/ark:/60537/bVQ1Wc3",
                  "https://n2t.net/ark:/60537/bNVs7nX",
                  "https://n2t.net/ark:/60537/bG0RiAq",
                  "https://n2t.net/ark:/60537/by6guNT",
                  "https://n2t.net/ark:/60537/bqpompu",
                  "https://n2t.net/ark:/60537/bhuNxBC",
                  "https://n2t.net/ark:/60537/b1FCTVe",
                  "https://n2t.net/ark:/60537/bLQsjYp"
                ];
                $toptienfilter = "VALUES ?locatiepunt { <" . implode("> <", $topTien) . "> } ";
            }
        }

        return $this->SPARQL('
SELECT ?locatiepunt ?naam ?straat ?straatnaam WHERE {
  ' . $toptienfilter . ' ' . $straatfilter . '
  {
    ?locatiepunt a geo:Geometry ;
                 <http://omeka.org/s/vocabs/o#item_set> <https://n2t.net/ark:/60537/bsgGtno> ;
                 schema:mainEntityOfPage/o:label ?naam . ' . $searchfilter . '
  }
  {
    {
      ?uri geo:hasGeometry ?locatiepunt ;
           a ?type ;
           gtm:straat ?straat .
      FILTER (?type IN (gtm:PlaatselijkeAanduiding, gtm:StraatNummerAanduiding, gtm:NummerAanduiding))
    } UNION {
      ?uri a gtm:Huisnaam ;
           gtm:straat ?straat .
    }
  }
  {
    ?straat schema:name ?straatnaam
  }
} 
GROUP BY ?locatiepunt  ?naam ?straat ?straatnaam	
ORDER BY ?naam ?straat');
    }

    public function get_personen_index($q, $straatidentifier, $tijdvak = null): array
    {

        $tijdvakfilter = !empty($tijdvak) ? ' FILTER(?datering>="' . $tijdvak[0] . '"^^xsd:gYear && ?datering<="' . $tijdvak[1] . '"^^xsd:gYear ) ' : '';
        $straatfilter = "";
        if (!empty($straatidentifier)) { // zoek op q met straat
            $straatfilter = "; gtm:straat <" . $straatidentifier . "> ";
        }
        $searchfilter = "";
        $toptienfilter = "";
        if (!empty($q)) {
            #$searchfilter=' ?text ql:contains-entity ?naam . ?text ql:contains-word "'.addslashes($q).'" . ';
            $searchfilter = ' FILTER(CONTAINS(LCASE(?naam), "' . addslashes(strtolower($q)) . '"))';
        } else {
            if (empty($straatidentifier)) {
                $topTien = [
                  "https://n2t.net/ark:/60537/b01dp31",
                  "https://n2t.net/ark:/60537/b003nsh",
                  "https://n2t.net/ark:/60537/b01dqqv",
                  "https://n2t.net/ark:/60537/b003q2k",
                  "https://n2t.net/ark:/60537/b01dr7f",
                  "https://n2t.net/ark:/60537/b62kMd",
                  "https://n2t.net/ark:/60537/b003q9g",
                  "https://n2t.net/ark:/60537/b003nqp",
                  "https://n2t.net/ark:/60537/b003qnm",
                  "https://n2t.net/ark:/60537/b01dt3j"
                ];
                $toptienfilter = "VALUES ?identifier { <" . implode("> <", $topTien) . "> } ";
            }
        }

        return $this->SPARQL('
SELECT ?identifier ?locatiepunt ?naam ?beroep ?datering WHERE {
  ' . $toptienfilter . '
  {
    # volkstelling / verponding
    ?identifier a picom:PersonObservation ; 
                schema:name ?naam ;
                schema:familyName ?familyname ;
                schema:givenName ?givenName;
                schema:identifier ?vermeldingidentifier;
                gtm:plaatselijkeAanduiding ?plaatselijkeaanduiding . ' . $searchfilter . ' 
    OPTIONAL { ?identifier schema:hasOccupation/o:label ?beroep }
    OPTIONAL { ?identifier schema:hasOccupation ?beroep }
    BIND(COALESCE(
        IF(STRSTARTS(STR(?vermeldingidentifier), "https://www.goudatijdmachine.nl/id/index/volkstelling1830/"), 1830, ?unbound),
        IF(STRSTARTS(STR(?vermeldingidentifier), "https://www.goudatijdmachine.nl/id/index/volkstelling1840/"), 1840, ?unbound),
        IF(STRSTARTS(STR(?vermeldingidentifier), "https://www.goudatijdmachine.nl/id/verponding/1785/"), 1785, ?unbound)
      ) AS ?datering)
    ?plaatselijkeaanduiding geo:hasGeometry ?locatiepunt ' . $straatfilter . ' . ' . $tijdvakfilter . '
    FILTER(ISIRI(?locatiepunt))
  }
  UNION
  {
    # adresboeken, locatiepunt in persoonvermelding
    {
      ?identifier a picom:PersonObservation ; 
                  schema:name ?naam ;
                  schema:datePublished ?datering ;
                  geo:hasGeometry ?locatiepunt ' . $straatfilter . ' . ' . $searchfilter . ' ' . $tijdvakfilter . ' 
      OPTIONAL { ?identifier schema:hasOccupation/o:label ?beroep }
      OPTIONAL { ?identifier schema:hasOccupation ?beroep }
      OPTIONAL { ?identifier schema:familyName ?familyname }
      OPTIONAL { ?identifier schema:givenName ?givenName }
      FILTER(ISIRI(?locatiepunt))
    } 
  }
  UNION
  {
    # bevolkingsregister, locatiepunt gekoppeld aan pagina br
    ?identifier a picom:PersonObservation ; 
                prov:hadPrimarySource ?source ;
                schema:familyName ?familyname ;
                schema:givenName ?givenName;
                schema:name ?naam . ' . $searchfilter . ' 
    ?source geo:hasGeometry ?locatiepunt ' . $straatfilter . ' ;
            schema:isPartOf ?partof .
    OPTIONAL { ?identifier schema:hasOccupation/o:label ?beroep }
    OPTIONAL { ?identifier schema:hasOccupation ?beroep }    
    OPTIONAL { ?partof rico:hasBeginningDate ?datering } ' . $tijdvakfilter . '
    FILTER(ISIRI(?locatiepunt))
  } 
} ORDER BY ?familyname ?givenName ?datering');
    }

    public function get_foto_index($q, $straatidentifier, $tijdvak): array
    {
        $qstring = trim($q ?? '');
        if (preg_match("/^L[0-9]+$/", $qstring)) {
            return $this->get_foto_index_locatiepunt($qstring, $tijdvak);
        } else {
            return $this->get_foto_index_beschrijving($qstring, $straatidentifier, $tijdvak);
        }
    }

    private function get_foto_index_beschrijving($q, $straatidentifier, $tijdvak): array
    {
        $straatfilter = !empty($straatidentifier) ? 'BIND( <' . $straatidentifier . '> AS ?straat) ' : '';
        #$searchfilter=!empty($q)?' ?text ql:contains-entity ?titel . ?text ql:contains-word "'.addslashes($q).'" . ':'';
        $searchfilter = !empty($q) ? ' FILTER(CONTAINS(LCASE(?titel), "' . addslashes(strtolower($q)) . '"))' : '';
        $tijdvakfilter = !empty($tijdvak) ? ' FILTER(?datering>="' . $tijdvak[0] . '"^^xsd:gYear && ?datering<="' . $tijdvak[1] . '"^^xsd:gYear ) ' : '';
        $toptienfilter = "";
        if (empty($straatfilter) && empty($searchfilter)) {
            $topTien = [
              "https://n2t.net/ark:/60537/brenuH",
              "https://n2t.net/ark:/60537/b01sdpt",
              "https://n2t.net/ark:/60537/bO8mhv",
              "https://n2t.net/ark:/60537/bZarCF",
              "https://n2t.net/ark:/60537/b5qoEx",
              "https://n2t.net/ark:/60537/bGQRcY",
              "https://n2t.net/ark:/60537/b8xT00",
              "https://n2t.net/ark:/60537/bH3yMd",
              "https://n2t.net/ark:/60537/bBEmQv",
              "https://n2t.net/ark:/60537/b5PMTR"
            ];
            $toptienfilter = "VALUES ?identifier { <" . implode("> <", $topTien) . "> } ";
        }

        return $this->SPARQL('
SELECT DISTINCT ?identifier ?titel ?url ?thumbnail ?straatnaam ?vervaardiger ?datering ?straat ?straatnaam ?locatiepunt WHERE {
  ' . $toptienfilter . ' ' . $straatfilter . '
  {
    ?identifier schema:spatialCoverage/gtm:straat ?straat ;
      schema:name ?titel ;
      schema:url ?url ;
      schema:dateCreated/rico:hasBeginningDate/rico:normalizedDateValue ?datering ;
      schema:spatialCoverage/schema:geo/geo:hasGeometry/geo:asWKT ?WKT2 ;
      o:media/schema:thumbnailUrl ?thumbnail .
    ' . $searchfilter . $tijdvakfilter . '
    OPTIONAL { ?identifier schema:creator ?vervaardiger . }  
  }
  {
    ?locatiepunt a geo:Geometry ;
                 schema:name ?name .
    ?perceel geo:hasGeometry ?locatiepunt ;
             geo:hasGeometry/geo:asWKT ?WKT1 .
    FILTER(STRSTARTS(STR(?WKT1),"POLYGON"))
  }
  FILTER(geof:sfIntersects(?WKT1, ?WKT2))
  ?straat schema:name ?straatnaam
}
ORDER BY ASC(?datering) ?titel');
    }

    private function get_foto_index_locatiepunt($locatiepunt, $tijdvak): array
    {
        #TODO: tijdvak is hier niet logisch
        #$tijdvakfilter=!empty($jaar_start)?' FILTER(?datering>="'.$jaar_start.'"^^xsd:gYear && ?datering<="'.$jaar_einde.'"^^xsd:gYear ) ':'';

        return $this->SPARQL('
SELECT DISTINCT ?identifier ?titel ?url ?thumbnail ?vervaardiger ?datering ?straat ?straatnaam ?locatiepunt WHERE  {
  {
    ?locatiepunt a geo:Geometry ;
                 schema:name ?name .
#    ?text ql:contains-entity ?name .
#    ?text ql:contains-word "' . $locatiepunt . '" .
    FILTER(CONTAINS(LCASE(?naam), "' . $locatiepunt . '"))
    ?perceel geo:hasGeometry ?locatiepunt ;
             geo:hasGeometry/geo:asWKT ?WKT1 .
    FILTER(STRSTARTS(STR(?WKT1),"POLYGON"))
  }
  {
    ?identifier schema:spatialCoverage/schema:geo/geo:hasGeometry/geo:asWKT ?WKT2 .
    FILTER(STRSTARTS(STR(?WKT2),"POLYGON")) 
    ?identifier schema:name ?titel ;
             schema:url ?url ;
             o:media/schema:thumbnailUrl ?thumbnail ;
             schema:dateCreated/rico:hasBeginningDate/rico:normalizedDateValue ?datering .
    OPTIONAL {
      ?identifier schema:creator ?vervaardiger .
    }
    OPTIONAL {
      ?identifier schema:spatialCoverage/schema:geo/geo:hasGeometry/osm:area ?area 
    }
  }
  FILTER(geof:sfIntersects(?WKT1, ?WKT2))
  {
    ?identifier schema:spatialCoverage/gtm:straat ?straat .
    ?straat schema:name ?straatnaam .
  }
} ORDER BY ?area');
    }

    public function get_foto($identifier): array
    {
        return $this->SPARQL('
SELECT * WHERE {
  BIND(<' . $identifier . '> as ?identifier)
  {
    ?identifier schema:spatialCoverage/gtm:straat ?straat ;
        schema:name ?titel ;
        schema:url ?url ;
        o:primary_media/o:source ?iiif_info_json ;
        schema:url ?url ;
        schema:spatialCoverage/schema:geo/geo:hasGeometry/geo:asWKT ?WKT2 ;
        o:media/schema:thumbnailUrl ?thumbnail .
    OPTIONAL { ?identifier schema:creator ?vervaardiger }
    OPTIONAL { ?identifier gtm:informatieAuteursRechten ?informatieAuteursRechten }
    OPTIONAL { ?identifier schema:dateCreated/rico:expressedDate ?datering }
  }
  {
    ?locatiepunt a geo:Geometry ;
                 schema:name ?name .
    ?perceel geo:hasGeometry ?locatiepunt ;
             geo:hasGeometry/geo:asWKT ?WKT1 .
    FILTER(STRSTARTS(STR(?WKT1),"POLYGON"))
  }
  FILTER(geof:sfIntersects(?WKT1, ?WKT2))
  ?straat schema:name ?straatnaam 
}');
    }

    public function get_fotos_dichtbij($identifier): array
    {
        return $this->SPARQL('
SELECT * WHERE {
  <' . $identifier . '> schema:spatialCoverage/schema:geo/geo:hasGeometry/geo:asWKT ?WKT1 .
  ?identifier geo:hasGeometry/geo:asWKT ?WKT2 ;
              schema:name ?titel ;
              o:media/schema:thumbnailUrl ?thumbnail ;
              o:primary_media/o:source ?iiif_info_json .
  BIND(geof:distance(?WKT1, ?WKT2) AS ?afstand)
}
ORDER BY ASC(?afstand)
LIMIT 10');
    }

    public function get_fotos_locatiepunt($locatiepuntidentifier): array
    {
        return $this->SPARQL('
SELECT DISTINCT ?identifier ?titel ?thumbnail ?datering WHERE  {
  {
    ?s a gtm:Perceel ;
       geo:hasGeometry <' . $locatiepuntidentifier . '>;
       geo:hasGeometry/geo:asWKT ?WKT1 .
  } 
  {
    ?identifier schema:spatialCoverage/schema:geo/geo:hasGeometry/geo:asWKT ?WKT2 .
    ?identifier schema:name ?titel ;
             schema:url ?url ;
             schema:dateCreated/rico:hasBeginningDate/rico:normalizedDateValue ?datering ;
             o:media/schema:thumbnailUrl ?thumbnail .
    OPTIONAL {
      ?identifier schema:spatialCoverage/schema:geo/geo:hasGeometry/<https://osm2rdf.cs.uni-freiburg.de/rdf#area> ?area 
    }
  }
  FILTER(geof:sfIntersects(?WKT1, ?WKT2)).
} ORDER BY ?datering ?area LIMIT 10');
    }

    public function get_pand($locatiepuntidentifier): array
    {
        # TODO: volgens OpenAPI requirement ook datering toevoegen
        return $this->SPARQL('
SELECT ?naam WHERE {
  <' . $locatiepuntidentifier . '>  schema:name ?naam
} ');
    }

    public function get_persoon($identifier): array
    {
        return $this->SPARQL('
SELECT * WHERE {
  BIND(<' . $identifier . '> as ?pv)
  ?pv schema:name ?name ;
      geo:hasGeometry ?locatiepunt .
  ?locatiepunt schema:name ?locatiepuntnaam .
  OPTIONAL { ?pv gtm:straat/o:title ?locatiepuntnaam }
  #OPTIONAL { ?pv schema:givenName ?givenName }
  #OPTIONAL { ?pv schema:familyName ?familyName }
  #OPTIONAL { ?pv pnv:patronym ?patronym }
  OPTIONAL { ?pv schema:hasOccupation ?hasOccupation }
  OPTIONAL { ?pv picom:hasAge ?hasAge }
  OPTIONAL { ?pv schema:birthDate ?birthDate }
  OPTIONAL { ?pv schema:birthPlace ?bp OPTIONAL { ?bp o:label ?bpLabel } BIND(IF(isLiteral(?bp), ?bp, ?bpLabel) AS ?birthPlace) }
  OPTIONAL { ?pv schema:deathDate ?deathDate }
  OPTIONAL { ?pv schema:deathPlace ?dp  OPTIONAL { ?dp o:label ?dpLabel } BIND(IF(isLiteral(?dp), ?dp, ?dpLabel) AS ?deathPlace) }
  #OPTIONAL { ?pv gtm:datumBegraven ?datumBegraven }
  #OPTIONAL { ?pv gtm:plaatsBegraven ?plaatsBegraven }
  #OPTIONAL { ?pv schema:spouse ?spouse }
  #OPTIONAL { ?pv schema:parent ?parent }
  #OPTIONAL { ?pv picom:isWidOf ?isWidOf }
  OPTIONAL { ?pv prov:hadPrimarySource/schema:isPartOf/rico:hasBeginningDate ?beginDate }
  OPTIONAL { ?pv prov:hadPrimarySource/rico:hasBeginningDate ?beginDate }
  OPTIONAL { ?pv prov:hadPrimarySource/schema:isPartOf/rico:hasEndDate ?endDate }
  OPTIONAL { ?pv prov:hadPrimarySource/rico:hasEndDate ?endDate }
  #OPTIONAL { ?pv prov:hadPrimarySource ?bronIdentifier }
  OPTIONAL { ?pv prov:hadPrimarySource ?snl . ?snl (schema:name|o:label) ?bronNaam }
  OPTIONAL { ?pv prov:hadPrimarySource/schema:isPartOf/rico:identifier ?_bronInventaris . BIND(CONCAT("SAMH ", STR(?_bronInventaris)) AS ?bronInventaris) }
  OPTIONAL { ?pv prov:hadPrimarySource/rico:identifier ?_bronInventaris . BIND(CONCAT("SAMH ", STR(?_bronInventaris)) AS ?bronInventaris) }
  OPTIONAL { ?pv schema:isBasedOn ?bronUrl }
  OPTIONAL { ?pv roar:documentedIn ?bronUrl }
  OPTIONAL { ?pv prov:hadPrimarySource ?bronUrl }
}');
    }

    public function get_personen_locatiepunt($locatiepuntidentifier): array
    {
        return $this->SPARQL('
SELECT ?identifier ?naam ?beroep ?datering WHERE {
  ?identifier a picom:PersonObservation ;
      geo:hasGeometry <' . $locatiepuntidentifier . '> ;
      schema:name ?naam .
  OPTIONAL { ?identifier schema:hasOccupation ?beroep }
  OPTIONAL { ?identifier schema:datePublished ?datering }
  OPTIONAL { ?identifier prov:hadPrimarySource/rico:hasBeginningDate ?datering }
  OPTIONAL { ?identifier prov:hadPrimarySource/schema:isPartOf/rico:hasBeginningDate ?datering }
} ORDER BY ASC(?datering)
');
    }

    public function get_adres_jaar($locatiepuntidentifier, $jaar1, $jaar2 = 0): array
    {
        $adressen = $this->get_adressen_locatiepunt($locatiepuntidentifier);
        $adres_array = [];
        $straaturi = [];

        if ($jaar2 == 0) {
            $jaar2 = $jaar1;
        }

        $jaar1_int = (int) $jaar1;
        $jaar2_int = (int) $jaar2;

        foreach ($adressen as $adres) {
            $startDate = (int) ($adres['startDate']['value'] ?? 0);
            $endDate = (int) ($adres['endDate']['value'] ?? 9999);

            if ($startDate <= $jaar2_int && $endDate >= $jaar1_int) {
                $adres_array[$adres['naam']['value']] = 1;
            }
            $straaturi[$adres['straaturi']['value']] = 1;
        }

        return [implode(", ", array_keys($adres_array)), array_keys($straaturi)];
    }

    public function get_adressen_locatiepunt($locatiepuntidentifier, $limit = 0): array
    {
        # nieuwste adres eerst
        return $this->SPARQL('
SELECT ?type ?naam ?startDate (COALESCE(?_endDate, "nu") AS ?endDate) ?wijknaam ?straaturi ?locatienaam WHERE {
  ?uri geo:hasGeometry <' . $locatiepuntidentifier . '> ;
       a ?type ;
       schema:startDate ?_startDate ;
       schema:name ?naam ;
       gtm:straat ?straaturi .
  FILTER (?type IN (gtm:PlaatselijkeAanduiding, gtm:StraatNummerAanduiding, gtm:NummerAanduiding, gtm:Huisnaam))
  BIND(xsd:integer(SUBSTR(STR(?_startDate), 1, 4)) AS ?startDate)
  OPTIONAL { ?uri schema:endDate ?_endDate }
  OPTIONAL {
    ?uri hg:liesIn ?wijk .
    ?wijk a gtm:Wijk ;
          schema:name ?wijknaam 
  }
  <' . $locatiepuntidentifier . '> schema:name ?locatienaam . 
} ORDER BY DESC(?startDate)' . ($limit > 0 ? ' LIMIT ' . $limit : ''));
    }
}
