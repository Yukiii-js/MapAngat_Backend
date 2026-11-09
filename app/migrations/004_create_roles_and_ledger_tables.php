<?php

class Create_roles_and_ledger_tables {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $this->ensure_roles_table();
        $this->ensure_ledger_table();
        $this->seed_admin_user();
    }

    private function ensure_roles_table()
    {
        if (!$this->_lava->dbforge->table_exists('roles')) {
            $this->_lava->dbforge
                ->add_field([
                    'id' => [
                        'type'           => 'INT',
                        'unsigned'       => TRUE,
                        'auto_increment' => TRUE,
                        'null'           => FALSE,
                    ],
                    'role_name' => [
                        'type'       => 'VARCHAR',
                        'constraint' => 50,
                        'null'       => FALSE,
                    ],
                    'created_at' => [
                        'type'    => 'DATETIME',
                        'null'    => FALSE,
                        'default' => 'CURRENT_TIMESTAMP',
                    ],
                ])
                ->add_key('id', primary: TRUE)
                ->add_key('role_name', unique: TRUE, name: 'roles_name_unique')
                ->create_table('roles');
        }

        $admin_role = $this->_lava->db->table('roles')->where('role_name', 'Admin')->get();
        if (!$admin_role) {
            $this->_lava->db->table('roles')->insert([
                'role_name' => 'Admin',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function ensure_ledger_table()
    {
        if ($this->_lava->dbforge->table_exists('blockchain_ledger')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'block_index' => ['type' => 'BIGINT', 'unsigned' => TRUE, 'null' => FALSE],
                'document_type' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => FALSE],
                'document_reference' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => FALSE],
                'document_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => FALSE],
                'previous_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => FALSE],
                'block_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => FALSE],
                'block_payload' => ['type' => 'LONGTEXT', 'null' => FALSE],
                'recorded_at' => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => FALSE],
                'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
            ])
            ->add_key('block_index', primary: TRUE)
            ->add_key('block_hash', unique: TRUE, name: 'ledger_block_hash_unique')
            ->add_key('document_reference', name: 'ledger_document_reference_idx')
            ->create_table('blockchain_ledger');
    }

    private function seed_admin_user()
    {
        $existing = $this->_lava->db->table('users')->where('username', 'mpdo')->get();
        if ($existing) {
            return;
        }

        $role = $this->_lava->db->raw(
            "SELECT id FROM roles WHERE role_name = ? LIMIT 1",
            ['Admin']
        )->fetch(PDO::FETCH_ASSOC);
        $role_id = (int) ($role['id'] ?? 0);
        if ($role_id === 0) {
            throw new RuntimeException('The Admin role could not be created.');
        }

        $this->_lava->db->table('users')->insert([
            'role_id' => $role_id,
            'username' => 'mpdo',
            'password' => password_hash('mpdo123', PASSWORD_BCRYPT),
            'full_name' => 'MPDO Administrator',
            'email' => 'mpdo@mapangat.gov.ph',
            'phone_number' => NULL,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function down()
    {
        // Keep user accounts and document fingerprints when rolling back migrations.
    }
}
