<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Project_model extends Model {

    private function table_exists($table = 'projects') {
        $stmt = $this->db->raw(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            [$table]
        );

        return (int) $stmt->fetchColumn() > 0;
    }

    private function column_names($table) {
        $rows = $this->db->raw(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ?",
            [$table]
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function ($row) {
            return (string) reset($row);
        }, $rows);
    }

    public function project_create_issues() {
        $required = [
            'projects' => ['project_name', 'barangay', 'status', 'progress', 'inspection_date', 'lat', 'lng', 'created_at'],
            'project_metrics' => ['project_id', 'target_progress', 'allocated_budget', 'spent_budget', 'updated_at'],
        ];
        $issues = [];

        foreach ($required as $table => $columns) {
            $available = $this->column_names($table);
            if ($available === []) {
                $issues[] = $table . ' table';
                continue;
            }
            foreach (array_diff($columns, $available) as $column) {
                $issues[] = $table . '.' . $column;
            }
        }

        return $issues;
    }

    public function create_project($project) {
        $db = lava_instance()->db;
        $db->raw('START TRANSACTION');
        try {
            $db->raw(
                "INSERT INTO projects
                    (project_name, barangay, status, progress, inspection_date, lat, lng, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $project['project_name'],
                    $project['barangay'],
                    $project['status'],
                    $project['progress'],
                    $project['inspection_date'],
                    $project['lat'],
                    $project['lng'],
                    date('Y-m-d H:i:s'),
                ]
            );
            $project_id = (int) $db->last_id();
            $db->raw(
                "INSERT INTO project_metrics
                    (project_id, target_progress, allocated_budget, spent_budget, updated_at)
                 VALUES (?, ?, ?, ?, ?)",
                [
                    $project_id,
                    $project['target_progress'],
                    $project['allocated_budget'],
                    $project['spent_budget'],
                    date('Y-m-d H:i:s'),
                ]
            );
            $db->raw('COMMIT');
            return $project_id;
        } catch (Throwable $error) {
            $db->raw('ROLLBACK');
            throw $error;
        }
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