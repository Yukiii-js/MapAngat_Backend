<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Spatial_model extends Model {

    private const EARTH_RADIUS_METERS = 6371008.8;

    public function calculate_area($geometry) {
        if (($geometry['type'] ?? '') === 'Point') {
            return 0.0;
        }
        $polygons = $geometry['type'] === 'Polygon'
            ? [$geometry['coordinates']]
            : $geometry['coordinates'];
        $area = 0.0;

        foreach ($polygons as $rings) {
            foreach ($rings as $index => $ring) {
                $ring_area = $this->ring_area($ring);
                $area += $index === 0 ? $ring_area : -$ring_area;
            }
        }

        return max(0.0, $area);
    }

    private function ring_area($ring) {
        $sum = 0.0;
        $count = count($ring);
        for ($index = 0; $index < $count; $index++) {
            $current = $ring[$index];
            $next = $ring[($index + 1) % $count];
            $sum += deg2rad($next[0] - $current[0])
                * (2 + sin(deg2rad($current[1])) + sin(deg2rad($next[1])));
        }
        return abs($sum * self::EARTH_RADIUS_METERS * self::EARTH_RADIUS_METERS / 2);
    }

    public function screen_geometry($parcel, $layers) {
        $results = [];
        foreach ($layers as $layer) {
            $features = $this->features($layer['geojson']);
            foreach ($features as $feature) {
                $geometry = $feature['geometry'] ?? null;
                if (!is_array($geometry) || !$this->intersects($parcel, $geometry)) {
                    continue;
                }
                $properties = $feature['properties'] ?? [];
                $results[] = [
                    'category' => $layer['category'],
                    'layer' => $layer['layer_name'],
                    'classification' => (string) ($properties['class'] ?? $properties['classification'] ?? $properties['name'] ?? $layer['layer_name']),
                    'properties' => $properties,
                ];
            }
        }
        return $results;
    }

    private function features($geojson) {
        if (($geojson['type'] ?? '') === 'FeatureCollection') {
            return $geojson['features'] ?? [];
        }
        if (($geojson['type'] ?? '') === 'Feature') {
            return [$geojson];
        }
        return [['type' => 'Feature', 'geometry' => $geojson, 'properties' => []]];
    }

    private function intersects($parcel, $geometry) {
        if (($parcel['type'] ?? '') === 'Point') {
            $point = $parcel['coordinates'];
            if (($geometry['type'] ?? '') === 'Point') {
                return abs($point[0] - $geometry['coordinates'][0]) < 1e-8
                    && abs($point[1] - $geometry['coordinates'][1]) < 1e-8;
            }
            foreach ($this->polygons($geometry) as $polygon) {
                if ($this->point_in_polygon($point, $polygon)) {
                    return true;
                }
            }
            return false;
        }
        $parcel_polygons = $this->polygons($parcel);
        if (($geometry['type'] ?? '') === 'Point') {
            foreach ($parcel_polygons as $polygon) {
                if ($this->point_in_polygon($geometry['coordinates'], $polygon)) {
                    return true;
                }
            }
            return false;
        }

        foreach ($this->polygons($geometry) as $other_polygon) {
            foreach ($parcel_polygons as $polygon) {
                foreach ($polygon as $ring) {
                    foreach ($other_polygon as $other_ring) {
                        if ($this->rings_intersect($ring, $other_ring)) {
                            return true;
                        }
                    }
                }
                if ($this->point_in_polygon($polygon[0][0], $other_polygon)
                    || $this->point_in_polygon($other_polygon[0][0], $polygon)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function polygons($geometry) {
        if (($geometry['type'] ?? '') === 'Polygon') {
            return [$geometry['coordinates']];
        }
        if (($geometry['type'] ?? '') === 'MultiPolygon') {
            return $geometry['coordinates'];
        }
        return [];
    }

    private function rings_intersect($first, $second) {
        for ($first_index = 0; $first_index < count($first) - 1; $first_index++) {
            for ($second_index = 0; $second_index < count($second) - 1; $second_index++) {
                if ($this->segments_intersect(
                    $first[$first_index],
                    $first[$first_index + 1],
                    $second[$second_index],
                    $second[$second_index + 1]
                )) {
                    return true;
                }
            }
        }
        return false;
    }

    private function segments_intersect($a, $b, $c, $d) {
        $orientation = static function ($p, $q, $r) {
            return ($q[1] - $p[1]) * ($r[0] - $q[0]) - ($q[0] - $p[0]) * ($r[1] - $q[1]);
        };
        $o1 = $orientation($a, $b, $c);
        $o2 = $orientation($a, $b, $d);
        $o3 = $orientation($c, $d, $a);
        $o4 = $orientation($c, $d, $b);
        if ((($o1 < 0 && $o2 > 0) || ($o1 > 0 && $o2 < 0))
            && (($o3 < 0 && $o4 > 0) || ($o3 > 0 && $o4 < 0))) {
            return true;
        }
        $on_segment = static function ($p, $q, $r) {
            return $q[0] <= max($p[0], $r[0]) && $q[0] >= min($p[0], $r[0])
                && $q[1] <= max($p[1], $r[1]) && $q[1] >= min($p[1], $r[1]);
        };
        return (abs($o1) < 1e-12 && $on_segment($a, $c, $b))
            || (abs($o2) < 1e-12 && $on_segment($a, $d, $b))
            || (abs($o3) < 1e-12 && $on_segment($c, $a, $d))
            || (abs($o4) < 1e-12 && $on_segment($c, $b, $d));
    }

    private function point_in_ring($point, $ring) {
        $inside = false;
        for ($index = 0, $previous = count($ring) - 1; $index < count($ring); $previous = $index++) {
            $a = $ring[$index];
            $b = $ring[$previous];
            if ((($a[1] > $point[1]) !== ($b[1] > $point[1]))
                && ($point[0] < ($b[0] - $a[0]) * ($point[1] - $a[1]) / (($b[1] - $a[1]) ?: 1e-12) + $a[0])) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    private function point_in_polygon($point, $polygon) {
        if (empty($polygon) || !$this->point_in_ring($point, $polygon[0])) {
            return false;
        }
        for ($index = 1; $index < count($polygon); $index++) {
            if ($this->point_in_ring($point, $polygon[$index])) {
                return false;
            }
        }
        return true;
    }
}
