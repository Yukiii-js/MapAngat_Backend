<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Clearance_model extends Model {

    public function is_available() {
        $stmt = $this->db->raw(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            ['location_clearances']
        );
        return (int) $stmt->fetchColumn() > 0;
    }

    public function get_all() {
        if (!$this->is_available()) {
            return [];
        }

        $columns_stmt = $this->db->raw(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ?",
            ['location_clearances']
        );
        $columns = array_column($columns_stmt->fetchAll(PDO::FETCH_ASSOC), 'column_name');
        $normalized = ['application_id', 'project_type', 'barangay'];
        $has_normalized_schema = count(array_intersect($normalized, $columns)) === count($normalized);

        $details_stmt = $this->db->raw(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            ['clearance_details']
        );
        $has_details = $has_normalized_schema && (int) $details_stmt->fetchColumn() > 0;
        if ($has_details) {
            $detail_columns_stmt = $this->db->raw(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ?",
                ['clearance_details']
            );
            $detail_columns = array_column($detail_columns_stmt->fetchAll(PDO::FETCH_ASSOC), 'column_name');
            $required_details = [
                'application_id', 'parcel_geojson', 'area_sqm', 'land_use_category',
                'approved_land_use', 'permit_valid_until', 'qr_reference', 'permit_status',
                'certificate_type', 'screening_results',
            ];
            $has_details = count(array_intersect($required_details, $detail_columns)) === count($required_details);
        }

        $column = static function ($name, $fallback) use ($columns) {
            return in_array($name, $columns, true) ? "c.`{$name}`" : $fallback;
        };
        $id = $column('id', 'NULL');
        $created_at = $column('created_at', 'NULL');
        $application_id = $has_normalized_schema
            ? $column('application_id', 'NULL')
            : (in_array('id', $columns, true) && in_array('created_at', $columns, true)
                ? "CONCAT('LC-', YEAR(c.`created_at`), '-', LPAD(c.`id`, 3, '0'))"
                : 'NULL');
        $project_type = $column(
            'project_type',
            $column('business_name', "'Location clearance'")
        );
        $barangay = $column(
            'barangay',
            in_array('location', $columns, true)
                ? "TRIM(SUBSTRING_INDEX(COALESCE(c.`location`, ''), ',', 1))"
                : "''"
        );
        $select = [
            "{$id} AS id",
            "{$application_id} AS application_id",
            $column('applicant_name', "''") . ' AS applicant_name',
            "{$project_type} AS project_type",
            "{$barangay} AS barangay",
            $column('status', "'Pending'") . ' AS status',
            $column('lat', 'NULL') . ' AS lat',
            $column('lng', 'NULL') . ' AS lng',
            "{$created_at} AS created_at",
            $column('updated_at', 'NULL') . ' AS updated_at',
        ];
        if ($has_details) {
            foreach ([
                'parcel_geojson', 'area_sqm', 'land_use_category', 'approved_land_use',
                'permit_valid_until', 'qr_reference', 'permit_status', 'certificate_type',
                'screening_results',
            ] as $detail_column) {
                $select[] = "d.`{$detail_column}` AS `{$detail_column}`";
            }
        }

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM location_clearances c';
        if ($has_details) {
            $sql .= ' LEFT JOIN clearance_details d ON d.application_id = c.application_id';
        }
        $sql .= in_array('created_at', $columns, true)
            ? ' ORDER BY c.`created_at` DESC'
            : (in_array('id', $columns, true) ? ' ORDER BY c.`id` DESC' : '');

        $stmt = $this->db->raw($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert_application($data) {
        return $this->db->table('location_clearances')->insert($data);
    }

    public function get_by_reference($reference) {
        foreach ($this->get_all() as $application) {
            if (strcasecmp((string) ($application['application_id'] ?? ''), $reference) === 0
                || strcasecmp((string) ($application['qr_reference'] ?? ''), $reference) === 0) {
                return $application;
            }
        }
        return null;
    }

    public function get_counts_by_status() {
        $counts = ['pending' => 0, 'review' => 0, 'approved' => 0];

        foreach ($this->get_all() as $application) {
            $status = strtolower((string) ($application['status'] ?? ''));
            if ($status === 'pending') {
                $counts['pending']++;
            } elseif (in_array($status, ['for review', 'under review'], true)) {
                $counts['review']++;
            } elseif ($status === 'approved') {
                $counts['approved']++;
            }
        }

        return $counts;
    }

    
}