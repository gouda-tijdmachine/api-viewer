# Gouda Tijdmachine Viewer API

API geeft toegang tot de [Gouda Tijdmachine Knowledge Graph](http://yasgui.org/short/J6vjIuQZTV) voor gebruik in de [Gouda Tijdmachine Viewer](https://github.com/gouda-tijdmachine/historischekaart).

## Cache voorverwarmen

Alle SPARQL-antwoorden worden 14 dagen in Redis gecachet (`CACHE_DURATION_SECONDS`).
Na een verversing van de triplestore (QLever-reindex) kan de cache geleegd en
volledig voorgevuld worden:

```
php8.5 bin/warm-cache.php
```

Het script leegt de cache en vraagt daarna op: de lijsten, de
default-zoekresultaten (de "tien interessante" panden/personen/foto's), alle
detailpagina's van die zoekresultaten en de pandgeometrieën per jaar. Opties:
`--zonder-wissen` (alleen bijvullen), `--zonder-geometrieen`,
`--jaren=1880-1900` (geometrie-jaren beperken). Vrije-tekst-zoekopdrachten
(`q=…`) zijn per definitie niet voor te verwarmen; die komen bij eerste
gebruik in de cache. Cron-suggestie: draaien direct na elke publicatie naar
de triplestore.
