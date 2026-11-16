<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ExternalHazardService
{
    const MUNICIPAL_LATITUDE = 13.3578;
    const MUNICIPAL_LONGITUDE = 121.0978;
    const MAX_RESPONSE_BYTES = 10485760;

    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?: lava_instance()->db;
    }

    public function tables_available()
    {
        $stmt = $this->db->raw(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ('external_api_configs', 'external_hazard_events', 'hdx_spatial_datasets')"
        );
        return (int) $stmt->fetchColumn() === 3;
    }

    public function admin_data()
    {
        $configs = $this->db->raw(
            "SELECT c.*,
                CASE WHEN c.api_key = 'hdx'
                    THEN (SELECT COUNT(*) FROM hdx_spatial_datasets)
                    ELSE (SELECT COUNT(*) FROM external_hazard_events e WHERE e.source = c.api_key)
                END AS current_record_count
             FROM external_api_configs c
             ORDER BY FIELD(c.api_key, 'usgs', 'gdacs', 'hdx')"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($configs as &$config) {
            $config['is_enabled'] = (bool) $config['is_enabled'];
            $config['bounding_box'] = json_decode($config['bounding_box_json'], true);
            unset($config['bounding_box_json']);
        }
        unset($config);

        return [
            'apis' => $configs,
            'events' => $this->events(100, false),
            'datasets' => $this->datasets(false),
        ];
    }

    public function resident_feed()
    {
        return [
            'events' => $this->events(250, true),
            'datasets' => $this->datasets(true),
        ];
    }

    public function sync($key)
    {
        $config = $this->get_config($key);
        if (!$config) {
            throw new InvalidArgumentException('Unknown external API key.');
        }

        $started = microtime(true);
        try {
            if ($key === 'usgs') {
                $this->sync_usgs($config);
            } elseif ($key === 'gdacs') {
                $this->sync_gdacs($config);
            } else {
                $this->sync_hdx();
            }

            $cached = $this->cached_count($key);
            $this->db->raw(
                "UPDATE external_api_configs
                 SET last_synced_at = UTC_TIMESTAMP(), last_status = 'success',
                     last_error_message = NULL, records_cached = ?, last_latency_ms = ?
                 WHERE api_key = ?",
                [$cached, (int) round((microtime(true) - $started) * 1000), $key]
            );

            return [
                'api_key' => $key,
                'records_cached' => $cached,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (Throwable $error) {
            $this->db->raw(
                "UPDATE external_api_configs
                 SET last_status = 'error', last_error_message = ?, last_latency_ms = ?
                 WHERE api_key = ?",
                [substr($error->getMessage(), 0, 4000), (int) round((microtime(true) - $started) * 1000), $key]
            );
            throw $error;
        }
    }

    public function update_config($key, $input)
    {
        $config = $this->get_config($key);
        if (!$config) {
            throw new InvalidArgumentException('Unknown external API key.');
        }

        $updates = [];
        $values = [];
        if (array_key_exists('is_enabled', $input)) {
            if (!in_array($input['is_enabled'], [true, false, 0, 1, '0', '1'], true)) {
                throw new InvalidArgumentException('is_enabled must be a boolean value.');
            }
            $updates[] = 'is_enabled = ?';
            $values[] = (int) (bool) $input['is_enabled'];
        }

        if (array_key_exists('sync_interval_minutes', $input)) {
            $interval = filter_var($input['sync_interval_minutes'], FILTER_VALIDATE_INT);
            if ($interval === false || $interval < 5 || $interval > 1440) {
                throw new InvalidArgumentException('Sync interval must be between 5 and 1440 minutes.');
            }
            $updates[] = 'sync_interval_minutes = ?';
            $values[] = $interval;
        }

        if (array_key_exists('min_magnitude', $input)) {
            $magnitude = filter_var($input['min_magnitude'], FILTER_VALIDATE_FLOAT);
            if ($magnitude === false || $magnitude < 1 || $magnitude > 9.9) {
                throw new InvalidArgumentException('Minimum magnitude must be between 1.0 and 9.9.');
            }
            $updates[] = 'min_magnitude = ?';
            $values[] = $magnitude;
        }

        if (array_key_exists('alert_levels', $input)) {
            $levels = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $input['alert_levels'])))));
            if (!$levels || array_diff($levels, ['Green', 'Orange', 'Red'])) {
                throw new InvalidArgumentException('Select one or more valid GDACS alert levels.');
            }
            $updates[] = 'alert_levels = ?';
            $values[] = implode(',', $levels);
        }

        if (array_key_exists('bounding_box', $input)) {
            $bounds = $input['bounding_box'];
            if (is_string($bounds)) {
                $bounds = json_decode($bounds, true);
            }
            if (!is_array($bounds)
                || !is_numeric($bounds['latitude'] ?? null)
                || !is_numeric($bounds['longitude'] ?? null)
                || !is_numeric($bounds['maxradiuskm'] ?? $bounds['buffer_km'] ?? null)
                || (float) $bounds['latitude'] < -90 || (float) $bounds['latitude'] > 90
                || (float) $bounds['longitude'] < -180 || (float) $bounds['longitude'] > 180) {
                throw new InvalidArgumentException('Provide valid latitude, longitude and search radius values.');
            }
            $radiusKey = $key === 'gdacs' ? 'buffer_km' : 'maxradiuskm';
            $radius = (float) ($bounds[$radiusKey] ?? $bounds['maxradiuskm'] ?? $bounds['buffer_km']);
            if ($radius < 1 || $radius > 1000) {
                throw new InvalidArgumentException('Search radius must be between 1 and 1000 km.');
            }
            $bounds = [
                'latitude' => (float) $bounds['latitude'],
                'longitude' => (float) $bounds['longitude'],
                $radiusKey => $radius,
            ];
            $encodedBounds = json_encode($bounds, JSON_UNESCAPED_SLASHES);
            if ($encodedBounds === false) {
                throw new RuntimeException('Unable to encode the search area configuration.');
            }
            $updates[] = 'bounding_box_json = ?';
            $values[] = $encodedBounds;
        }

        if ($updates) {
            $values[] = $key;
            $this->db->raw(
                'UPDATE external_api_configs SET ' . implode(', ', $updates) . ' WHERE api_key = ?',
                $values
            );
        }

        return $this->get_config($key);
    }

    public function set_event_visibility($id, $isPublished)
    {
        if (!in_array($isPublished, [true, false, 0, 1, '0', '1'], true)) {
            throw new InvalidArgumentException('is_published_to_residents must be a boolean value.');
        }
        $stmt = $this->db->raw(
            'UPDATE external_hazard_events SET is_published_to_residents = ? WHERE id = ?',
            [(int) (bool) $isPublished, (int) $id]
        );
        if ($stmt->rowCount() === 0) {
            $exists = $this->db->raw('SELECT id FROM external_hazard_events WHERE id = ? LIMIT 1', [(int) $id])->fetchColumn();
            if (!$exists) {
                return false;
            }
        }
        return true;
    }

    public function set_dataset_active($id, $isActive)
    {
        if (!in_array($isActive, [true, false, 0, 1, '0', '1'], true)) {
            throw new InvalidArgumentException('is_active_layer must be a boolean value.');
        }
        $stmt = $this->db->raw(
            'UPDATE hdx_spatial_datasets SET is_active_layer = ? WHERE id = ?',
            [(int) (bool) $isActive, (int) $id]
        );
        if ($stmt->rowCount() === 0) {
            $exists = $this->db->raw('SELECT id FROM hdx_spatial_datasets WHERE id = ? LIMIT 1', [(int) $id])->fetchColumn();
            if (!$exists) {
                return false;
            }
        }
        return true;
    }

    private function get_config($key)
    {
        $row = $this->db->raw(
            'SELECT * FROM external_api_configs WHERE api_key = ? LIMIT 1',
            [$key]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['bounding_box'] = json_decode($row['bounding_box_json'], true);
        return $row;
    }

    private function events($limit, $publishedOnly)
    {
        $sql = 'SELECT * FROM external_hazard_events';
        if ($publishedOnly) {
            $sql .= ' WHERE is_published_to_residents = 1
                      AND event_timestamp >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)';
        }
        $sql .= ' ORDER BY event_timestamp DESC LIMIT ' . (int) $limit;
        $rows = $this->db->raw($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['is_published_to_residents'] = (bool) $row['is_published_to_residents'];
            $row['raw_payload'] = json_decode($row['raw_payload_json'], true);
            $row['geometry'] = $row['raw_payload']['geometry'] ?? null;
            unset($row['raw_payload_json']);
        }
        unset($row);
        return $rows;
    }

    private function datasets($activeOnly)
    {
        $sql = 'SELECT * FROM hdx_spatial_datasets';
        if ($activeOnly) {
            $sql .= ' WHERE is_active_layer = 1';
        }
        $sql .= ' ORDER BY category, title';
        $rows = $this->db->raw($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['is_active_layer'] = (bool) $row['is_active_layer'];
            $row['geojson'] = json_decode($row['geojson_or_summary_json'], true);
            unset($row['geojson_or_summary_json']);
        }
        unset($row);
        return $rows;
    }

    private function cached_count($key)
    {
        if ($key === 'hdx') {
            return (int) $this->db->raw('SELECT COUNT(*) FROM hdx_spatial_datasets')->fetchColumn();
        }
        return (int) $this->db->raw(
            'SELECT COUNT(*) FROM external_hazard_events WHERE source = ?',
            [$key]
        )->fetchColumn();
    }

    private function sync_usgs($config)
    {
        $bounds = $config['bounding_box'];
        $query = [
            'format' => 'geojson',
            'starttime' => gmdate('Y-m-d\TH:i:s\Z', time() - 7 * 86400),
            'endtime' => gmdate('Y-m-d\TH:i:s\Z'),
            'latitude' => $bounds['latitude'] ?? self::MUNICIPAL_LATITUDE,
            'longitude' => $bounds['longitude'] ?? self::MUNICIPAL_LONGITUDE,
            'maxradiuskm' => $bounds['maxradiuskm'] ?? 300,
            'minmagnitude' => $config['min_magnitude'],
        ];
        $result = $this->fetch_json('https://earthquake.usgs.gov/fdsnws/event/1/query?' . http_build_query($query));
        $features = $result['features'] ?? [];
        if (!is_array($features)) {
            throw new RuntimeException('USGS returned an unexpected feature list.');
        }

        foreach ($features as $feature) {
            $coordinates = $feature['geometry']['coordinates'] ?? [];
            $properties = $feature['properties'] ?? [];
            if (count($coordinates) < 2 || !is_numeric($coordinates[0]) || !is_numeric($coordinates[1])) {
                continue;
            }
            $longitude = (float) $coordinates[0];
            $latitude = (float) $coordinates[1];
            $magnitude = is_numeric($properties['mag'] ?? null) ? (float) $properties['mag'] : null;
            $distance = $this->distance_km($latitude, $longitude, $query['latitude'], $query['longitude']);
            $alert = strtolower((string) ($properties['alert'] ?? ''));
            $severity = in_array($alert, ['green', 'orange', 'red'], true)
                ? $alert
                : (($magnitude ?? 0) >= 5.5 ? 'red' : (($magnitude ?? 0) >= 4 ? 'orange' : 'green'));
            $time = isset($properties['time']) ? (int) floor((float) $properties['time'] / 1000) : time();
            $this->save_event([
                'source' => 'usgs',
                'external_event_id' => 'usgs:' . (string) ($feature['id'] ?? $properties['code'] ?? sha1(json_encode($feature))),
                'hazard_type' => 'EQ',
                'title' => (string) ($properties['place'] ?? 'Earthquake'),
                'severity_level' => $severity,
                'magnitude_or_score' => $magnitude,
                'depth_km' => is_numeric($coordinates[2] ?? null) ? (float) $coordinates[2] : null,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'distance_to_municipality_km' => $distance,
                'affected_population' => null,
                'event_timestamp' => gmdate('Y-m-d H:i:s', $time),
                'raw_payload_json' => $this->encode_json($feature),
                'is_published_to_residents' => ($distance <= 150 || ($magnitude ?? 0) >= 4.5) ? 1 : 0,
            ]);
        }
    }

    private function sync_gdacs($config)
    {
        $bounds = $config['bounding_box'];
        $now = time();
        $query = [
            'fromdate' => gmdate('Y-m-d', $now - 30 * 86400),
            'todate' => gmdate('Y-m-d', $now),
            'alertlevel' => $config['alert_levels'],
            'iso3' => 'PHL',
        ];
        $url = 'https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH?' . http_build_query($query);
        $result = $this->fetch_json($url);
        $features = $result['features'] ?? [];
        if (!is_array($features)) {
            throw new RuntimeException('GDACS returned an unexpected feature list.');
        }
        $radius = (float) ($bounds['buffer_km'] ?? 500);

        foreach ($features as $feature) {
            $properties = $feature['properties'] ?? [];
            $geometry = $feature['geometry'] ?? [];
            $position = $this->geometry_center($geometry);
            if (!$position) {
                continue;
            }
            list($longitude, $latitude) = $position;
            $distance = $this->distance_km(
                $latitude,
                $longitude,
                $bounds['latitude'] ?? self::MUNICIPAL_LATITUDE,
                $bounds['longitude'] ?? self::MUNICIPAL_LONGITUDE
            );
            if ($distance > $radius && strtoupper((string) ($properties['iso3'] ?? '')) !== 'PHL') {
                continue;
            }

            $alert = strtolower((string) ($properties['alertlevel'] ?? 'green'));
            $severity = in_array($alert, ['green', 'orange', 'red'], true) ? $alert : 'green';
            $eventType = strtoupper((string) ($properties['eventtype'] ?? ''));
            $hazardType = in_array($eventType, ['TC', 'FL', 'EQ', 'VO', 'DR'], true) ? $eventType : 'TC';
            $eventId = (string) ($properties['eventid'] ?? $feature['id'] ?? sha1(json_encode($feature)));
            $eventDate = $properties['fromdate'] ?? $properties['fromDate'] ?? null;
            $expiresAt = $properties['todate'] ?? $properties['toDate'] ?? null;
            if ($expiresAt && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) {
                continue;
            }
            $timestamp = $eventDate && strtotime($eventDate) !== false ? strtotime($eventDate) : time();
            $score = $properties['severity'] ?? $properties['alertscore'] ?? null;
            $population = $properties['population'] ?? null;
            $population = is_numeric($population) ? max(0, (int) $population) : null;

            $this->save_event([
                'source' => 'gdacs',
                'external_event_id' => 'gdacs:' . $eventId,
                'hazard_type' => $hazardType,
                'title' => (string) ($properties['name'] ?? $properties['htmldescription'] ?? 'Regional hazard alert'),
                'severity_level' => $severity,
                'magnitude_or_score' => is_numeric($score) ? (float) $score : null,
                'depth_km' => null,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'distance_to_municipality_km' => $distance,
                'affected_population' => $population,
                'event_timestamp' => gmdate('Y-m-d H:i:s', $timestamp),
                'raw_payload_json' => $this->encode_json($feature),
                'is_published_to_residents' => 1,
            ]);
        }
    }

    private function sync_hdx()
    {
        $searches = [
            ['query' => 'cod-ab-phl', 'category' => 'admin_boundaries'],
            ['query' => 'hdx-hapi-phl', 'category' => 'population_exposure'],
            ['query' => 'Philippines evacuation centers facilities', 'category' => 'facilities'],
        ];
        foreach ($searches as $search) {
            $url = 'https://data.humdata.org/api/3/action/package_search?' . http_build_query([
                'q' => $search['query'],
                'rows' => 10,
            ]);
            $response = $this->fetch_json($url);
            $packages = $response['result']['results'] ?? [];
            if (!is_array($packages)) {
                throw new RuntimeException('HDX returned an unexpected package list.');
            }

            foreach ($packages as $package) {
                if (!is_array($package) || empty($package['name'])) {
                    continue;
                }
                $this->cache_hdx_package($package, $search['category']);
                break;
            }
        }
    }

    private function cache_hdx_package($package, $category)
    {
        $geojson = null;
        $resourceId = null;
        foreach (($package['resources'] ?? []) as $resource) {
            $url = (string) ($resource['download_url'] ?? $resource['url'] ?? '');
            $format = strtoupper((string) ($resource['format'] ?? ''));
            if (!in_array($format, ['GEOJSON', 'JSON'], true)
                && !preg_match('/\.(geojson|json)(?:$|[?#])/i', $url)) {
                continue;
            }
            $parts = parse_url($url);
            if (($parts['scheme'] ?? '') !== 'https'
                || strtolower((string) ($parts['host'] ?? '')) !== 'data.humdata.org') {
                continue;
            }
            $download = $this->fetch_json($url);
            if (in_array($download['type'] ?? '', ['Feature', 'FeatureCollection'], true)) {
                $geojson = $download;
                $resourceId = $resource['id'] ?? null;
                break;
            }
        }

        $summary = $geojson ?? [
            'package' => [
                'name' => $package['name'],
                'title' => $package['title'] ?? $package['name'],
                'notes' => $package['notes'] ?? '',
                'resources' => array_map(static function ($resource) {
                    return [
                        'id' => $resource['id'] ?? null,
                        'name' => $resource['name'] ?? null,
                        'format' => $resource['format'] ?? null,
                    ];
                }, $package['resources'] ?? []),
            ],
        ];
        $encoded = $this->encode_json($summary);
        if (strlen($encoded) > 5242880) {
            throw new RuntimeException('HDX GeoJSON dataset exceeds the 5 MB cache limit.');
        }
        $modified = $package['metadata_modified'] ?? null;
        $sourceUpdated = $modified && strtotime($modified) !== false
            ? gmdate('Y-m-d H:i:s', strtotime($modified))
            : null;
        $this->db->raw(
            "INSERT INTO hdx_spatial_datasets
                (dataset_slug, resource_id, title, category, geojson_or_summary_json, source_updated_at, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                resource_id = VALUES(resource_id),
                title = VALUES(title),
                category = VALUES(category),
                geojson_or_summary_json = VALUES(geojson_or_summary_json),
                source_updated_at = VALUES(source_updated_at),
                synced_at = UTC_TIMESTAMP()",
            [
                substr((string) $package['name'], 0, 191),
                $resourceId,
                substr((string) ($package['title'] ?? $package['name']), 0, 500),
                $category,
                $encoded,
                $sourceUpdated,
            ]
        );
    }

    private function save_event($event)
    {
        $this->db->raw(
            "INSERT INTO external_hazard_events
                (source, external_event_id, hazard_type, title, severity_level, magnitude_or_score, depth_km,
                 latitude, longitude, distance_to_municipality_km, affected_population, event_timestamp,
                 raw_payload_json, is_published_to_residents)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                hazard_type = VALUES(hazard_type),
                title = VALUES(title),
                severity_level = VALUES(severity_level),
                magnitude_or_score = VALUES(magnitude_or_score),
                depth_km = VALUES(depth_km),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                distance_to_municipality_km = VALUES(distance_to_municipality_km),
                affected_population = VALUES(affected_population),
                event_timestamp = VALUES(event_timestamp),
                raw_payload_json = VALUES(raw_payload_json),
                updated_at = UTC_TIMESTAMP()",
            [
                $event['source'],
                substr($event['external_event_id'], 0, 191),
                $event['hazard_type'],
                substr($event['title'], 0, 500),
                $event['severity_level'],
                $event['magnitude_or_score'],
                $event['depth_km'],
                $event['latitude'],
                $event['longitude'],
                $event['distance_to_municipality_km'],
                $event['affected_population'],
                $event['event_timestamp'],
                $event['raw_payload_json'],
                $event['is_published_to_residents'],
            ]
        );
    }

    private function fetch_json($url)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for external data sync.');
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new RuntimeException('External data URLs must use HTTPS.');
        }

        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/geo+json, application/json'],
            CURLOPT_USERAGENT => 'MapAngat/1.0 (+https://mapangat.gov.ph)',
            CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $completed = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($completed === false) {
            throw new RuntimeException($curlError ?: 'The external API response exceeded the 10 MB limit.');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('External API request failed with HTTP ' . $status . '.');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('The external API returned invalid JSON.');
        }
        return $decoded;
    }

    private function geometry_center($geometry)
    {
        $type = $geometry['type'] ?? '';
        $coordinates = $geometry['coordinates'] ?? null;
        if ($type === 'Point' && is_array($coordinates) && count($coordinates) >= 2) {
            return [(float) $coordinates[0], (float) $coordinates[1]];
        }
        if ($type === 'Polygon' && isset($coordinates[0]) && is_array($coordinates[0])) {
            $ring = $coordinates[0];
        } elseif ($type === 'MultiPolygon' && isset($coordinates[0][0]) && is_array($coordinates[0][0])) {
            $ring = $coordinates[0][0];
        } else {
            return null;
        }
        $points = array_filter($ring, static function ($point) {
            return is_array($point) && isset($point[0], $point[1])
                && is_numeric($point[0]) && is_numeric($point[1]);
        });
        if (!$points) {
            return null;
        }
        $longitude = array_sum(array_map(static function ($point) { return (float) $point[0]; }, $points)) / count($points);
        $latitude = array_sum(array_map(static function ($point) { return (float) $point[1]; }, $points)) / count($points);
        return [$longitude, $latitude];
    }

    private function distance_km($latitude1, $longitude1, $latitude2, $longitude2)
    {
        $earthRadius = 6371;
        $latDelta = deg2rad($latitude2 - $latitude1);
        $lonDelta = deg2rad($longitude2 - $longitude1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * sin($lonDelta / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function encode_json($value)
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode external API data as JSON.');
        }
        return $encoded;
    }
}
