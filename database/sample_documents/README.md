# Fingerprint demo document

`demo_permit.pdf` is a fictional sample document for practicing the QR Field Verification fingerprint flow.

## What the fingerprint does

1. An MPDO user selects the PDF and enters a document reference.
2. The browser calculates a SHA-256 digest from the PDF bytes. The PDF itself is not uploaded.
3. The backend sends the reference, document type, and digest to the document ledger.
4. The ledger appends a hash-linked record. The app displays a QR code for its public verification page.
5. To verify, select the exact same PDF and reference. The browser hashes it again and compares the digest with the ledger entry. Any byte change causes a mismatch.

## Live demo steps

In **QR Field Verification**, use:

- Reference: `DEMO-FINGERPRINT-2026-001`
- Document type: `Other`
- PDF: this `demo_permit.pdf`

Select **Add fingerprint to ledger**, then use the same reference and PDF under **Verify document fingerprint**. Do not edit or re-save the PDF between the two steps.

The anchor operation creates a permanent entry in the shared ledger. This demo PDF is fictional and must not be presented as a real permit. A successful hash match confirms only that the bytes match the anchored file; it does not prove authenticity, approval, or legal validity.

Expected SHA-256 for the unchanged sample:

`a06f6bb389ba2e871cfd3ff8b34db8d7d9abb3779aac418de85c2fef6dd91298`

## Deployment status

The live QR Field Verification page currently reports **“Document ledger service is not configured”** when anchoring. The browser-side hash calculation works, but no ledger record or verification QR was created. To enable the live demo, configure `LEDGER_SERVICE_URL` and `LEDGER_SERVICE_KEY` on the backend, and configure the corresponding `LEDGER_API_KEY` plus database settings on the ledger service, as described in the backend deployment guide.
