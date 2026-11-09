import test from 'node:test';
import assert from 'node:assert/strict';
import { createBlock, GENESIS_HASH, verifyChain } from './ledger.mjs';

const firstBlock = createBlock({
  index: 1,
  documentType: 'permit',
  documentReference: 'BCO-2026-001',
  documentHash: 'a'.repeat(64),
  previousHash: GENESIS_HASH,
  metadata: { issuedBy: 'mpdo' },
  recordedAt: '2026-11-10T00:00:00.000Z',
});

test('creates and verifies a linked block', () => {
  const rows = [{
    block_index: 1,
    block_hash: firstBlock.currentHash,
    block_payload: JSON.stringify(firstBlock),
  }];
  assert.deepEqual(verifyChain(rows), [firstBlock]);
});

test('rejects a changed block payload', () => {
  const changedBlock = { ...firstBlock, documentHash: 'b'.repeat(64) };
  const rows = [{
    block_index: 1,
    block_hash: firstBlock.currentHash,
    block_payload: JSON.stringify(changedBlock),
  }];
  assert.throws(() => verifyChain(rows), /integrity check failed/);
});

test('rejects a broken previous-hash link', () => {
  const secondBlock = createBlock({
    index: 2,
    documentType: 'permit',
    documentReference: 'BCO-2026-002',
    documentHash: 'c'.repeat(64),
    previousHash: 'd'.repeat(64),
    metadata: {},
    recordedAt: '2026-11-10T00:01:00.000Z',
  });
  const rows = [
    { block_index: 1, block_hash: firstBlock.currentHash, block_payload: JSON.stringify(firstBlock) },
    { block_index: 2, block_hash: secondBlock.currentHash, block_payload: JSON.stringify(secondBlock) },
  ];
  assert.throws(() => verifyChain(rows), /integrity check failed/);
});
