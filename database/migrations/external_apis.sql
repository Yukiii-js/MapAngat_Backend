CREATE TABLE IF NOT EXISTS external_api_configs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_key ENUM('usgs', 'gdacs', 'hdx') NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    endpoint_url VARCHAR(512) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    sync_interval_minutes INT NOT NULL DEFAULT 60,
    bounding_box_json JSON NOT NULL,
    min_magnitude DECIMAL(3,1) NOT NULL DEFAULT 2.5,
    alert_levels VARCHAR(80) NOT NULL DEFAULT 'Green,Orange,Red',
    last_synced_at DATETIME NULL,
    last_latency_ms INT UNSIGNED NULL,
    last_status ENUM('success', 'error', 'idle') NOT NULL DEFAULT 'idle',
    last_error_message TEXT NULL,
    records_cached INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY external_api_configs_api_key_unique (api_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_hazard_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    source ENUM('usgs', 'gdacs') NOT NULL,
    external_event_id VARCHAR(191) NOT NULL,
    hazard_type ENUM('EQ', 'TC', 'FL', 'VO', 'DR') NOT NULL,
    title VARCHAR(500) NOT NULL,
    severity_level ENUM('green', 'orange', 'red') NOT NULL DEFAULT 'green',
    magnitude_or_score DECIMAL(5,2) NULL,
    depth_km DECIMAL(6,2) NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    distance_to_municipality_km DECIMAL(8,2) NOT NULL,
    affected_population INT UNSIGNED NULL,
    event_timestamp DATETIME NOT NULL,
    raw_payload_json JSON NOT NULL,
    is_published_to_residents TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY external_hazard_events_external_id_unique (external_event_id),
    KEY external_hazard_events_source_timestamp_idx (source, event_timestamp),
    KEY external_hazard_events_published_timestamp_idx (is_published_to_residents, event_timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hdx_spatial_datasets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dataset_slug VARCHAR(191) NOT NULL,
    resource_id VARCHAR(191) NULL,
    title VARCHAR(500) NOT NULL,
    category ENUM('admin_boundaries', 'population_exposure', 'hazard_zones', 'facilities') NOT NULL,
    geojson_or_summary_json LONGTEXT NOT NULL,
    source_updated_at DATETIME NULL,
    is_active_layer TINYINT(1) NOT NULL DEFAULT 1,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY hdx_spatial_datasets_slug_unique (dataset_slug),
    KEY hdx_spatial_datasets_active_category_idx (is_active_layer, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO external_api_configs
    (api_key, display_name, endpoint_url, is_enabled, sync_interval_minutes, bounding_box_json, min_magnitude, alert_levels, last_status)
VALUES
    ('usgs', 'USGS Earthquakes', 'https://earthquake.usgs.gov/fdsnws/event/1/query', 1, 60, JSON_OBJECT('latitude', 13.3578, 'longitude', 121.0978, 'maxradiuskm', 300), 2.5, 'Green,Orange,Red', 'idle'),
    ('gdacs', 'GDACS Regional Alerts', 'https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH', 1, 60, JSON_OBJECT('latitude', 13.3578, 'longitude', 121.0978, 'buffer_km', 500), 2.5, 'Green,Orange,Red', 'idle'),
    ('hdx', 'UN OCHA HDX Spatial Data', 'https://data.humdata.org/api/3/action/package_search', 1, 1440, JSON_OBJECT('country', 'PHL'), 2.5, 'Green,Orange,Red', 'idle')
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    endpoint_url = VALUES(endpoint_url);
