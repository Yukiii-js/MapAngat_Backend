<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Clearance_model extends Model {

    private function columns() {
        return array_column(
            $this->db->raw(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ?",
                ['location_clearances']
            )->fetchAll(PDO::FETCH_ASSOC),
            'column_name'
        );
    }

    private function first_column($columns, $candidates) {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        return null;
    }

    public function is_available() {
        $stmt = $this->db->raw(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            ['location_clearances']
        );
        return (int) $stmt->fetchColumn() > 0;
    }

    public function supports_workflow_create() {
        if (!$this->is_available()) {
            return false;
        }

        $clearance_columns = $this->columns();
        $required_clearance_columns = [
            'application_id', 'applicant_name', 'project_type', 'barangay',
            'status', 'lat', 'lng', 'created_at',
        ];
        if (count(array_intersect($required_clearance_columns, $clearance_columns)) !== count($required_clearance_columns)) {
            return false;
        }

        $details_stmt = $this->db->raw(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ?",
            ['clearance_details']
        );
        $details_columns = array_column($details_stmt->fetchAll(PDO::FETCH_ASSOC), 'column_name');
        $required_details_columns = [
            'application_id', 'parcel_geojson', 'area_sqm', 'land_use_category',
            'approved_land_use', 'permit_valid_until', 'qr_reference', 'permit_status',
            'certificate_type', 'screening_results', 'created_at', 'updated_at',
        ];
        return count(array_intersect($required_details_columns, $details_columns)) === count($required_details_columns);
    }

    public function get_all() {
        if (!$this->is_available()) {
            return [];
        }

        $columns = $this->columns();
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
            return $name !== null && in_array($name, $columns, true) ? "c.`{$name}`" : $fallback;
        };
        $id_column = $this->first_column($columns, ['id', 'clearance_id', 'record_id']);
        $date_column = $this->first_column($columns, ['created_at', 'submitted_at', 'submitted_date', 'date_submitted', 'application_date']);
        $reference_column = $this->first_column(
            $columns,
            ['application_id', 'application_code', 'application_no', 'application_ref', 'reference_number', 'ref_no', 'ref_number', 'tracking_number', 'clearance_number', 'permit_number']
        );
        $applicant_column = $this->first_column($columns, ['applicant_name', 'applicant_full_name', 'applicant', 'owner_name', 'business_owner', 'client_name', 'full_name', 'name']);
        $project_column = $this->first_column($columns, ['project_type', 'business_name', 'project_name', 'project_title', 'development_type', 'nature_of_project', 'business_type']);
        $barangay_column = $this->first_column($columns, ['barangay', 'barangay_name']);
        $location_column = $this->first_column($columns, ['location', 'project_location', 'address']);
        $id = $column($id_column, 'NULL');
        $created_at = $column($date_column, 'NULL');
        $application_id = $column($reference_column, 'NULL');
        if ($id_column !== null) {
            $year = $date_column !== null ? "YEAR(c.`{$date_column}`)" : 'YEAR(CURRENT_DATE())';
            $generated_reference = "CONCAT('LC-', {$year}, '-', LPAD(c.`{$id_column}`, 6, '0'))";
            $application_id = $reference_column !== null
                ? "COALESCE(NULLIF(c.`{$reference_column}`, ''), {$generated_reference})"
                : $generated_reference;
        }
        $project_type = $column($project_column, "'Location clearance'");
        $barangay = $column($barangay_column, "''");
        if ($barangay_column === null && $location_column !== null) {
            $barangay = "TRIM(SUBSTRING_INDEX(COALESCE(c.`{$location_column}`, ''), ',', 1))";
        }
        $select = [
            "{$id} AS id",
            "{$application_id} AS application_id",
            $column($applicant_column, "''") . ' AS applicant_name',
            "{$project_type} AS project_type",
            "{$barangay} AS barangay",
            $column($this->first_column($columns, ['status', 'application_status', 'clearance_status']), "'Pending'") . ' AS status',
            $column($this->first_column($columns, ['lat', 'latitude', 'location_latitude']), 'NULL') . ' AS lat',
            $column($this->first_column($columns, ['lng', 'longitude', 'location_longitude']), 'NULL') . ' AS lng',
            "{$created_at} AS created_at",
            $column($this->first_column($columns, ['updated_at', 'modified_at']), 'NULL') . ' AS updated_at',
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
        $sql .= $date_column !== null
            ? " ORDER BY c.`{$date_column}` DESC"
            : ($id_column !== null ? " ORDER BY c.`{$id_column}` DESC" : '');

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

    public function update_status($reference, $status) {
        $record = $this->get_by_reference($reference);
        if (!$record) {
            return false;
        }
        $columns = $this->columns();
        $status_column = $this->first_column($columns, ['status', 'application_status']);
        if ($status_column === null) {
            return false;
        }

        if (in_array('application_id', $columns, true)) {
            $updated_column = $this->first_column($columns, ['updated_at', 'modified_at']);
            if ($updated_column !== null) {
                $this->db->raw(
                    "UPDATE location_clearances SET `{$status_column}` = ?, `{$updated_column}` = ? WHERE application_id = ?",
                    [$status, date('Y-m-d H:i:s'), $record['application_id']]
                );
            } else {
                $this->db->raw(
                    "UPDATE location_clearances SET `{$status_column}` = ? WHERE application_id = ?",
                    [$status, $record['application_id']]
                );
            }
            return true;
        }

        $id_column = $this->first_column($columns, ['id', 'clearance_id', 'record_id']);
        if ($id_column === null || !isset($record['id'])) {
            return false;
        }
        $this->db->raw(
            "UPDATE location_clearances SET `{$status_column}` = ? WHERE `{$id_column}` = ?",
            [$status, $record['id']]
        );
        return true;
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