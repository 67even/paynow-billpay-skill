/**
 * Paynow BillPay client (Node 18+, built-in fetch). No dependencies.
 *
 * - HTTP Basic auth, 60 s timeout. HTTP 200 is not success: read `Status`.
 * - 400 -> BillPayValidationError. Timeout / network / 5xx -> BillPayTransportError
 *   (for PAY: outcome unknown -> never re-send PAY, poll STATUS).
 */

export const FINAL_STATUSES = new Set(['Paid', 'Failed', 'Reversed']);
// BeingPaid: Test Biller PP prefix (used in UAT). Pending: ZETDC under load.
export const PENDING_STATUSES = new Set(['BeingProcessed', 'BeingPaid', 'Pending']);

export type Action = 'Auth' | 'Pay' | 'Retry' | 'Status';

export interface PaymentProduct {
  Code: string;
  Quantity?: number;
  Department?: string;
  Price?: number;
  RequiresForexPayment?: boolean;
  // "Array of key/value pairs" per the field table, but Pink Lotto sends an array
  // of objects and Liquid Home a plain object -- follow each biller's example.
  Metadata?: Record<string, string> | Array<Record<string, string>>;
}

export interface PaymentRequest {
  Action: Action;
  BillerCode?: string;
  MemberNumber?: string;
  Reference: string;
  TotalAmount?: number;
  Products?: PaymentProduct[];
  PayerDetails?: Record<string, string>;
}

export interface PaymentResponse extends Record<string, any> {
  Status?: string;
  Narration?: string;
  TechnicalNarration?: string;
  TotalAmount?: number;
  MemberName?: string;
  BillPayReference?: string;
  AuthData?: {
    MemberName?: string;
    MemberAddress?: string;
    AccountDetails?: Record<string, string>;
    AccountBalances?: Record<string, string>;
    AccountBalance?: number | null;
  };
  PaymentData?: {
    ReceiptHtml?: string[] | null;
    ReceiptSmses?: string[] | null;
    DisplayData?: Record<string, string> | null;
  };
  Currency?: string;
  WalletBalanceAfterDebit?: number | null;
}

export class BillPayValidationError extends Error {
  constructor(message: string, public modelState: Record<string, string[]> = {}) {
    super(`${message} ${JSON.stringify(modelState)}`);
  }
}
export class BillPayTransportError extends Error {}

export interface BillPayConfig {
  username: string;
  password: string;
  baseUrl?: string;
  timeoutMs?: number;
}

export class BillPayClient {
  private readonly auth: string;
  private readonly baseUrl: string;
  private readonly timeoutMs: number;

  constructor(cfg: BillPayConfig) {
    this.auth = 'Basic ' + Buffer.from(`${cfg.username}:${cfg.password}`).toString('base64');
    this.baseUrl = (cfg.baseUrl ?? 'https://billpay.paynow.co.zw').replace(/\/$/, '');
    this.timeoutMs = cfg.timeoutMs ?? 60_000;
  }

  static fromEnv(): BillPayClient {
    return new BillPayClient({
      username: process.env.BILLPAY_USERNAME!,
      password: process.env.BILLPAY_PASSWORD!,
      baseUrl: process.env.BILLPAY_BASE_URL,
      timeoutMs: Number(process.env.BILLPAY_TIMEOUT ?? 60) * 1000,
    });
  }

  private async request<T>(method: 'GET' | 'POST', path: string,
                           opts: { query?: Record<string, string | number | undefined>; json?: unknown; body?: any } = {}): Promise<T> {
    const url = new URL(this.baseUrl + path);
    for (const [k, v] of Object.entries(opts.query ?? {})) {
      if (v !== undefined && v !== '') url.searchParams.set(k, String(v));
    }
    const headers: Record<string, string> = { Authorization: this.auth, Accept: 'application/json' };
    let body = opts.body;
    if (opts.json !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(opts.json);
    }

    let res: Response;
    try {
      res = await fetch(url, { method, headers, body, signal: AbortSignal.timeout(this.timeoutMs) });
    } catch (err) {
      throw new BillPayTransportError(`BillPay request failed: ${(err as Error).message}`);
    }
    if (res.status === 400) {
      const err = await res.json().catch(() => ({}));
      throw new BillPayValidationError(err.Message ?? 'The request is invalid.', err.ModelState ?? {});
    }
    if (!res.ok) throw new BillPayTransportError(`BillPay HTTP ${res.status}`);
    const text = await res.text();
    return (text.trim() ? JSON.parse(text) : null) as T;   // TargetStats may be empty
  }

  // ---------------- vendor API ----------------
  /** undefined = full catalogue (seed only); after a webhook ALWAYS pass codes. */
  listBillers(billerCodes?: string[]) {
    if (billerCodes && billerCodes.length === 0) {
      throw new Error('billerCodes must be undefined or non-empty (avoid an unfiltered call)');
    }
    return this.request<any[]>('GET', '/api/payment/ListBillers',
      { query: { billerCodes: billerCodes?.join(',') } });
  }

  /** ResultCode: 0 = FailedPermanent, 1 = Success, 2 = Offline. */
  member(billerCode: string, memberNumber: string) {
    return this.request<any>('GET', '/api/payment/member', { query: { billerCode, memberNumber } });
  }

  wallets() {
    return this.request<any[]>('GET', '/api/wallets');
  }

  process(payload: PaymentRequest) {
    return this.request<PaymentResponse>('POST', '/api/payment/process', { json: payload });
  }

  status(reference: string) {
    return this.process({ Action: 'Status', Reference: reference });
  }

  reverse(originalReference: string, reference: string) {
    return this.request<any>('POST', '/api/payment/reverse',
      { json: { OriginalReference: originalReference, Reference: reference } });
  }

  /** From/To "dd-MMM-yyyy HH:mm:ss", BillerCode, Status, VendorReference, Page, PerPage (<=200). */
  listPayments(filters: Record<string, string | number> = {}) {
    return this.request<any>('GET', '/api/payment/list', { query: filters });
  }

  targetStats(billercode: string, currency?: string) {
    return this.request<any | null>('GET', '/api/payment/TargetStats', { query: { billercode, currency } });
  }

  feed(billercode: string, currency?: string, beforeUnixMs?: number) {
    return this.request<any[]>('GET', '/api/payment/feed', { query: { billercode, currency, before: beforeUnixMs } });
  }

  // ---------------- biller API ----------------
  /** update blanks omitted optional fields -- send the full record. */
  saveMember(member: Record<string, any>, update = false) {
    const body = { ...member };
    if (body.AccountDetails && typeof body.AccountDetails === 'object') {
      body.AccountDetails = JSON.stringify(body.AccountDetails);   // JSON string
    }
    return this.request<unknown>('POST', update ? '/api/member/update' : '/api/member/create', { json: body });
  }

  deleteMember(MemberNumber: string) {
    return this.request<unknown>('POST', '/api/member/delete', { json: { MemberNumber } });
  }

  undeleteMember(MemberNumber: string) {
    return this.request<unknown>('POST', '/api/member/undelete', { json: { MemberNumber } });
  }

  /** Multipart field name "file" is an assumption (not documented). */
  uploadMembers(csv: Blob, fileName: string, del = false) {
    const form = new FormData();
    form.append('file', csv, fileName);
    return this.request<any>('POST', del ? '/api/member/uploadmembersdelete' : '/api/member/uploadmembers', { body: form });
  }
}
