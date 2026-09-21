/**
 * BillPay webhook receivers (Express 4/5).  npm i express @types/express
 */
import crypto from 'node:crypto';
import express from 'express';
import { BillPayClient } from './billpayClient';

declare function upsertBillers(billers: any[]): Promise<void>;          // your DB code
declare function upsertPayment(payment: any): Promise<void>;            // keyed on PaymentId
declare function alertOperations(err: unknown): void;

export const app = express();

// Set only once the BillPay team has configured the token.
const WEBHOOK_TOKEN = process.env.BILLPAY_WEBHOOK_TOKEN;
const SECRET_KEY = process.env.BILLPAY_SECRET_KEY ?? '';

const safeEqual = (a: string, b: string) =>
  a.length === b.length && crypto.timingSafeEqual(Buffer.from(a), Buffer.from(b));

// Vendor: biller configuration changed -> ["CODE1","CODE2"]
app.post('/webhooks/billpay/config', express.json({ type: () => true }), async (req, res) => {
  if (WEBHOOK_TOKEN && !safeEqual(req.headers.authorization ?? '', `Bearer ${WEBHOOK_TOKEN}`)) {
    return res.sendStatus(401);   // header format is ambiguous in the docs -- confirm with Paynow
  }
  const codes: string[] = Array.isArray(req.body)
    ? req.body.filter((c: unknown) => typeof c === 'string' && c) : [];
  res.sendStatus(200);            // respond fast: BillPay retries every 30 s, up to 3 times
  if (codes.length === 0) return; // never fall back to an unfiltered ListBillers

  try {
    await upsertBillers(await BillPayClient.fromEnv().listBillers(codes));   // ALWAYS filtered
  } catch (err) {
    console.error('BillPay config resync failed', err);
    alertOperations(err);
  }
});

function legacyHashOk(data: any): boolean {
  const plain = (data.Payments ?? []).map((p: any) =>
    `${p.PaymentId}${p.BillPayReference}${p.BankReference}${p.PaidDate}${p.MemberNumber}` +
    `${p.MemberName}${p.ProductCode}${Number(p.ProductPrice).toFixed(2)}${p.ProductDepartment ?? ''}`).join('');
  const expected = crypto.createHash('sha256').update(plain + SECRET_KEY, 'utf8').digest('hex');
  return safeEqual(expected, String(data.Hash ?? '').toLowerCase());
}

// Biller: payment notification. HMAC over the RAW body, before JSON parsing.
app.post('/webhooks/billpay/payments', express.raw({ type: () => true }), async (req, res) => {
  if (!SECRET_KEY || !Buffer.isBuffer(req.body)) return res.sendStatus(401);
  const raw: Buffer = req.body;
  const signature = req.headers['x-signature'];
  let data: any;
  try { data = JSON.parse(raw.toString('utf8')); } catch { return res.sendStatus(400); }

  if (typeof signature === 'string' && signature) {
    const computed = crypto.createHmac('sha256', SECRET_KEY).update(raw).digest('base64');
    if (!safeEqual(signature, computed)) return res.sendStatus(401);
  } else if (!legacyHashOk(data)) {
    return res.sendStatus(401);
  }

  for (const p of data.Payments ?? []) await upsertPayment(p);  // idempotent on PaymentId
  res.sendStatus(200);
});
