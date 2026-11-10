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
        $this->call->model('Workflow_model');
        $this->call->model('Spatial_model');
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

    private function require_mpdo($payload) {
        $user = $this->get_user_by_id((int) ($payload['sub'] ?? 0));
        if (!in_array(strtolower((string) ($user['role'] ?? '')), ['admin', 'mpdo'], true)) {
            $this->api->respond_error('Only MPDO staff can perform this action', 403);
        }
        return $user;
    }

    private function validate_geometry($value) {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (isset($value['type']) && $value['type'] === 'Feature') {
            $value = $value['geometry'] ?? null;
        }
        if (!is_array($value) || !in_array($value['type'] ?? '', ['Point', 'Polygon', 'MultiPolygon'], true)) {
            $this->api->respond_error('A GeoJSON Point, Polygon or MultiPolygon is required', 400);
        }
        $coordinates = $value['coordinates'] ?? null;
        if (!$this->valid_coordinates($coordinates)) {
            $this->api->respond_error('The GeoJSON contains invalid or out-of-range coordinates', 400);
        }
        if ($value['type'] === 'Polygon' && !$this->valid_polygon($coordinates)) {
            $this->api->respond_error('Parcel polygons must contain at least four positions', 400);
        }
        if ($value['type'] === 'MultiPolygon') {
            if (empty($coordinates)) {
                $this->api->respond_error('A MultiPolygon must contain at least one polygon', 400);
            }
            foreach ($coordinates as $polygon) {
                if (!$this->valid_polygon($polygon)) {
                    $this->api->respond_error('Parcel polygons must contain closed rings with at least four positions', 400);
                }
            }
        }
        return $value;
    }

    private function valid_polygon($rings) {
        if (!is_array($rings) || empty($rings)) {
            return false;
        }
        foreach ($rings as $ring) {
            if (!is_array($ring) || count($ring) < 4
                || abs((float) $ring[0][0] - (float) $ring[count($ring) - 1][0]) > 1e-10
                || abs((float) $ring[0][1] - (float) $ring[count($ring) - 1][1]) > 1e-10) {
                return false;
            }
        }
        return true;
    }

    private function valid_coordinates($value) {
        if (!is_array($value) || count($value) < 2) {
            return false;
        }
        if (is_numeric($value[0] ?? null) && is_numeric($value[1] ?? null)) {
            return is_finite((float) $value[0]) && is_finite((float) $value[1])
                && (float) $value[0] >= -180 && (float) $value[0] <= 180
                && (float) $value[1] >= -90 && (float) $value[1] <= 90;
        }
        foreach ($value as $coordinate) {
            if (!$this->valid_coordinates($coordinate)) {
                return false;
            }
        }
        return true;
    }

    private function validate_optional_coordinate($value, $minimum, $maximum) {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || !is_finite((float) $value)
            || (float) $value < $minimum || (float) $value > $maximum) {
            $this->api->respond_error('A valid latitude and longitude are required', 400);
        }
        return (float) $value;
    }

    private function validate_photo_url($value) {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 2048 || !filter_var($value, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['https', 'http'], true)) {
            $this->api->respond_error('Photo URL must be a valid HTTP or HTTPS URL', 400);
        }
        return $value;
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

    public function project_updates() {
        $this->api->require_method('GET');
        $this->api->require_jwt();
        $project_id = (int) $this->io->get('project_id');
        if ($project_id < 1) {
            $this->api->respond_error('A valid project ID is required', 400);
        }
        $this->json_response(['updates' => $this->Workflow_model->get_project_updates($project_id)]);
    }

    public function save_project_update() {
        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user = $this->require_mpdo($payload);
        $body = $this->api->body();
        $project_id = (int) ($body['project_id'] ?? 0);
        $project = null;
        foreach ($this->Project_model->get_all() as $row) {
            if ((int) ($row['id'] ?? 0) === $project_id) {
                $project = $row;
                break;
            }
        }
        if (!$project) {
            $this->api->respond_error('Project not found', 404);
        }

        $completion = $body['completion'] ?? null;
        $target_progress = $body['target_progress'] ?? 100;
        $allocated_budget = $body['allocated_budget'] ?? 0;
        $budget_spent = $body['budget_spent'] ?? 0;
        $status = trim((string) ($body['status'] ?? ''));
        $note = trim((string) ($body['update_note'] ?? ''));
        if (!is_numeric($completion) || (float) $completion < 0 || (float) $completion > 100
            || !is_numeric($target_progress) || (float) $target_progress < 0 || (float) $target_progress > 100
            || !is_numeric($allocated_budget) || (float) $allocated_budget < 0
            || !is_numeric($budget_spent) || (float) $budget_spent < 0
            || (float) $budget_spent > (float) $allocated_budget
            || !in_array($status, ['On track', 'At risk', 'Delayed'], true)
            || $note === '' || strlen($note) > 5000) {
            $this->api->respond_error('Enter valid progress, budget, status and update details', 400);
        }

        $latitude = $this->validate_optional_coordinate($body['latitude'] ?? null, -90, 90);
        $longitude = $this->validate_optional_coordinate($body['longitude'] ?? null, -180, 180);
        if (($latitude === null) !== ($longitude === null)) {
            $this->api->respond_error('Provide both latitude and longitude for a geotagged update', 400);
        }

        $this->Workflow_model->add_project_update([
            'project_id' => $project_id,
            'completion' => (float) $completion,
            'target_progress' => (float) $target_progress,
            'allocated_budget' => (float) $allocated_budget,
            'budget_spent' => (float) $budget_spent,
            'status' => $status,
            'update_note' => $note,
            'photo_url' => $this->validate_photo_url($body['photo_url'] ?? ''),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'created_by' => (string) ($user['username'] ?? 'MPDO'),
        ]);
        $this->json_response(['message' => 'Project progress update saved'], 201);
    }

    public function clearances() {
        $this->api->require_method('GET');
        $this->api->require_jwt();

        if (!$this->Clearance_model->is_available()) {
            $this->api->respond_error(
                'Location clearance storage is not configured. Apply the MapAngat database schema before loading clearance records.',
                503
            );
        }
        $clearances = $this->Clearance_model->get_all();
        $this->json_response(['clearances' => $clearances]);
    }

    public function create_clearance() {
        $this->api->require_method('POST');
        $this->api->require_jwt();
        $body = $this->api->body();
        $applicant = trim((string) ($body['applicant_name'] ?? ''));
        $project_type = trim((string) ($body['project_type'] ?? ''));
        $barangay = trim((string) ($body['barangay'] ?? ''));
        $land_use = trim((string) ($body['land_use_category'] ?? ''));
        if ($applicant === '' || strlen($applicant) > 150 || $project_type === '' || strlen($project_type) > 150
            || $barangay === '' || strlen($barangay) > 100
            || !in_array($land_use, ['Agriculture', 'Commercial', 'Residential', 'Institutional', 'Industrial', 'Other'], true)) {
            $this->api->respond_error('Complete the applicant, project, barangay and land-use fields', 400);
        }

        $geometry = $this->validate_geometry($body['parcel_geojson'] ?? null);
        $layers = $this->Workflow_model->get_spatial_layers();
        $screening = $this->Spatial_model->screen_geometry($geometry, $layers);
        $area_sqm = $this->Spatial_model->calculate_area($geometry);
        $positions = $geometry['type'] === 'Point'
            ? [$geometry['coordinates']]
            : ($geometry['type'] === 'Polygon' ? $geometry['coordinates'][0] : $geometry['coordinates'][0][0]);
        $longitude = 0.0;
        $latitude = 0.0;
        foreach ($positions as $position) {
            $longitude += (float) $position[0];
            $latitude += (float) $position[1];
        }
        $longitude /= count($positions);
        $latitude /= count($positions);
        $application_id = 'LC-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(6)));
        $db = lava_instance()->db;
        $db->raw('START TRANSACTION');
        try {
            $this->Clearance_model->insert_application([
                'application_id' => $application_id,
                'applicant_name' => $applicant,
                'project_type' => $project_type,
                'barangay' => $barangay,
                'status' => 'Submitted',
                'lat' => $latitude,
                'lng' => $longitude,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $this->Workflow_model->add_clearance_details([
                'application_id' => $application_id,
                'parcel_geojson' => json_encode($geometry, JSON_UNESCAPED_SLASHES),
                'area_sqm' => $area_sqm,
                'land_use_category' => $land_use,
                'screening_results' => json_encode($screening, JSON_UNESCAPED_SLASHES),
            ]);
            $db->raw('COMMIT');
        } catch (Throwable $error) {
            $db->raw('ROLLBACK');
            throw $error;
        }
        $this->json_response([
            'application_id' => $application_id,
            'status' => 'Submitted',
            'area_sqm' => round($area_sqm, 2),
            'screening' => $screening,
            'layers_available' => count($layers),
        ], 201);
    }

    public function lookup_clearance() {
        $this->api->require_method('GET');
        $this->api->require_jwt();
        $reference = trim((string) $this->io->get('reference'));
        if ($reference === '' || strlen($reference) > 80) {
            $this->api->respond_error('Enter a clearance ID or QR reference', 400);
        }
        $clearance = $this->Clearance_model->get_by_reference($reference);
        if (!$clearance) {
            $this->api->respond_error('Clearance record not found', 404);
        }
        $permit_status = (string) ($clearance['permit_status'] ?? '');
        if ($permit_status === 'Valid' && !empty($clearance['permit_valid_until'])
            && $clearance['permit_valid_until'] < date('Y-m-d')) {
            $permit_status = 'Expired';
        }
        $clearance['permit_validity'] = $permit_status ?: 'Not issued';
        $clearance['screening_results'] = json_decode((string) ($clearance['screening_results'] ?? '[]'), true) ?: [];
        $clearance['parcel_geojson'] = json_decode((string) ($clearance['parcel_geojson'] ?? ''), true);
        $this->json_response(['clearance' => $clearance]);
    }

    public function update_clearance_status() {
        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $this->require_mpdo($payload);
        $body = $this->api->body();
        $application_id = trim((string) ($body['application_id'] ?? ''));
        $status = trim((string) ($body['status'] ?? ''));
        $allowed = ['Submitted', 'Under Review', 'Field Inspection Scheduled', 'Rejected'];
        if ($application_id === '' || !in_array($status, $allowed, true)) {
            $this->api->respond_error('Choose a valid application and lifecycle status', 400);
        }
        if (!$this->Clearance_model->get_by_reference($application_id)) {
            $this->api->respond_error('Clearance record not found', 404);
        }
        $this->Workflow_model->update_clearance_status($application_id, $status);
        if ($status === 'Rejected') {
            $this->Workflow_model->set_permit_status($application_id, 'Revoked');
        }
        $this->json_response(['message' => 'Clearance status updated']);
    }

    public function issue_clearance() {
        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $this->require_mpdo($payload);
        $body = $this->api->body();
        $application_id = trim((string) ($body['application_id'] ?? ''));
        $approved_land_use = trim((string) ($body['approved_land_use'] ?? ''));
        $certificate_type = trim((string) ($body['certificate_type'] ?? ''));
        $valid_until = trim((string) ($body['valid_until'] ?? ''));
        $clearance = $this->Clearance_model->get_by_reference($application_id);
        if (!$clearance) {
            $this->api->respond_error('Clearance record not found', 404);
        }
        if (($clearance['status'] ?? '') === 'Rejected') {
            $this->api->respond_error('A rejected application cannot be issued', 409);
        }
        if ($approved_land_use === '' || strlen($approved_land_use) > 100
            || !in_array($certificate_type, ['Zoning Clearance', 'Locational Certificate'], true)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid_until)
            || strtotime($valid_until) === false || $valid_until < date('Y-m-d')) {
            $this->api->respond_error('Provide an approved land use, certificate type and valid expiration date', 400);
        }
        $qr_reference = 'BCO-LC-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(10)));
        $this->Workflow_model->issue_clearance(
            $application_id,
            $approved_land_use,
            $certificate_type,
            $valid_until,
            $qr_reference
        );
        $this->json_response(['qr_reference' => $qr_reference, 'permit_status' => 'Valid'], 201);
    }

    public function save_inspection() {
        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $body = $this->api->body();
        $application_id = trim((string) ($body['application_id'] ?? ''));
        $inspector_name = trim((string) ($body['inspector_name'] ?? ''));
        $findings = trim((string) ($body['findings'] ?? ''));
        if (!$this->Clearance_model->get_by_reference($application_id)) {
            $this->api->respond_error('Clearance record not found', 404);
        }
        if ($inspector_name === '' || strlen($inspector_name) > 150 || $findings === '' || strlen($findings) > 5000) {
            $this->api->respond_error('Inspector name and inspection findings are required', 400);
        }
        $latitude = $this->validate_optional_coordinate($body['latitude'] ?? null, -90, 90);
        $longitude = $this->validate_optional_coordinate($body['longitude'] ?? null, -180, 180);
        if (($latitude === null) !== ($longitude === null)) {
            $this->api->respond_error('Provide both latitude and longitude for a geotagged inspection', 400);
        }
        $this->Workflow_model->add_inspection([
            'application_id' => $application_id,
            'inspector_name' => $inspector_name,
            'findings' => $findings,
            'photo_url' => $this->validate_photo_url($body['photo_url'] ?? ''),
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
        $this->json_response(['message' => 'Field inspection submitted'], 201);
    }

    public function spatial_layers() {
        $this->api->require_method('GET');
        $this->api->require_jwt();
        $this->json_response(['layers' => $this->Workflow_model->get_spatial_layers()]);
    }

    public function save_spatial_layer() {
        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $this->require_mpdo($payload);
        $body = $this->api->body();
        $name = trim((string) ($body['layer_name'] ?? ''));
        $category = trim((string) ($body['category'] ?? ''));
        $geojson = $body['geojson'] ?? null;
        if ($name === '' || strlen($name) > 120 || !in_array($category, ['zoning', 'hazard', 'waterbody'], true)
            || !is_array($geojson) || !in_array($geojson['type'] ?? '', ['Feature', 'FeatureCollection', 'Polygon', 'MultiPolygon'], true)) {
            $this->api->respond_error('Provide a layer name, supported category and valid GeoJSON', 400);
        }
        $encoded = json_encode($geojson, JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) > 5242880) {
            $this->api->respond_error('GeoJSON layer must be valid and no larger than 5 MB', 400);
        }
        $features = ($geojson['type'] ?? '') === 'FeatureCollection'
            ? ($geojson['features'] ?? [])
            : [(($geojson['type'] ?? '') === 'Feature') ? $geojson : ['type' => 'Feature', 'geometry' => $geojson]];
        if (!is_array($features) || empty($features)) {
            $this->api->respond_error('The GeoJSON layer must contain at least one feature', 400);
        }
        foreach ($features as $feature) {
            $feature_geometry = $feature['geometry'] ?? null;
            if (!is_array($feature_geometry)) {
                $this->api->respond_error('Each GeoJSON feature must have a supported geometry', 400);
            }
            $this->validate_geometry($feature_geometry);
        }
        $this->Workflow_model->save_spatial_layer($name, $category, $encoded);
        $this->json_response(['message' => 'Planning layer saved']);
    }

    public function screen_geometry() {
        $this->api->require_method('POST');
        $this->api->require_jwt();
        $body = $this->api->body();
        $geometry = $this->validate_geometry($body['parcel_geojson'] ?? null);
        $layers = $this->Workflow_model->get_spatial_layers();
        $matches = $this->Spatial_model->screen_geometry($geometry, $layers);
        $this->json_response([
            'area_sqm' => round($this->Spatial_model->calculate_area($geometry), 2),
            'layers_available' => count($layers),
            'matches' => $matches,
        ]);
    }

    public function report_summary() {
        $this->api->require_method('GET');
        $this->api->require_jwt();
        $clearances = $this->Clearance_model->get_all();
        $projects = $this->Project_model->get_all();
        $by_barangay = [];
        foreach ($clearances as $row) {
            $name = (string) ($row['barangay'] ?? 'Unspecified');
            $by_barangay[$name]['clearances'] = ($by_barangay[$name]['clearances'] ?? 0) + 1;
        }
        foreach ($projects as $row) {
            $name = (string) ($row['barangay'] ?? 'Unspecified');
            $by_barangay[$name]['projects'] = ($by_barangay[$name]['projects'] ?? 0) + 1;
        }
        $this->json_response([
            'counts' => ['clearances' => count($clearances), 'projects' => count($projects)],
            'by_barangay' => $by_barangay,
        ]);
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
