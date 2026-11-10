<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Project_model extends Model {

    private function table_exists() {
        $stmt = $this->db->raw(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            ['projects']
        );

        return (int) $stmt->fetchColumn() > 0;
    }

    public function get_all() {
        if (!$this->table_exists()) {
            return [];
        }

        $stmt = $this->db->raw(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            ['project_metrics']
        );
        if ((int) $stmt->fetchColumn() === 0) {
            return $this->db->table('projects')->get_all();
        }

        return $this->db->raw(
            "SELECT p.*, COALESCE(m.target_progress, 100) AS target_progress,
                    COALESCE(m.allocated_budget, 0) AS allocated_budget,
                    COALESCE(m.spent_budget, 0) AS spent_budget
             FROM projects p
             LEFT JOIN project_metrics m ON m.project_id = p.id
             ORDER BY p.created_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get_counts_by_status() {
        if (!$this->table_exists()) {
            return [
                'on_track' => 0,
                'at_risk' => 0,
                'delayed' => 0,
                'total' => 0,
            ];
        }

        return array(
            'on_track' => $this->db->table('projects')->where('status', 'On track')->count(),
            'at_risk'  => $this->db->table('projects')->where('status', 'At risk')->count(),
            'delayed'  => $this->db->table('projects')->where('status', 'Delayed')->count(),
            'total'    => $this->db->table('projects')->count()
        );
    }
}