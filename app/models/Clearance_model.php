<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Clearance_model extends Model {

    public function get_all() {
        $stmt = $this->db->raw('SHOW COLUMNS FROM location_clearances');
        $columns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $normalized_columns = ['application_id', 'project_type', 'barangay'];

        if (count(array_intersect($normalized_columns, $columns)) !== count($normalized_columns)) {
            $stmt = $this->db->raw(
                "SELECT id,
                        CONCAT('LC-', YEAR(created_at), '-', LPAD(id, 3, '0')) AS application_id,
                        applicant_name,
                        COALESCE(NULLIF(business_name, ''), 'Location clearance') AS project_type,
                        TRIM(SUBSTRING_INDEX(COALESCE(location, ''), ',', 1)) AS barangay,
                        status,
                        NULL AS lat,
                        NULL AS lng,
                        created_at,
                        updated_at
                 FROM location_clearances
                 ORDER BY created_at DESC"
            );

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $details_stmt = $this->db->raw(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            ['clearance_details']
        );
        if ((int) $details_stmt->fetchColumn() === 0) {
            return $this->db->table('location_clearances')->order_by('created_at', 'DESC')->get_all();
        }

        $stmt = $this->db->raw(
            "SELECT c.*, d.parcel_geojson, d.area_sqm, d.land_use_category, d.approved_land_use,
                    d.permit_valid_until, d.qr_reference, d.permit_status, d.certificate_type,
                    d.screening_results
             FROM location_clearances c
             LEFT JOIN clearance_details d ON d.application_id = c.application_id
             ORDER BY c.created_at DESC"
        );
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