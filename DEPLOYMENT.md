# MapAngat deployment and database setup

## Database

The supplied database dump targets the MySQL schema `defaultdb` and already includes the location-clearance and spatial-workflow tables. Configure the backend and ledger service to use that same schema. Do not import the full dump over a live database: it contains `DROP TABLE` statements and existing records.

The application uses `migrations`, `roles`, `users`, `refresh_tokens`, `barangays`, `projects`, `location_clearances`, `inspections`, and `blockchain_ledger`. `field_compliance_permits` and `hazard_reports` are optional integrations checked by the map API; they are not required by this project.

The spatial-clearance, project progress, field inspection, and GeoJSON layer workflows require the tables in [`database/005_spatial_workflows.sql`](./database/005_spatial_workflows.sql). Run that additive SQL script only if those tables are absent from the database used by the API; it does not modify existing records. The equivalent application migration is [`app/migrations/005_create_spatial_workflow_tables.php`](./app/migrations/005_create_spatial_workflow_tables.php); application migrations are disabled by default.

New location-clearance submissions also require the normalized `location_clearances` columns (`application_id`, `applicant_name`, `project_type`, `barangay`, `status`, `lat`, `lng`, and `created_at`). An older table is not rewritten automatically; migrate its existing records and schema before enabling submissions. The API returns a setup error rather than saving a partial application when the required schema is missing. The camera scanner and geotagging require HTTPS and browser permission; the configured Permissions-Policy allows these features for the same-origin app.

To replace the initial password, generate a hash on a trusted machine and update only the hash:

```sh
php -r "echo password_hash('YOUR_NEW_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
```

```sql
UPDATE users SET password = 'PASTE_THE_GENERATED_HASH' WHERE username = 'mpdo';
```

## Render services

The frontend is a static site, the LavaLust API is a Docker web service, and the JavaScript ledger is a small Node web service. No paid news or mapping API keys are needed.

### Backend

- Root directory: `MapAngat_Backend`
- Runtime: Docker, using the included `Dockerfile`
- Set `APP_ENV=production` and `APP_KEY` to a new random value.
- Set `DB_DRIVER=mysql`, `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_NAME=defaultdb`, and `DB_CHARSET=utf8mb4` for the database in the supplied dump. The application accepts `DB_PASSWORD` or the existing `DB_PASS` setting.
- Remote MySQL connections use TLS by default. If the provider supplies a CA certificate, add it as a secret file and set `DB_SSL_CA` to its path for certificate validation. Set `DB_SSL=false` only for local development.
- Set independent, randomly generated `JWT_SECRET` and `REFRESH_TOKEN_KEY` values (at least 32 characters each).
- Set `CORS_ALLOWED_ORIGINS` to the exact frontend origin, without a trailing slash. Multiple origins may be comma-separated.
- Set `LEDGER_SERVICE_URL` to the deployed ledger service URL and `LEDGER_SERVICE_KEY` to the shared random key described below.
- The API health check is `/api/health`.

The MPDO workspace now includes location-clearance intake and lifecycle management, project progress and budget updates, field QR verification and geotagged inspection logs, spatial analytics, and CSV/printable report exports. Parcel screening uses only GeoJSON planning layers uploaded by authorized MPDO staff at Spatial Intelligence & Analysis. Until authoritative zoning and hazard datasets are configured, the application explicitly reports that screening is unavailable; it does not infer a zoning decision from demo data.

The Docker entrypoint listens on Render's `PORT`. The Vite development proxy targets LavaLust's local default port, `3000`.

Generate `APP_KEY`, `JWT_SECRET`, `REFRESH_TOKEN_KEY`, and `LEDGER_SERVICE_KEY` as separate random values of at least 32 characters. For example, run `php -r "echo bin2hex(random_bytes(32));"` several times on a trusted machine. The backend's `LEDGER_SERVICE_KEY` must equal the ledger service's `LEDGER_API_KEY`.

### Frontend

- Root directory: `MapAngat_Frontend`
- Build command: `npm install && npm run build`
- Publish directory: `dist`
- Set `VITE_API_BASE_URL` to the backend's HTTPS origin, without a trailing slash. Vite embeds this value at build time, so redeploy after changing it.

### JavaScript document ledger

- Repository: the same backend repository
- Root directory: `ledger-service`
- Runtime: Node 20 or newer
- Build command: `npm install`
- Start command: `npm start`
- Set `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_NAME`, and `DB_CHARSET` to the same database used by the PHP backend. The service accepts `DB_PASSWORD` or `DB_PASS`.
- Remote MySQL connections use TLS by default; add the provider CA file as a secret file and set `DB_SSL_CA` to its path for certificate validation.
- Set `LEDGER_API_KEY` to a random value of at least 32 characters. Set the exact same value as backend `LEDGER_SERVICE_KEY`.
- The health check is `/health`.

The backend calls the ledger over HTTPS using the shared key. Do not put that key in the frontend or commit it to source control. The ledger table must be created with the SQL script before starting the Node service.

## Document fingerprint workflow

1. MPDO staff select an issued PDF and enter its reference in the staff workspace.
2. The browser computes SHA-256 locally; the PDF is not uploaded or stored by the ledger.
3. The backend checks the user's JWT and role, then asks the Node service to append a hash-linked block in MySQL.
4. Verification computes the selected PDF's hash in the browser and compares it with the ledger entry for that reference.

The ledger is a tamper-evident SHA-256 hash chain stored in the project's MySQL database. It is not a decentralized public blockchain: a database administrator with write access could alter the chain, and a hash match does not establish that a permit is valid or approved. Retain the issued PDF and restrict database write access appropriately.

## Credential safety

The deployment screenshots attached to the setup request included database credentials. Treat the exposed database password as compromised: rotate it with the database provider, then update both deployed services. Keep all credentials and signing keys in the hosting provider's environment/secrets settings, not in source files or the frontend.
