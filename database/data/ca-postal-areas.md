# Canadian postal areas

`ca-postal-areas.csv` has one row for each forward sortation area (FSA), the
first three characters of a Canadian postal code. Each row holds a town name
and the area's centre point. `App\Support\PostalAreas` reads it to put
shoppers and stores on the map without a paid geocoder.

Source: GeoNames postal code export for Canada
(https://download.geonames.org/export/zip/CA.zip), licensed under
Creative Commons Attribution 4.0 (https://creativecommons.org/licenses/by/4.0/).
Credit GeoNames (www.geonames.org) on the site.

Town names were simplified from the GeoNames place names: directional
suffixes such as "Northeast" or "Central" were dropped, rural areas use the
hub town in brackets, Toronto `M` codes are "Toronto", `H7` codes are "Laval"
and other `H` codes are "Montréal". The data comes "as is"; centre points are
approximate, a few km in cities and more in rural areas.
