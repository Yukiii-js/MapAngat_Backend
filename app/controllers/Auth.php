<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Auth extends Controller {

    public function __construct() {
        parent::__construct();
        $this->call->helper('url');
        $this->call->library('session');
    }

    public function index() {
        $this->login();
    }

    public function login() {
        $data['title'] = 'Sign In - MapAngat MPDO';
        $this->call->view('auth/login', $data);
    }

    public function process_login() {
        $username = trim((string) $this->io->post('username'));
        $password = (string) $this->io->post('password');

        if ($username === '' || $password === '') {
            $this->session->set_flashdata('login_error', 'Username and password are required.');
            redirect('/');
        }

        $stmt = $this->db->raw(
            "SELECT u.*, COALESCE(r.role_name, 'Admin') AS role
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.username = ? LIMIT 1",
            [$username]
        );
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !isset($user['password']) || !password_verify($password, $user['password'])) {
            $this->session->set_flashdata('login_error', 'Invalid username or password.');
            redirect('/');
        }

        if (($user['status'] ?? 'active') !== 'active') {
            $this->session->set_flashdata('login_error', 'This account is inactive.');
            redirect('/');
        }

        $this->session->set_userdata([
            'logged_in' => true,
            'user_id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'] ?? 'Admin',
            'email' => $user['email'] ?? ''
        ]);

        redirect('dashboard');
    }

    public function logout() {
        $this->session->unset_userdata(['logged_in', 'username']);
        redirect('/');
    }
}