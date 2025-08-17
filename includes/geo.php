<?php
// includes/geo.php
// Verhindert Direktzugriff
if (!defined('ABSPATH')) {
    exit;
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
