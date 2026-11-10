<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

$router->get('/', 'Auth::login');
$router->get('login', 'Auth::login');
$router->get('logout', 'Auth::logout');
$router->get('dashboard', 'Dashboard::index');
$router->get('web-gis', 'Dashboard::webgis');
$router->get('spatial-analysis', 'Spatial::index');
$router->get('location-clearance', 'Clearance::index');
$router->get('program-monitoring', 'Projects::index');
$router->get('qr-verification', 'QRField::index');
$router->post('auth/process_login', 'Auth::process_login');
$router->post('location-clearance/store', 'Clearance::store');
$router->post('spatial-analysis/run-screening', 'Spatial::run_screening');

// API endpoints for the Vite frontend and external clients
$router->post('api/auth/login', 'ApiController::login');
$router->get('api/auth/me', 'ApiController::me');
$router->post('api/auth/refresh', 'ApiController::refresh');
$router->post('api/ledger/anchor', 'ApiController::anchor_document');
$router->post('api/ledger/verify', 'ApiController::verify_document');
$router->get('api/dashboard/stats', 'ApiController::dashboard_stats');
$router->get('api/projects', 'ApiController::projects');
$router->get('api/projects/updates', 'ApiController::project_updates');
$router->post('api/projects/update', 'ApiController::save_project_update');
$router->get('api/clearances', 'ApiController::clearances');
$router->post('api/clearances', 'ApiController::create_clearance');
$router->get('api/clearances/lookup', 'ApiController::lookup_clearance');
$router->post('api/clearances/status', 'ApiController::update_clearance_status');
$router->post('api/clearances/issue', 'ApiController::issue_clearance');
$router->post('api/inspections', 'ApiController::save_inspection');
$router->get('api/spatial/layers', 'ApiController::spatial_layers');
$router->post('api/spatial/layers', 'ApiController::save_spatial_layer');
$router->post('api/spatial/screen', 'ApiController::screen_geometry');
$router->get('api/reports/summary', 'ApiController::report_summary');
$router->get('api/map/points', 'ApiController::map_points');
$router->get('api/health', 'ApiController::health');

// Preflight requests must match the same route as the actual cross-origin request.
$router->options('api/auth/login', 'ApiController::health');
$router->options('api/auth/me', 'ApiController::health');
$router->options('api/auth/refresh', 'ApiController::health');
$router->options('api/ledger/anchor', 'ApiController::health');
$router->options('api/ledger/verify', 'ApiController::health');
$router->options('api/dashboard/stats', 'ApiController::health');
$router->options('api/projects', 'ApiController::health');
$router->options('api/projects/updates', 'ApiController::health');
$router->options('api/projects/update', 'ApiController::health');
$router->options('api/clearances', 'ApiController::health');
$router->options('api/clearances/lookup', 'ApiController::health');
$router->options('api/clearances/status', 'ApiController::health');
$router->options('api/clearances/issue', 'ApiController::health');
$router->options('api/inspections', 'ApiController::health');
$router->options('api/spatial/layers', 'ApiController::health');
$router->options('api/spatial/screen', 'ApiController::health');
$router->options('api/reports/summary', 'ApiController::health');
$router->options('api/map/points', 'ApiController::health');
$router->options('api/health', 'ApiController::health');