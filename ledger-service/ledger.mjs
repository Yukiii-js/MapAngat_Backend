import { createHash } from 'node:crypto';

export const GENESIS_HASH = '0'.repeat(64);

export function hashBlock(block) {
  return createHash('sha256').update(JSON.stringify(block), 'utf8').digest('hex');
}

export function createBlock({
  index,
  documentType,
  documentReference,
  documentHash,
  previousHash,
  metadata,
  recordedAt,
}) {
  const contents = {
    index,
    documentType,
    documentReference,
    documentHash,
    previousHash,
    metadata,
    recordedAt,
  };
  return { ...contents, currentHash: hashBlock(contents) };
}

export function verifyChain(rows) {
  let previousHash = GENESIS_HASH;
  const blocks = rows.map((row) => {
    let block;
    try {
      block = JSON.parse(row.block_payload);
    } catch {
      throw new Error(`Ledger block ${row.block_index} contains invalid JSON`);
    }

    const { currentHash, ...contents } = block;
    if (
      Number(block.index) !== Number(row.block_index)
      || block.previousHash !== previousHash
      || currentHash !== row.block_hash
      || currentHash !== hashBlock(contents)
    ) {
      throw new Error(`Ledger integrity check failed at block ${row.block_index}`);
    }
    previousHash = currentHash;
    return block;
  });

  return blocks;
}
