<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Workflow_model extends Model {

    private function database() {
        return lava_instance()->db;
    }

    public function add_clearance_details($details) {
        $this->database()->raw(
            "INSERT INTO clearance_details
                (application_id, parcel_geojson, area_sqm, land_use_category, screening_results, created_at)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $details['application_id'],
                $details['parcel_geojson'],
                $details['area_sqm'],
                $details['land_use_category'],
                $details['screening_results'],
                date('Y-m-d H:i:s'),
            ]
        );
    }

    public function update_clearance_status($application_id, $status) {
        $this->database()->raw(
            "UPDATE location_clearances SET status = ?, updated_at = ? WHERE application_id = ?",
            [$status, date('Y-m-d H:i:s'), $application_id]
        );
    }

    public function issue_clearance($application_id, $approved_land_use, $certificate_type, $valid_until, $qr_reference) {
        $this->database()->raw(
            "UPDATE clearance_details
             SET approved_land_use = ?, certificate_type = ?, permit_valid_until = ?,
                 qr_reference = ?, permit_status = 'Valid', updated_at = ?
             WHERE application_id = ?",
            [$approved_land_use, $certificate_type, $valid_until, $qr_reference, date('Y-m-d H:i:s'), $application_id]
        );
        $this->update_clearance_status($application_id, 'Approved');
    }

    public function set_permit_status($application_id, $permit_status) {
        $this->database()->raw(
            "UPDATE clearance_details SET permit_status = ?, updated_at = ? WHERE application_id = ?",
            [$permit_status, date('Y-m-d H:i:s'), $application_id]
        );
    }

    public function add_project_update($update) {
        $db = $this->database();
        $db->raw(
            "INSERT INTO project_progress_updates
                (project_id, completion, budget_spent, update_note, photo_url, latitude, longitude, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $update['project_id'],
                $update['completion'],
                $update['budget_spent'],
                $update['update_note'],
                $update['photo_url'],
                $update['latitude'],
                $update['longitude'],
                $update['created_by'],
                date('Y-m-d H:i:s'),
            ]
        );
        $db->raw(
            "INSERT INTO project_metrics (project_id, target_progress, allocated_budget, spent_budget, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE target_progress = VALUES(target_progress),
                allocated_budget = VALUES(allocated_budget), spent_budget = VALUES(spent_budget),
                updated_at = VALUES(updated_at)",
            [
                $update['project_id'],
                $update['target_progress'],
                $update['allocated_budget'],
                $update['budget_spent'],
                date('Y-m-d H:i:s'),
            ]
        );
        $db->raw(
            "UPDATE projects SET progress = ?, status = ?, updated_at = ? WHERE id = ?",
            [$update['completion'], $update['status'], date('Y-m-d H:i:s'), $update['project_id']]
        );
    }

    public function get_project_updates($project_id) {
        return $this->database()->raw(
            "SELECT id, project_id, completion, budget_spent, update_note, photo_url,
                    latitude, longitude, created_by, created_at
             FROM project_progress_updates WHERE project_id = ? ORDER BY created_at DESC",
            [$project_id]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function add_inspection($inspection) {
        $this->database()->raw(
            "INSERT INTO field_inspection_logs
                (application_id, inspector_name, findings, photo_url, latitude, longitude, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $inspection['application_id'],
                $inspection['inspector_name'],
                $inspection['findings'],
                $inspection['photo_url'],
                $inspection['latitude'],
                $inspection['longitude'],
                date('Y-m-d H:i:s'),
            ]
        );
    }

    public function save_spatial_layer($name, $category, $geojson) {
        $this->database()->raw(
            "INSERT INTO spatial_layers (layer_name, category, geojson, is_active, updated_at)
             VALUES (?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE geojson = VALUES(geojson), is_active = 1, updated_at = VALUES(updated_at)",
            [$name, $category, $geojson, date('Y-m-d H:i:s')]
        );
    }

    public function get_spatial_layers() {
        $rows = $this->database()->raw(
            "SELECT id, layer_name, category, geojson, updated_at
             FROM spatial_layers WHERE is_active = 1 ORDER BY category, layer_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['geojson'] = json_decode($row['geojson'], true);
        }
        unset($row);
        return $rows;
    }
}
