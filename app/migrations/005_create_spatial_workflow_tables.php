<?php

class Create_spatial_workflow_tables {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $this->create_table('clearance_details', [
            'application_id' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => FALSE],
            'parcel_geojson' => ['type' => 'LONGTEXT', 'null' => FALSE],
            'area_sqm' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'null' => FALSE],
            'land_use_category' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => FALSE],
            'approved_land_use' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => TRUE],
            'permit_valid_until' => ['type' => 'DATE', 'null' => TRUE],
            'qr_reference' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => TRUE],
            'permit_status' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => TRUE],
            'certificate_type' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => TRUE],
            'screening_results' => ['type' => 'LONGTEXT', 'null' => TRUE],
            'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
        ], 'application_id', [], [['qr_reference']]);

        $this->create_table('project_metrics', [
            'project_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
            'target_progress' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => FALSE, 'default' => 100],
            'allocated_budget' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'null' => FALSE, 'default' => 0],
            'spent_budget' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'null' => FALSE, 'default' => 0],
            'updated_at' => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
        ], 'project_id');

        $this->create_table('project_progress_updates', [
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE],
            'project_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
            'completion' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => FALSE],
            'budget_spent' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'null' => FALSE],
            'update_note' => ['type' => 'TEXT', 'null' => FALSE],
            'photo_url' => ['type' => 'VARCHAR', 'constraint' => 2048, 'null' => TRUE],
            'latitude' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => TRUE],
            'longitude' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => TRUE],
            'created_by' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => FALSE],
            'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
        ], 'id', ['project_id']);

        $this->create_table('field_inspection_logs', [
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE],
            'application_id' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => FALSE],
            'inspector_name' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => FALSE],
            'findings' => ['type' => 'TEXT', 'null' => FALSE],
            'photo_url' => ['type' => 'VARCHAR', 'constraint' => 2048, 'null' => TRUE],
            'latitude' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => TRUE],
            'longitude' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => TRUE],
            'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
        ], 'id', ['application_id']);

        $this->create_table('spatial_layers', [
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE],
            'layer_name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => FALSE],
            'category' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => FALSE],
            'geojson' => ['type' => 'LONGTEXT', 'null' => FALSE],
            'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => FALSE, 'default' => 1],
            'updated_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
        ], 'id', [], [['category', 'layer_name']]);
    }

    private function create_table($table, $fields, $primary_key, $indexes = [], $unique_indexes = [])
    {
        if ($this->_lava->dbforge->table_exists($table)) {
            return;
        }

        $builder = $this->_lava->dbforge->add_field($fields)->add_key($primary_key, primary: TRUE);
        foreach ($indexes as $index) {
            $builder->add_key($index, name: $table . '_' . $index . '_idx');
        }
        foreach ($unique_indexes as $index) {
            $builder->add_key($index, unique: TRUE, name: $table . '_' . implode('_', $index) . '_unique');
        }
        $builder->create_table($table);
    }

    public function down()
    {
        // Workflow records are retained during rollbacks to avoid deleting permit history.
    }
}
