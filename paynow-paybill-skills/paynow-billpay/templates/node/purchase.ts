/**
 * Vendor purchase flow: AUTH -> confirm -> charge -> PAY -> (pending -> STATUS) -> deliver.
 * In production run pollUntilFinal in a job queue (BullMQ etc.), not in the HTTP request.
 */
import { randomUUID } from 'node:crypto';
import {
  BillPayClient, BillPayTransportError, BillPayValidationError, FINAL_STATUSES,
  PaymentProduct, PaymentRequest, PaymentResponse,
} from './billpayClient';

export interface Hooks {
  chargeCustomer(reference: string, amount: number): Promise<void>;          // throw on failure
  refundCustomer(reference: string, amount: number, reason: string): Promise<void>;
  sendReceiptHtml(reference: string, html: string): Promise<void>;          // one entry per call
  sendSms(reference: string, text: string): Promise<void>;                  // one SMS per call
  confirmWithCustomer(info: Record<string, unknown>): Promise<boolean>;
  escalate(reference: string, status: string | undefined): Promise<void>;
  save(reference: string, data: Record<string, unknown>): Promise<void>;    // persist state
}

const sleep = (s: number) => new Promise((r) => setTimeout(r, s * 1000));

/** STATUS 120 s after pending/unknown, then every 180 s (600 s while Flagged). */
export async function pollUntilFinal(client: BillPayClient, reference: string, maxAttempts = 10) {
  await sleep(120);
  let result: PaymentResponse | undefined;
  for (let i = 0; i < maxAttempts; i++) {
    try {
      result = await client.status(reference);
    } catch {
      await sleep(180);            // transient failure: keep the cadence
      continue;
    }
    if (FINAL_STATUSES.has(result.Status ?? '')) return result;
    await sleep(result.Status === 'Flagged' ? 600 : 180);
  }
  return result;                   // still non-final -> escalate
}

export async function purchase(
  client: BillPayClient, hooks: Hooks, billerCode: string, memberNumber: string,
  products: PaymentProduct[], totalAmount?: number,
  authReturnsPrice = false,        // product AuthAmountMandated is true or false
) {
  const reference = randomUUID();
  const authReq: PaymentRequest = {
    Action: 'Auth', BillerCode: billerCode, MemberNumber: memberNumber, Reference: reference,
    Products: products, ...(totalAmount !== undefined ? { TotalAmount: totalAmount } : {}),
  };
  await hooks.save(reference, { state: 'created', authReq });           // persist first

  const auth = await client.process(authReq);                            // nothing charged yet
  if (auth.Status !== 'Authorized') {
    await hooks.save(reference, { state: 'auth_failed', auth });
    throw new BillPayValidationError(auth.Narration ?? 'Authorisation failed');
  }

  const amountDue = (authReturnsPrice ? auth.TotalAmount : totalAmount) ?? 0;
  const ok = await hooks.confirmWithCustomer({
    name: auth.AuthData?.MemberName, address: auth.AuthData?.MemberAddress,     // UAT test 2
    details: auth.AuthData?.AccountDetails, balances: auth.AuthData?.AccountBalances,
    balance: auth.AuthData?.AccountBalance, amount: amountDue,
  });
  if (!ok) { await hooks.save(reference, { state: 'abandoned' }); return undefined; }

  // PAY mirrors AUTH; blank TotalAmount/Price when AUTH returned the price.
  // (Part-payment submission is not specified by Paynow -- confirm with support.)
  // Forex: where RequiresForexPayment wasn't set (catalogue RequiresForex = null), AUTH decides.
  const payProducts: PaymentProduct[] = products.map((p, i) => {
    const fromAuth = auth.Products?.[i]?.RequiresForexPayment;
    return p.RequiresForexPayment === undefined && fromAuth != null
      ? { ...p, RequiresForexPayment: Boolean(fromAuth) } : { ...p };
  });
  const payReq: PaymentRequest = { ...authReq, Action: 'Pay', Products: payProducts };
  if (authReturnsPrice) {
    delete payReq.TotalAmount;
    payReq.Products = payProducts.map(({ Price, ...rest }) => rest);
  }

  await hooks.chargeCustomer(reference, amountDue);
  await hooks.save(reference, { state: 'paying' });

  let result: PaymentResponse | undefined;
  try {
    result = await client.process(payReq);
  } catch (err) {
    if (err instanceof BillPayValidationError) {                    // 400: rejected
      await hooks.refundCustomer(reference, amountDue, err.message);
      await hooks.save(reference, { state: 'refunded' });
      throw err;
    }
    if (!(err instanceof BillPayTransportError)) throw err;
    result = await pollUntilFinal(client, reference);               // unknown: never re-send PAY
  }

  if (!FINAL_STATUSES.has(result?.Status ?? '')) {                  // BeingProcessed/BeingPaid/Pending/Flagged
    result = await pollUntilFinal(client, reference);
  }

  if (result?.Status === 'Paid') {
    for (const html of result.PaymentData?.ReceiptHtml ?? []) await hooks.sendReceiptHtml(reference, html);
    for (const sms of result.PaymentData?.ReceiptSmses ?? []) await hooks.sendSms(reference, sms);
    await hooks.save(reference, { state: 'fulfilled', result });
  } else if (result?.Status === 'Failed') {
    await hooks.refundCustomer(reference, amountDue, result.Narration ?? 'Payment failed');
    await hooks.save(reference, { state: 'refunded', result });
  } else {
    await hooks.escalate(reference, result?.Status);
    await hooks.save(reference, { state: 'needs_attention', result });
  }
  return result;
}
