<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ExternalApiController extends Controller
{
    private $externalHazards;

    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->externalHazards = $this->call->library('ExternalHazardService');
    }

    private function json_response($data, $status = 200)
    {
        $this->api->respond($data, $status);
    }

    private function require_mpdo()
    {
        $payload = $this->api->require_jwt();
        if (!in_array(strtolower((string) ($payload['role'] ?? '')), ['admin', 'mpdo'], true)) {
            $this->api->respond_error('Only MPDO staff can perform this action', 403);
        }
    }

    private function require_schema()
    {
        if (!$this->externalHazards->tables_available()) {
            $this->api->respond_error('External API tables are not installed. Apply database/migrations/external_apis.sql first.', 503);
        }
    }

    public function resident_feed()
    {
        $this->api->require_method('GET');
        $this->require_schema();
        $this->json_response($this->externalHazards->resident_feed());
    }

    public function admin_apis()
    {
        $this->api->require_method('GET');
        $this->require_mpdo();
        $this->require_schema();
        $this->json_response($this->externalHazards->admin_data());
    }

    public function sync($key)
    {
        $this->api->require_method('POST');
        $this->require_mpdo();
        $this->require_schema();
        try {
            $this->json_response($this->externalHazards->sync($key));
        } catch (InvalidArgumentException $error) {
            $this->api->respond_error($error->getMessage(), 404);
        } catch (RuntimeException $error) {
            $this->json_response([
                'error' => $error->getMessage(),
                'cached_fallback' => $this->externalHazards->resident_feed(),
            ], 502);
        }
    }

    public function update_config($key)
    {
        $this->api->require_method('PATCH');
        $this->require_mpdo();
        $this->require_schema();
        try {
            $config = $this->externalHazards->update_config($key, $this->api->body());
            $this->json_response(['config' => $config]);
        } catch (InvalidArgumentException $error) {
            $this->api->respond_error($error->getMessage(), 400);
        }
    }

    public function update_event_visibility($id)
    {
        $this->api->require_method('PATCH');
        $this->require_mpdo();
        $this->require_schema();
        $body = $this->api->body();
        if (!array_key_exists('is_published_to_residents', $body)) {
            $this->api->respond_error('is_published_to_residents is required', 400);
        }
        try {
            if (!$this->externalHazards->set_event_visibility($id, $body['is_published_to_residents'])) {
                $this->api->respond_error('External event not found', 404);
            }
            $this->json_response(['message' => 'Resident feed visibility updated']);
        } catch (InvalidArgumentException $error) {
            $this->api->respond_error($error->getMessage(), 400);
        }
    }

    public function update_dataset_visibility($id)
    {
        $this->api->require_method('PATCH');
        $this->require_mpdo();
        $this->require_schema();
        $body = $this->api->body();
        if (!array_key_exists('is_active_layer', $body)) {
            $this->api->respond_error('is_active_layer is required', 400);
        }
        try {
            if (!$this->externalHazards->set_dataset_active($id, $body['is_active_layer'])) {
                $this->api->respond_error('HDX dataset not found', 404);
            }
            $this->json_response(['message' => 'HDX layer visibility updated']);
        } catch (InvalidArgumentException $error) {
            $this->api->respond_error($error->getMessage(), 400);
        }
    }
}
