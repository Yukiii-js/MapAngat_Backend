CREATE TABLE IF NOT EXISTS clearance_details (
    application_id VARCHAR(50) NOT NULL,
    parcel_geojson LONGTEXT NOT NULL,
    area_sqm DECIMAL(14,2) NOT NULL,
    land_use_category VARCHAR(60) NOT NULL,
    approved_land_use VARCHAR(100) NULL,
    permit_valid_until DATE NULL,
    qr_reference VARCHAR(80) NULL,
    permit_status VARCHAR(20) NULL,
    certificate_type VARCHAR(60) NULL,
    screening_results LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (application_id),
    UNIQUE KEY clearance_details_qr_reference_unique (qr_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_metrics (
    project_id INT UNSIGNED NOT NULL,
    target_progress DECIMAL(5,2) NOT NULL DEFAULT 100,
    allocated_budget DECIMAL(14,2) NOT NULL DEFAULT 0,
    spent_budget DECIMAL(14,2) NOT NULL DEFAULT 0,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_progress_updates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    completion DECIMAL(5,2) NOT NULL,
    budget_spent DECIMAL(14,2) NOT NULL,
    update_note TEXT NOT NULL,
    photo_url VARCHAR(2048) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    created_by VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY project_progress_updates_project_id_idx (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS field_inspection_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id VARCHAR(50) NOT NULL,
    inspector_name VARCHAR(150) NOT NULL,
    findings TEXT NOT NULL,
    photo_url VARCHAR(2048) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY field_inspection_logs_application_id_idx (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS spatial_layers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    layer_name VARCHAR(120) NOT NULL,
    category VARCHAR(40) NOT NULL,
    geojson LONGTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY spatial_layers_category_layer_name_unique (category, layer_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
