"""Paynow BillPay client + vendor purchase flow (Python 3.9+, requests).

    pip install requests

Rules encoded here (see SKILL.md section 4):
  * HTTP Basic auth, 60 s timeout; HTTP 200 is not success -- read "Status".
  * AUTH before charging the customer; PAY mirrors AUTH, except Price/TotalAmount
    are left blank when AUTH returned the price (AuthAmountMandated true/false).
  * Timeout / connection error / 5xx on PAY = outcome unknown: never re-send PAY;
    STATUS after 120 s, then every 180 s (600 s while Flagged), cap, escalate.
  * Every non-final status (BeingProcessed, BeingPaid, Pending, Flagged, unknown)
    is pending.
  * Each ReceiptHtml / ReceiptSmses entry is delivered individually.

In production run `poll_until_final` in a background worker (Celery, RQ, ...),
not inside a web request.
"""
from __future__ import annotations

import os
import time
import uuid
from dataclasses import dataclass, field
from typing import Any, Callable, Dict, List, Optional

import requests

FINAL_STATUSES = {"Paid", "Failed", "Reversed"}
PENDING_STATUSES = {"BeingProcessed", "BeingPaid", "Pending"}  # BeingPaid: Test Biller PP; Pending: ZETDC


class BillPayValidationError(Exception):
    """HTTP 400. `model_state` maps field paths to messages. Do not retry as-is."""

    def __init__(self, message: str, model_state: Optional[dict] = None):
        super().__init__(f"{message} {model_state or ''}".strip())
        self.model_state = model_state or {}


class BillPayTransportError(Exception):
    """Timeout, connection error or HTTP 5xx. For PAY the outcome is UNKNOWN."""


class BillPayClient:
    def __init__(self, username: str, password: str,
                 base_url: str = "https://billpay.paynow.co.zw", timeout: int = 60):
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout
        self.session = requests.Session()
        self.session.auth = (username, password)          # HTTP Basic on every request
        self.session.headers["Accept"] = "application/json"

    @classmethod
    def from_env(cls) -> "BillPayClient":
        return cls(os.environ["BILLPAY_USERNAME"], os.environ["BILLPAY_PASSWORD"],
                   os.environ.get("BILLPAY_BASE_URL", "https://billpay.paynow.co.zw"),
                   int(os.environ.get("BILLPAY_TIMEOUT", "60")))

    # ---------------- low level ----------------
    def _request(self, method: str, path: str, **kwargs) -> Any:
        try:
            resp = self.session.request(method, self.base_url + path, timeout=self.timeout, **kwargs)
        except requests.RequestException as exc:           # timeout, DNS, reset...
            raise BillPayTransportError(str(exc)) from exc
        if resp.status_code == 400:
            try:
                body = resp.json()
            except ValueError:
                body = {}
            raise BillPayValidationError(body.get("Message", "The request is invalid."), body.get("ModelState"))
        if resp.status_code >= 300:
            raise BillPayTransportError(f"BillPay HTTP {resp.status_code}")
        return resp.json() if resp.content.strip() else None   # TargetStats may be empty

    # ---------------- vendor API ----------------
    def list_billers(self, biller_codes: Optional[List[str]] = None) -> list:
        """None = full catalogue (seed only). After a webhook ALWAYS pass the codes."""
        if biller_codes is not None and not biller_codes:
            raise ValueError("biller_codes must be None or non-empty (avoid an unfiltered call)")
        params = {"billerCodes": ",".join(biller_codes)} if biller_codes else None
        return self._request("GET", "/api/payment/ListBillers", params=params) or []

    def member(self, biller_code: str, member_number: str) -> dict:
        """ResultCode: 0 = FailedPermanent, 1 = Success, 2 = Offline (retry later)."""
        return self._request("GET", "/api/payment/member",
                             params={"billerCode": biller_code, "memberNumber": member_number}) or {}

    def wallets(self) -> list:
        return self._request("GET", "/api/wallets") or []

    def process(self, payload: dict) -> dict:
        return self._request("POST", "/api/payment/process", json=payload) or {}

    def status(self, reference: str) -> dict:
        return self.process({"Action": "Status", "Reference": reference})

    def reverse(self, original_reference: str, reversal_reference: str) -> dict:
        """ErrorCode 0 = success. Very few billers support reversals."""
        return self._request("POST", "/api/payment/reverse", json={
            "OriginalReference": original_reference, "Reference": reversal_reference}) or {}

    def list_payments(self, **filters) -> dict:
        """From/To as 'dd-MMM-yyyy HH:mm:ss', BillerCode, Status, VendorReference, Page, PerPage<=200."""
        return self._request("GET", "/api/payment/list", params=filters) or {}

    def target_stats(self, biller_code: str, currency: Optional[str] = None) -> Optional[dict]:
        params = {"billercode": biller_code, **({"currency": currency} if currency else {})}
        return self._request("GET", "/api/payment/TargetStats", params=params)

    def feed(self, biller_code: str, currency: Optional[str] = None, before_ms: Optional[int] = None) -> list:
        params = {"billercode": biller_code}
        if currency:
            params["currency"] = currency
        if before_ms:
            params["before"] = before_ms           # Unix time in milliseconds
        return self._request("GET", "/api/payment/feed", params=params) or []

    # ---------------- biller API ----------------
    def save_member(self, member: dict, update: bool = False) -> None:
        """update blanks omitted optional fields -- send the full record."""
        body = dict(member)
        if isinstance(body.get("AccountDetails"), dict):
            import json
            body["AccountDetails"] = json.dumps(body["AccountDetails"])   # JSON string
        self._request("POST", "/api/member/update" if update else "/api/member/create", json=body)

    def delete_member(self, member_number: str) -> None:
        self._request("POST", "/api/member/delete", json={"MemberNumber": member_number})

    def undelete_member(self, member_number: str) -> None:
        self._request("POST", "/api/member/undelete", json={"MemberNumber": member_number})

    def upload_members(self, csv_path: str, delete: bool = False) -> dict:
        """Multipart field name 'file' is an assumption (not documented)."""
        path = "/api/member/uploadmembersdelete" if delete else "/api/member/uploadmembers"
        with open(csv_path, "rb") as fh:
            return self._request("POST", path, files={"file": (os.path.basename(csv_path), fh, "text/csv")}) or {}

    def download_payments(self, date_from: str, date_to: str, member_number: Optional[str] = None) -> str:
        params = {"From": date_from, "To": date_to, **({"MemberNumber": member_number} if member_number else {})}
        try:
            resp = self.session.get(self.base_url + "/api/member/downloadpayments",
                                    params=params, timeout=self.timeout)
        except requests.RequestException as exc:
            raise BillPayTransportError(str(exc)) from exc
        if resp.status_code != 200:
            raise BillPayTransportError(f"BillPay HTTP {resp.status_code}")
        return resp.text


# ---------------------------------------------------------------------------
# Vendor purchase flow
# ---------------------------------------------------------------------------

@dataclass
class Hooks:
    """Plug in your own application services."""
    charge_customer: Callable[[str, float], None]            # (reference, amount) -> raise on failure
    refund_customer: Callable[[str, float, str], None]       # (reference, amount, reason)
    send_receipt_html: Callable[[str, str], None]            # (reference, html) -- one entry per call
    send_sms: Callable[[str, str], None]                     # (reference, text) -- one SMS per call
    confirm_with_customer: Callable[[dict], bool]            # shows member + amount, returns yes/no
    escalate: Callable[[str, Optional[str]], None]           # (reference, last status)
    save: Callable[[str, dict], None] = field(default=lambda ref, data: None)  # persist state


def poll_until_final(client: BillPayClient, reference: str, max_attempts: int = 10,
                     sleep: Optional[Callable[[float], None]] = None) -> Optional[dict]:
    """STATUS 120 s after the pending/unknown response, then every 180 s (600 s if Flagged)."""
    sleep = sleep or time.sleep
    sleep(120)
    result: Optional[dict] = None
    for _ in range(max_attempts):
        try:
            result = client.status(reference)
        except (BillPayTransportError, BillPayValidationError):
            sleep(180)                      # transient: keep the cadence
            continue
        status = result.get("Status")
        if status in FINAL_STATUSES:
            return result
        sleep(600 if status == "Flagged" else 180)
    return result                           # still non-final -> caller escalates


def deliver(result: dict, reference: str, hooks: Hooks) -> None:
    data = result.get("PaymentData") or {}
    for html in data.get("ReceiptHtml") or []:          # live billers may omit these
        hooks.send_receipt_html(reference, html)
    for sms in data.get("ReceiptSmses") or []:
        hooks.send_sms(reference, sms)
    # DisplayData and Products[].Vouchers[] (VoucherCode, SerialNumber, ExpiryDate)
    # belong on your confirmation page / email.


def purchase(client: BillPayClient, hooks: Hooks, biller_code: str, member_number: str,
             products: List[Dict[str, Any]], total_amount: Optional[float] = None,
             auth_returns_price: bool = False, payer_details: Optional[dict] = None) -> Optional[dict]:
    """auth_returns_price: True when the product's AuthAmountMandated is true or false."""
    reference = str(uuid.uuid4())
    auth_req: Dict[str, Any] = {"Action": "Auth", "BillerCode": biller_code,
                                "MemberNumber": member_number, "Reference": reference,
                                "Products": products}
    if total_amount is not None:
        auth_req["TotalAmount"] = total_amount
    hooks.save(reference, {"state": "created", "auth_request": auth_req})   # persist first

    # 1. AUTH -- nothing charged yet, so any failure here is safe to report
    auth = client.process(auth_req)
    if auth.get("Status") != "Authorized":
        hooks.save(reference, {"state": "auth_failed", "auth": auth})
        raise BillPayValidationError(auth.get("Narration") or "Authorisation failed")

    # 2. Confirm member details + amount (UAT test 2)
    auth_data = auth.get("AuthData") or {}
    amount_due = auth.get("TotalAmount") if auth_returns_price else total_amount
    if not hooks.confirm_with_customer({
        "name": auth_data.get("MemberName"), "address": auth_data.get("MemberAddress"),
        "details": auth_data.get("AccountDetails"), "balances": auth_data.get("AccountBalances"),
        "balance": auth_data.get("AccountBalance"), "amount": amount_due,
    }):
        hooks.save(reference, {"state": "abandoned"})
        return None

    # 3. PAY mirrors AUTH; blank Price/TotalAmount when AUTH returned the price.
    #    (How to submit a *part* payment is not specified by Paynow -- ask support.)
    pay_req = dict(auth_req, Action="Pay")
    # Forex: where you didn't set RequiresForexPayment (catalogue RequiresForex = null),
    # AUTH decides -- acknowledge AUTH's answer in PAY.
    pay_products = []
    for i, p in enumerate(products):
        p = dict(p)
        from_auth = ((auth.get("Products") or [{}] * len(products))[i] or {}).get("RequiresForexPayment")
        if "RequiresForexPayment" not in p and from_auth is not None:
            p["RequiresForexPayment"] = bool(from_auth)
        pay_products.append(p)
    pay_req["Products"] = pay_products
    if auth_returns_price:
        pay_req.pop("TotalAmount", None)
        pay_req["Products"] = [{k: v for k, v in p.items() if k != "Price"} for p in pay_products]
    if payer_details:
        pay_req["PayerDetails"] = payer_details

    hooks.charge_customer(reference, float(amount_due or 0))
    hooks.save(reference, {"state": "paying"})

    try:
        result: Optional[dict] = client.process(pay_req)
    except BillPayValidationError as exc:            # 400: rejected, nothing provisioned
        hooks.refund_customer(reference, float(amount_due or 0), str(exc))
        hooks.save(reference, {"state": "refunded"})
        raise
    except BillPayTransportError:                    # outcome unknown: never re-send PAY
        result = poll_until_final(client, reference)

    status = (result or {}).get("Status")
    if status not in FINAL_STATUSES:                 # BeingProcessed / BeingPaid / Pending / Flagged / unknown
        result = poll_until_final(client, reference)
        status = (result or {}).get("Status")

    if status == "Paid":
        deliver(result, reference, hooks)
        hooks.save(reference, {"state": "fulfilled", "result": result})
    elif status == "Failed":
        hooks.refund_customer(reference, float(amount_due or 0), (result or {}).get("Narration") or "Failed")
        hooks.save(reference, {"state": "refunded", "result": result})
    else:
        hooks.escalate(reference, status)
        hooks.save(reference, {"state": "needs_attention", "result": result})
    return result
