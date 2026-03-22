<?php
// includes/geo.php
// Verhindert Direktzugriff
if (!defined('ABSPATH')) {
    exit;
}

/* -------------------------------------------------------------------------
 * OSM / Projection Helpers
 * ---------------------------------------------------------------------- */

if (!function_exists('lst_is_webmercator_coord')) {
    function lst_is_webmercator_coord($x, $y): bool {
        // WebMercator Koordinaten liegen typischerweise im Bereich tausende bis millionen.
        // Lon/Lat bleibt in [-180..180] / [-90..90].
        $ax = abs((float)$x);
        $ay = abs((float)$y);
        return ($ax > 200.0 || $ay > 200.0) && ($ax < 30000000.0) && ($ay < 30000000.0);
    }
}

if (!function_exists('lst_mercator_to_lonlat')) {
    function lst_mercator_to_lonlat(float $x, float $y): array {
        $R = 6378137.0;
        $lon = ($x / $R) * (180.0 / M_PI);
        $lat = (2.0 * atan(exp($y / $R)) - M_PI / 2.0) * (180.0 / M_PI);
        return [$lon, $lat];
    }
}

if (!function_exists('lst_normalize_ring_to_wgs84')) {
    function lst_normalize_ring_to_wgs84(array $ring): array {
        if (count($ring) < 2) return $ring;

        // anhand des ersten gültigen Punktes entscheiden
        foreach ($ring as $pt) {
            if (!is_array($pt) || count($pt) < 2) continue;
            $x = $pt[0];
            $y = $pt[1];
            if (lst_is_webmercator_coord($x, $y)) {
                $out = [];
                foreach ($ring as $p) {
                    if (!is_array($p) || count($p) < 2) continue;
                    $ll = lst_mercator_to_lonlat((float)$p[0], (float)$p[1]);
                    $out[] = $ll;
                }
                return $out;
            }
            // bereits WGS84
            return $ring;
        }

        return $ring;
    }
}

if (!function_exists('lst_normalize_mpoly_to_wgs84')) {
    function lst_normalize_mpoly_to_wgs84(array $mp): array {
        $out = [];
        foreach ($mp as $ring) {
            if (!is_array($ring)) continue;
            $out[] = lst_normalize_ring_to_wgs84($ring);
        }
        return $out;
    }
}

if (!function_exists('lst_simplify_ring_by_distance')) {
    function lst_simplify_ring_by_distance(array $ring, float $min_m = 10.0): array {
        if (count($ring) < 4) return $ring;

        // Ring schließen
        $first = $ring[0];
        $last  = $ring[count($ring)-1];
        $closed = (is_array($first) && is_array($last) && count($first) >= 2 && count($last) >= 2
            && ((float)$first[0] === (float)$last[0]) && ((float)$first[1] === (float)$last[1]));

        if (!$closed) {
            $ring[] = $first;
        }

        $out = [];
        $prev = null;
        foreach ($ring as $pt) {
            if (!is_array($pt) || count($pt) < 2) continue;
            $lon = (float)$pt[0];
            $lat = (float)$pt[1];

            if ($prev === null) {
                $out[] = [$lon, $lat];
                $prev = [$lon, $lat];
                continue;
            }

            $d = lst_haversine_m($prev[1], $prev[0], $lat, $lon);
            if ($d >= $min_m) {
                $out[] = [$lon, $lat];
                $prev = [$lon, $lat];
            }
        }

        // wieder schließen
        if (count($out) >= 3) {
            $f = $out[0];
            $l = $out[count($out)-1];
            if ($f[0] !== $l[0] || $f[1] !== $l[1]) {
                $out[] = $f;
            }
        }

        // Minimum für Polygon
        if (count($out) < 4) return $ring;

        return $out;
    }
}

/**
 * GeoJSON → MultiPolygon (nur Außenringe) normalisieren.
 * Akzeptiert FeatureCollection, Feature, Polygon, MultiPolygon.
 */
if (!function_exists('lst_geo_to_multipolygon')) {
    function lst_geo_to_multipolygon(array $in): array {
        $out = [];
        if (!isset($in['type'])) return $out;

        if ($in['type'] === 'FeatureCollection') {
            foreach ($in['features'] ?? [] as $f) {
                $g = $f['geometry'] ?? null;
                if (!$g) continue;
                foreach (lst_geo_to_multipolygon($g) as $ring) { $out[] = $ring; }
            }
            return $out;
        }

        if ($in['type'] === 'Feature') {
            $g = $in['geometry'] ?? null;
            return $g ? lst_geo_to_multipolygon($g) : $out;
        }

        if ($in['type'] === 'Polygon') {
            if (!empty($in['coordinates'][0])) $out[] = $in['coordinates'][0]; // Außenring
            return $out;
        }

        if ($in['type'] === 'MultiPolygon') {
            foreach ($in['coordinates'] ?? [] as $poly) {
                if (!empty($poly[0])) $out[] = $poly[0]; // Außenring
            }
            return $out;
        }

        return $out;
    }
}

/** Bounding Box für ein normalisiertes MultiPolygon (Liste von Ringen) */
if (!function_exists('lst_mpoly_bbox')) {
    function lst_mpoly_bbox(array $mp): array {
        $minLon = INF; $minLat = INF; $maxLon = -INF; $maxLat = -INF;
        foreach ($mp as $ring) {
            foreach ($ring as $c) {
                $lon = (float)$c[0]; $lat = (float)$c[1];
                if ($lon < $minLon) $minLon = $lon;
                if ($lon > $maxLon) $maxLon = $lon;
                if ($lat < $minLat) $minLat = $lat;
                if ($lat > $maxLat) $maxLat = $lat;
            }
        }
        if (!is_finite($minLon)) return [0,0,0,0];
        return [$minLon,$minLat,$maxLon,$maxLat];
    }
}

/** Point-in-Polygon für einen Ring (lon,lat) */
if (!function_exists('lst_pip_ring')) {
    function lst_pip_ring(array $pt, array $ring): bool {
        $x = $pt[0]; $y = $pt[1]; $inside = false;
        $n = count($ring);
        for ($i=0,$j=$n-1; $i<$n; $j=$i++) {
            $xi = (float)$ring[$i][0]; $yi = (float)$ring[$i][1];
            $xj = (float)$ring[$j][0]; $yj = (float)$ring[$j][1];
            $intersect = (($yi > $y) != ($yj > $y))
                && ($x < ($xj-$xi) * ($y-$yi) / (($yj-$yi) ?: 1e-12) + $xi);
            if ($intersect) $inside = !$inside;
        }
        return $inside;
    }
}

/** Point-in-MultiPolygon (lon,lat) – true, wenn in einem Außenring */
if (!function_exists('lst_point_in_mpoly')) {
    function lst_point_in_mpoly(array $pt, array $mp): bool {
        foreach ($mp as $ring) {
            if (lst_pip_ring($pt, $ring)) return true;
        }
        return false;
    }
}

/**
 * Rundet Koordinaten in GeoJSON-Koordinatenarrays.
 * $coords kann Point, LineString, Polygon usw. sein.
 * Gibt die gleiche Struktur zurück.
 */
if (!function_exists('lst_geo_round_coords')) {
    function lst_geo_round_coords($coords, int $decimals = 5) {
        if (is_array($coords) && isset($coords[0]) && is_numeric($coords[0]) && isset($coords[1]) && is_numeric($coords[1])) {
            return [
                round((float)$coords[0], $decimals),
                round((float)$coords[1], $decimals),
            ];
        }
        if (!is_array($coords)) return $coords;

        $out = [];
        foreach ($coords as $k => $v) {
            $out[$k] = lst_geo_round_coords($v, $decimals);
        }
        return $out;
    }
}

/**
 * Entfernt Punkte, die näher als $min_m am vorherigen Punkt liegen.
 * Erwartet Liste von [lon,lat].
 */
if (!function_exists('lst_simplify_line_by_distance')) {
    function lst_simplify_line_by_distance(array $coords, float $min_m = 8.0): array {
        if (count($coords) <= 2) return $coords;

        $out = [];
        $last = null;
        foreach ($coords as $pt) {
            if (!is_array($pt) || count($pt) < 2) continue;
            $lon = (float)$pt[0];
            $lat = (float)$pt[1];
            if ($last === null) {
                $out[] = [$lon, $lat];
                $last = [$lon, $lat];
                continue;
            }
            $d = lst_haversine_m($last[1], $last[0], $lat, $lon);
            if ($d >= $min_m) {
                $out[] = [$lon, $lat];
                $last = [$lon, $lat];
            }
        }
        // immer letzten Punkt behalten
        $end = $coords[count($coords)-1];
        if (is_array($end) && count($end) >= 2) {
            $endPt = [(float)$end[0], (float)$end[1]];
            $lastOut = $out[count($out)-1] ?? null;
            if ($lastOut === null || ($lastOut[0] != $endPt[0] || $lastOut[1] != $endPt[1])) {
                $out[] = $endPt;
            }
        }
        if (count($out) < 2) return $coords;
        return $out;
    }
}

/** Haversine Distanz in Metern */
if (!function_exists('lst_haversine_m')) {
    function lst_haversine_m(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $R = 6371000.0;
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dphi = deg2rad($lat2 - $lat1);
        $dl   = deg2rad($lon2 - $lon1);
        $a = sin($dphi/2)*sin($dphi/2) + cos($phi1)*cos($phi2) * sin($dl/2)*sin($dl/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $R * $c;
    }
}

/**
 * Centroid einer einfachen Polygon-Koordinatenliste (Außenring) als [lon,lat].
 * Fallback: Mittelwert der Punkte.
 */
if (!function_exists('lst_polygon_centroid')) {
    function lst_polygon_centroid(array $ring): array {
        $n = count($ring);
        if ($n < 3) {
            return [0.0, 0.0];
        }
        $area2 = 0.0;
        $cx = 0.0;
        $cy = 0.0;
        for ($i=0; $i<$n-1; $i++) {
            $x1 = (float)$ring[$i][0];
            $y1 = (float)$ring[$i][1];
            $x2 = (float)$ring[$i+1][0];
            $y2 = (float)$ring[$i+1][1];
            $cross = $x1*$y2 - $x2*$y1;
            $area2 += $cross;
            $cx += ($x1 + $x2) * $cross;
            $cy += ($y1 + $y2) * $cross;
        }
        if (abs($area2) < 1e-12) {
            // Fallback: Mittelwert
            $sx = 0.0; $sy = 0.0; $c = 0;
            foreach ($ring as $pt) {
                if (!is_array($pt) || count($pt) < 2) continue;
                $sx += (float)$pt[0];
                $sy += (float)$pt[1];
                $c++;
            }
            return $c ? [$sx/$c, $sy/$c] : [0.0,0.0];
        }
        $area = $area2 / 2.0;
        $cx = $cx / (6.0 * $area);
        $cy = $cy / (6.0 * $area);
        return [$cx, $cy];
    }
}

/**
 * "Centroid" einer LineString-Koordinatenliste als Punkt auf halber Linienlänge.
 * Eingabe: [[lon,lat], [lon,lat], ...]
 * Ausgabe: [lon,lat]
 */
if (!function_exists('lst_linestring_centroid')) {
    function lst_linestring_centroid(array $coords): array {
        // sanitize
        $pts = [];
        foreach ($coords as $pt) {
            if (!is_array($pt) || count($pt) < 2) continue;
            $pts[] = [(float)$pt[0], (float)$pt[1]];
        }

        $n = count($pts);
        if ($n === 0) return [0.0, 0.0];
        if ($n === 1) return $pts[0];

        // Gesamtlänge
        $total = 0.0;
        for ($i = 0; $i < $n - 1; $i++) {
            $lon1 = $pts[$i][0];     $lat1 = $pts[$i][1];
            $lon2 = $pts[$i+1][0];   $lat2 = $pts[$i+1][1];
            $total += lst_haversine_m($lat1, $lon1, $lat2, $lon2);
        }
        if ($total <= 0.0) return $pts[(int)floor($n / 2)];

        $half = $total / 2.0;

        // Punkt auf halber Länge interpolieren
        $acc = 0.0;
        for ($i = 0; $i < $n - 1; $i++) {
            $lon1 = $pts[$i][0];     $lat1 = $pts[$i][1];
            $lon2 = $pts[$i+1][0];   $lat2 = $pts[$i+1][1];

            $seg = lst_haversine_m($lat1, $lon1, $lat2, $lon2);
            if ($seg <= 0.0) continue;

            if ($acc + $seg >= $half) {
                $t = ($half - $acc) / $seg; // 0..1
                $lon = $lon1 + ($lon2 - $lon1) * $t;
                $lat = $lat1 + ($lat2 - $lat1) * $t;
                return [$lon, $lat];
            }
            $acc += $seg;
        }

        // Fallback: letzter Punkt
        return $pts[$n - 1];
    }
}


/**
 * Grobe Polygonfläche in m² über equirectangular projection.
 * Ring muss geschlossen sein (erster=letzter) oder wird intern geschlossen.
 */
if (!function_exists('lst_polygon_area_m2')) {
    function lst_polygon_area_m2(array $ring): float {
        $n = count($ring);
        if ($n < 3) return 0.0;

        // Referenzlatitude für Projektion
        $lat0 = 0.0;
        $c = 0;
        foreach ($ring as $pt) {
            if (!is_array($pt) || count($pt) < 2) continue;
            $lat0 += (float)$pt[1];
            $c++;
        }
        $lat0 = $c ? $lat0/$c : 0.0;

        $R = 6371000.0;
        $cos = cos(deg2rad($lat0));

        // Shoelace in projizierten Metern
        $area2 = 0.0;
        for ($i=0; $i<$n; $i++) {
            $j = ($i+1) % $n;
            $lon1 = deg2rad((float)$ring[$i][0]);
            $lat1 = deg2rad((float)$ring[$i][1]);
            $lon2 = deg2rad((float)$ring[$j][0]);
            $lat2 = deg2rad((float)$ring[$j][1]);

            $x1 = $R * $lon1 * $cos;
            $y1 = $R * $lat1;
            $x2 = $R * $lon2 * $cos;
            $y2 = $R * $lat2;
            $area2 += ($x1*$y2 - $x2*$y1);
        }
        return abs($area2) / 2.0;
    }
}
