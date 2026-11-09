<?php

class Create_mapangat_schema {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $this->ensure_projects_table();
        $this->ensure_location_clearances_table();
        $this->ensure_barangays_table();
        $this->ensure_inspections_table();
        $this->seed_admin_user();
    }

    private function ensure_projects_table()
    {
        if ($this->_lava->dbforge->table_exists('projects')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE],
                'project_name' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => FALSE],
                'barangay' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => FALSE],
                'status' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => FALSE, 'default' => 'On track'],
                'progress' => ['type' => 'INT', 'constraint' => 3, 'unsigned' => TRUE, 'null' => FALSE, 'default' => 0],
                'inspection_date' => ['type' => 'DATE', 'null' => TRUE],
                'lat' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => TRUE],
                'lng' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => TRUE],
                'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
                'updated_at' => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('barangay', name: 'projects_barangay_idx')
            ->create_table('projects');
    }

    private function ensure_location_clearances_table()
    {
        if ($this->_lava->dbforge->table_exists('location_clearances')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE],
                'application_id' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => FALSE, 'unique' => TRUE],
                'applicant_name' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => FALSE],
                'project_type' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => FALSE],
                'barangay' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => FALSE],
                'status' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => FALSE, 'default' => 'Pending'],
                'lat' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => TRUE],
                'lng' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => TRUE],
                'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
                'updated_at' => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('application_id', unique: TRUE, name: 'location_clearances_app_id_unique')
            ->add_key('barangay', name: 'location_clearances_barangay_idx')
            ->create_table('location_clearances');
    }

    private function ensure_barangays_table()
    {
        if ($this->_lava->dbforge->table_exists('barangays')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE],
                'barangay_name' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => FALSE, 'unique' => TRUE],
                'municipality' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => FALSE, 'default' => 'Baco'],
                'province' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => FALSE, 'default' => 'Oriental Mindoro'],
                'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('barangay_name', unique: TRUE, name: 'barangays_name_unique')
            ->create_table('barangays');
    }

    private function ensure_inspections_table()
    {
        if ($this->_lava->dbforge->table_exists('inspections')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE],
                'reference_type' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => FALSE, 'default' => 'project'],
                'reference_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
                'inspector_name' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => FALSE],
                'findings' => ['type' => 'TEXT', 'null' => TRUE],
                'inspection_date' => ['type' => 'DATE', 'null' => FALSE],
                'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('reference_id', name: 'inspections_reference_idx')
            ->create_table('inspections');
    }

    private function seed_admin_user()
    {
        if ($this->table_has_columns('barangays', ['municipality', 'province'])
            && (int) $this->_lava->db->table('barangays')->count() === 0) {
            $this->_lava->db->table('barangays')->insert_batch([
                ['barangay_name' => 'Poblacion', 'municipality' => 'Baco', 'province' => 'Oriental Mindoro'],
                ['barangay_name' => 'San Andres', 'municipality' => 'Baco', 'province' => 'Oriental Mindoro'],
                ['barangay_name' => 'Bangkatan', 'municipality' => 'Baco', 'province' => 'Oriental Mindoro'],
                ['barangay_name' => 'Lantuyang', 'municipality' => 'Baco', 'province' => 'Oriental Mindoro'],
            ]);
        }

        if ((int) $this->_lava->db->table('projects')->count() === 0) {
            $this->_lava->db->table('projects')->insert_batch([
                ['project_name' => 'Baco Municipal Hall Renovation', 'barangay' => 'Poblacion', 'status' => 'On track', 'progress' => 78, 'inspection_date' => '2026-11-05', 'lat' => 13.3337, 'lng' => 121.0920],
                ['project_name' => 'Farm-to-Market Road Rehabilitation', 'barangay' => 'San Andres', 'status' => 'At risk', 'progress' => 52, 'inspection_date' => '2026-11-10', 'lat' => 13.3414, 'lng' => 121.1096],
                ['project_name' => 'Drainage Improvement', 'barangay' => 'Bangkatan', 'status' => 'Delayed', 'progress' => 33, 'inspection_date' => '2026-11-12', 'lat' => 13.3271, 'lng' => 121.0814],
            ]);
        }

        if ($this->table_has_columns('location_clearances', ['application_id', 'project_type', 'barangay'])
            && (int) $this->_lava->db->table('location_clearances')->count() === 0) {
            $this->_lava->db->table('location_clearances')->insert_batch([
                ['application_id' => 'LC-2026-AD12F4', 'applicant_name' => 'Aldrin Delos Reyes', 'project_type' => 'Roadside Stall', 'barangay' => 'Poblacion', 'status' => 'Pending', 'lat' => 13.3337, 'lng' => 121.0920],
                ['application_id' => 'LC-2026-BE35A9', 'applicant_name' => 'Leah Santos', 'project_type' => 'Solar Installation', 'barangay' => 'San Andres', 'status' => 'For review', 'lat' => 13.3414, 'lng' => 121.1096],
                ['application_id' => 'LC-2026-CF61D0', 'applicant_name' => 'Maria Dela Cruz', 'project_type' => 'Commercial Building', 'barangay' => 'Bangkatan', 'status' => 'Approved', 'lat' => 13.3271, 'lng' => 121.0814],
            ]);
        }

        if ((int) $this->_lava->db->table('inspections')->count() === 0) {
            $this->_lava->db->table('inspections')->insert_batch([
                ['reference_type' => 'project', 'reference_id' => 1, 'inspector_name' => 'Rosa Tolentino', 'findings' => 'Site works are on schedule with minor material delay.', 'inspection_date' => '2026-11-05'],
                ['reference_type' => 'project', 'reference_id' => 2, 'inspector_name' => 'Nestor Cacho', 'findings' => 'Excavation line requires additional soil compaction review.', 'inspection_date' => '2026-11-10'],
            ]);
        }
    }

    private function table_has_columns($table, $required_columns)
    {
        $stmt = $this->_lava->db->raw("SHOW COLUMNS FROM `{$table}`");
        $columns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

        return count(array_intersect($required_columns, $columns)) === count($required_columns);
    }

    public function down()
    {
        $tables = ['inspections', 'barangays', 'location_clearances', 'projects', 'users'];
        foreach ($tables as $table) {
            if ($this->_lava->dbforge->table_exists($table)) {
                $this->_lava->dbforge->drop_table($table);
            }
        }
    }
}
