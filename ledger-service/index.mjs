import { readFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { timingSafeEqual } from 'node:crypto';
import { createPool } from 'mysql2/promise';
import { createBlock, GENESIS_HASH, verifyChain } from './ledger.mjs';

const port = Number(process.env.PORT || 10000);
const apiKey = process.env.LEDGER_API_KEY || '';
const requiredDatabaseSettings = ['DB_HOST', 'DB_USER', 'DB_NAME'];

if (apiKey.length < 32) {
  throw new Error('LEDGER_API_KEY must contain at least 32 characters.');
}
for (const name of requiredDatabaseSettings) {
  if (!process.env[name]) throw new Error(`${name} is required.`);
}
if (!process.env.DB_PASSWORD && !process.env.DB_PASS) {
  throw new Error('DB_PASSWORD (or the existing DB_PASS setting) is required.');
}

const sslCaPath = process.env.DB_SSL_CA || '';
const isLocalDatabase = ['localhost', '127.0.0.1'].includes(process.env.DB_HOST);
const sslEnabled = process.env.DB_SSL === 'true'
  || (process.env.DB_SSL !== 'false' && !isLocalDatabase)
  || sslCaPath !== '';
const ssl = sslEnabled
  ? {
      rejectUnauthorized: Boolean(sslCaPath),
      ...(sslCaPath ? { ca: readFileSync(sslCaPath, 'utf8') } : {}),
    }
  : undefined;

const pool = createPool({
  host: process.env.DB_HOST,
  port: Number(process.env.DB_PORT || 3306),
  user: process.env.DB_USER,
  password: process.env.DB_PASSWORD || process.env.DB_PASS,
  database: process.env.DB_NAME,
  charset: process.env.DB_CHARSET || 'utf8mb4',
  waitForConnections: true,
  connectionLimit: 5,
  timezone: 'Z',
  ssl,
});

const lockName = 'mapangat_blockchain_ledger';

function sendJson(response, status, value) {
  response.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store',
    'X-Content-Type-Options': 'nosniff',
  });
  response.end(JSON.stringify(value));
}

function hasValidApiKey(request) {
  const provided = Buffer.from(request.headers['x-api-key'] || '');
  const expected = Buffer.from(apiKey);
  return provided.length === expected.length && timingSafeEqual(provided, expected);
}

async function readJson(request) {
  const chunks = [];
  let size = 0;
  for await (const chunk of request) {
    size += chunk.length;
    if (size > 8192) throw Object.assign(new Error('Request body is too large'), { status: 413 });
    chunks.push(chunk);
  }
  try {
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
  } catch {
    throw Object.assign(new Error('Request body must be valid JSON'), { status: 400 });
  }
}

function validateDocument(body, shouldHaveType) {
  const reference = String(body.document_reference || '').trim();
  const documentHash = String(body.document_hash || '').trim().toLowerCase();
  if (!/^[A-Za-z0-9][A-Za-z0-9._/-]{0,119}$/.test(reference)) {
    throw Object.assign(new Error('A valid document reference is required'), { status: 400 });
  }
  if (!/^[a-f0-9]{64}$/.test(documentHash)) {
    throw Object.assign(new Error('A valid SHA-256 document hash is required'), { status: 400 });
  }

  const documentType = String(body.document_type || 'permit').trim().toLowerCase();
  if (shouldHaveType && !['permit', 'location_clearance', 'other'].includes(documentType)) {
    throw Object.assign(new Error('Unsupported document type'), { status: 400 });
  }

  return { reference, documentHash, documentType };
}

async function getVerifiedBlocks(connection) {
  const [rows] = await connection.query(
    `SELECT block_index, block_hash, block_payload
     FROM blockchain_ledger ORDER BY block_index ASC`,
  );
  const blocks = verifyChain(rows);
  if (blocks.some((block, index) => Number(block.index) !== index + 1)) {
    throw new Error('Ledger block sequence is not contiguous');
  }
  return blocks;
}

async function appendBlock(body) {
  const { reference, documentHash, documentType } = validateDocument(body, true);
  const metadata = {
    issuedBy: String(body.metadata?.issued_by || '').slice(0, 100),
  };
  const connection = await pool.getConnection();
  let transactionStarted = false;
  try {
    const [lockRows] = await connection.execute('SELECT GET_LOCK(?, 10) AS acquired', [lockName]);
    if (Number(lockRows[0]?.acquired) !== 1) {
      throw Object.assign(new Error('Could not acquire the document ledger lock'), { status: 503 });
    }

    await connection.beginTransaction();
    transactionStarted = true;
    const blocks = await getVerifiedBlocks(connection);
    const latest = blocks.at(-1);
    const block = createBlock({
      index: blocks.length + 1,
      documentType,
      documentReference: reference,
      documentHash,
      previousHash: latest?.currentHash || GENESIS_HASH,
      metadata,
      recordedAt: new Date().toISOString(),
    });

    await connection.execute(
      `INSERT INTO blockchain_ledger
       (block_index, document_type, document_reference, document_hash,
        previous_hash, block_hash, block_payload, recorded_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        block.index,
        block.documentType,
        block.documentReference,
        block.documentHash,
        block.previousHash,
        block.currentHash,
        JSON.stringify(block),
        block.recordedAt,
      ],
    );
    await connection.commit();
    transactionStarted = false;
    return { block };
  } catch (error) {
    if (transactionStarted) await connection.rollback();
    throw error;
  } finally {
    try {
      await connection.execute('SELECT RELEASE_LOCK(?)', [lockName]);
    } finally {
      connection.release();
    }
  }
}

async function verifyDocument(body) {
  const { reference, documentHash } = validateDocument(body, false);
  const blocks = await getVerifiedBlocks(pool);
  const block = [...blocks].reverse().find((item) => item.documentReference === reference) || null;
  return {
    verified: Boolean(block && block.documentHash === documentHash),
    block,
  };
}

const server = createServer(async (request, response) => {
  const url = new URL(request.url || '/', 'http://localhost');
  try {
    if (request.method === 'GET' && url.pathname === '/health') {
      await pool.query('SELECT 1');
      sendJson(response, 200, { status: 'ok', service: 'MapAngat document ledger' });
      return;
    }
    if (!['/internal/anchor', '/internal/verify'].includes(url.pathname)) {
      sendJson(response, 404, { error: 'Not found' });
      return;
    }
    if (!hasValidApiKey(request)) {
      sendJson(response, 401, { error: 'Unauthorized' });
      return;
    }
    if (request.method !== 'POST') {
      response.setHeader('Allow', 'POST');
      sendJson(response, 405, { error: 'Method not allowed' });
      return;
    }

    const body = await readJson(request);
    const result = url.pathname === '/internal/anchor'
      ? await appendBlock(body)
      : await verifyDocument(body);
    sendJson(response, url.pathname === '/internal/anchor' ? 201 : 200, result);
  } catch (error) {
    console.error(error);
    sendJson(response, error.status || 500, {
      error: error.status ? error.message : 'Ledger request failed',
    });
  }
});

server.listen(port, '0.0.0.0', () => {
  console.log(`MapAngat ledger listening on port ${port}`);
});

for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, async () => {
    server.close();
    await pool.end();
    process.exit(0);
  });
}
