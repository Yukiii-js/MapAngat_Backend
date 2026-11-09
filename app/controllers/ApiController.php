<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiController extends Controller {

    public function __construct() {
        parent::__construct();
        $this->call->library('session');
        $this->call->helper('url');
        $this->call->library('api');
        $this->call->model('Clearance_model');
        $this->call->model('Project_model');
    }

    private function json_response($data, $status = 200) {
        $this->api->respond($data, $status);
    }

    public function health() {
        $this->api->require_method('GET');
        $this->json_response([
            'status' => 'ok',
            'app' => 'MapAngat API',
            'timestamp' => date('c')
        ]);
    }

    private function get_user_by_username($username) {
        $db = lava_instance()->db;
        $stmt = $db->raw(
            "SELECT u.*, COALESCE(r.role_name, 'Admin') AS role
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.username = ? LIMIT 1",
            [$username]
        );

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($user) && !empty($user) ? $user : null;
    }

    private function get_user_by_id($id) {
        $db = lava_instance()->db;
        $stmt = $db->raw(
            "SELECT u.*, COALESCE(r.role_name, 'Admin') AS role
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? LIMIT 1",
            [$id]
        );

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($user) && !empty($user) ? $user : null;
    }

    private function table_exists($table) {
        $stmt = lava_instance()->db->raw(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            [$table]
        );

        return (int) $stmt->fetchColumn() > 0;
    }

    public function login() {
        $this->api->require_method('POST');
        $body = $this->api->body();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === '') {
            $this->api->respond_error('Username and password are required', 400);
        }

        $user = $this->get_user_by_username($username);

        if (!$user || !isset($user['password']) || !password_verify($password, $user['password'])) {
            $this->api->respond_error('Invalid username or password', 401);
        }

        if (($user['status'] ?? 'active') !== 'active') {
            $this->api->respond_error('This account is inactive', 403);
        }

        $role = $user['role'] ?? 'admin';
        $token_payload = [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'role' => $role,
            'scopes' => ['read', 'write']
        ];

        $tokens = $this->api->issue_tokens($token_payload);

        $this->json_response([
            'message' => 'Login successful',
            'user' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name'] ?? '',
                'role' => $role,
                'email' => $user['email'] ?? ''
            ],
            'tokens' => $tokens
        ]);
    }

    public function me() {
        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();

        $user = $this->get_user_by_id((int) ($payload['sub'] ?? 0));

        $this->json_response([
            'user' => [
                'id' => (int) ($user['id'] ?? 0),
                'username' => $user['username'] ?? '',
                'full_name' => $user['full_name'] ?? '',
                'role' => $user['role'] ?? 'admin'
            ]
        ]);
    }

    public function refresh() {
        $this->api->require_method('POST');
        $body = $this->api->body();
        $refresh_token = trim((string) ($body['refresh_token'] ?? ''));

        if ($refresh_token === '') {
            $this->api->respond_error('Refresh token is required', 400);
        }

        $this->api->refresh_access_token($refresh_token);
    }

    private function call_ledger_service($endpoint, $payload) {
        $base_url = rtrim((string) getenv('LEDGER_SERVICE_URL'), '/');
        $service_key = (string) getenv('LEDGER_SERVICE_KEY');
        if ($base_url === '' || $service_key === '') {
            $this->api->respond_error('Document ledger service is not configured', 503);
        }

        $service_url = parse_url($base_url);
        $local_http = is_array($service_url)
            && ($service_url['scheme'] ?? '') === 'http'
            && in_array($service_url['host'] ?? '', ['localhost', '127.0.0.1'], true);
        if (!is_array($service_url)
            || empty($service_url['host'])
            || (($service_url['scheme'] ?? '') !== 'https' && !$local_http)) {
            $this->api->respond_error('Document ledger service URL must use HTTPS', 503);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nX-API-Key: {$service_key}\r\n",
                'content' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $response_body = @file_get_contents($base_url . $endpoint, false, $context);

        if ($response_body === false) {
            $this->api->respond_error('Document ledger service could not be reached', 503);
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $status = (int) $matches[1];
                break;
            }
        }

        $result = json_decode($response_body, true);
        if (!is_array($result)) {
            $this->api->respond_error('Document ledger service returned an invalid response', 502);
        }
        if ($status < 200 || $status >= 300) {
            $this->api->respond_error($result['error'] ?? 'Document ledger request failed', 502);
        }

        return $result;
    }

    private function validate_ledger_document($body) {
        $reference = trim((string) ($body['document_reference'] ?? ''));
        $document_hash = strtolower(trim((string) ($body['document_hash'] ?? '')));

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,119}$/', $reference)) {
            $this->api->respond_error('A valid document reference is required', 400);
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $document_hash)) {
            $this->api->respond_error('A valid SHA-256 document hash is required', 400);
        }

        return [$reference, $document_hash];
    }

    public function anchor_document() {
        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user = $this->get_user_by_id((int) ($payload['sub'] ?? 0));
        $role = strtolower((string) ($user['role'] ?? ''));
        if (!in_array($role, ['admin', 'mpdo'], true)) {
            $this->api->respond_error('Only MPDO staff can add documents to the ledger', 403);
        }

        $body = $this->api->body();
        [$reference, $document_hash] = $this->validate_ledger_document($body);
        $document_type = strtolower(trim((string) ($body['document_type'] ?? 'permit')));
        if (!in_array($document_type, ['permit', 'location_clearance', 'other'], true)) {
            $this->api->respond_error('Unsupported document type', 400);
        }

        $this->json_response($this->call_ledger_service('/internal/anchor', [
            'document_reference' => $reference,
            'document_hash' => $document_hash,
            'document_type' => $document_type,
            'metadata' => ['issued_by' => (string) ($user['username'] ?? '')],
        ]), 201);
    }

    public function verify_document() {
        $this->api->require_method('POST');
        $body = $this->api->body();
        [$reference, $document_hash] = $this->validate_ledger_document($body);

        $this->json_response($this->call_ledger_service('/internal/verify', [
            'document_reference' => $reference,
            'document_hash' => $document_hash,
        ]));
    }

    public function dashboard_stats() {
        $this->api->require_method('GET');
        $this->api->require_jwt();

        $clearance_counts = $this->Clearance_model->get_counts_by_status();
        $project_counts = $this->Project_model->get_counts_by_status();

        $this->json_response([
            'counts' => [
                'clearance_pending' => $clearance_counts['pending'] ?? 0,
                'clearance_review' => $clearance_counts['review'] ?? 0,
                'clearance_approved' => $clearance_counts['approved'] ?? 0,
                'projects_total' => $project_counts['total'] ?? 0,
                'projects_on_track' => $project_counts['on_track'] ?? 0,
                'projects_at_risk' => $project_counts['at_risk'] ?? 0,
                'projects_delayed' => $project_counts['delayed'] ?? 0,
            ]
        ]);
    }

    public function projects() {
        $this->api->require_method('GET');
        $this->api->require_jwt();

        $projects = $this->Project_model->get_all();
        $this->json_response(['projects' => $projects]);
    }

    public function clearances() {
        $this->api->require_method('GET');
        $this->api->require_jwt();

        $clearances = $this->Clearance_model->get_all();
        $this->json_response(['clearances' => $clearances]);
    }

    public function map_points() {
        $this->api->require_method('GET');
        $this->api->require_jwt();

        $points = [];
        $projectRows = $this->Project_model->get_all();
        foreach ($projectRows as $row) {
            if (empty($row['lat']) || empty($row['lng'])) {
                continue;
            }

            $points[] = [
                'id' => 'project-' . ($row['id'] ?? uniqid()),
                'name' => $row['project_name'] ?? 'Project',
                'type' => 'project',
                'lat' => (float) ($row['lat'] ?? 0),
                'lng' => (float) ($row['lng'] ?? 0),
                'barangay' => $row['barangay'] ?? '',
                'status' => $row['status'] ?? 'Unknown'
            ];
        }

        $clearanceRows = $this->Clearance_model->get_all();
        foreach ($clearanceRows as $row) {
            if (empty($row['lat']) || empty($row['lng'])) {
                continue;
            }

            $points[] = [
                'id' => 'clearance-' . ($row['id'] ?? uniqid()),
                'name' => ($row['applicant_name'] ?? 'Applicant') . ' - ' . ($row['project_type'] ?? $row['business_name'] ?? 'Permit'),
                'type' => 'clearance',
                'lat' => (float) ($row['lat'] ?? 0),
                'lng' => (float) ($row['lng'] ?? 0),
                'barangay' => $row['barangay'] ?? '',
                'status' => $row['status'] ?? 'Pending'
            ];
        }

        if ($this->table_exists('field_compliance_permits')) {
            $stmt = lava_instance()->db->raw(
                "SELECT p.id, p.permit_number, p.project_title, p.status,
                        p.location_latitude AS lat, p.location_longitude AS lng,
                        b.barangay_name AS barangay
                 FROM field_compliance_permits p
                 LEFT JOIN barangays b ON b.id = p.barangay_id
                 WHERE p.location_latitude IS NOT NULL AND p.location_longitude IS NOT NULL"
            );

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $points[] = [
                    'id' => 'permit-' . $row['id'],
                    'reference' => $row['permit_number'],
                    'name' => $row['project_title'],
                    'type' => 'permit',
                    'lat' => (float) $row['lat'],
                    'lng' => (float) $row['lng'],
                    'barangay' => $row['barangay'] ?? '',
                    'status' => $row['status'] ?? 'Unknown',
                ];
            }
        }

        if ($this->table_exists('hazard_reports')) {
            $stmt = lava_instance()->db->raw(
                "SELECT h.id, h.report_code, h.title, h.status, h.severity_level,
                        h.latitude AS lat, h.longitude AS lng,
                        b.barangay_name AS barangay
                 FROM hazard_reports h
                 LEFT JOIN barangays b ON b.id = h.barangay_id
                 WHERE h.latitude IS NOT NULL AND h.longitude IS NOT NULL"
            );

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $points[] = [
                    'id' => 'report-' . $row['id'],
                    'reference' => $row['report_code'],
                    'name' => $row['title'],
                    'type' => 'report',
                    'lat' => (float) $row['lat'],
                    'lng' => (float) $row['lng'],
                    'barangay' => $row['barangay'] ?? '',
                    'status' => $row['status'] ?? 'Pending',
                    'severity' => $row['severity_level'] ?? 'Unknown',
                ];
            }
        }

        $this->json_response(['points' => $points]);
    }
}
