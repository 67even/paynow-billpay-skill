"""BillPay webhook receivers (Flask). pip install flask requests

Vendor:  POST /webhooks/billpay/config    body ["CODE1","CODE2"]
Biller:  POST /webhooks/billpay/payments  body {"Payments":[...],"Hash":"..."} + X-Signature
"""
import base64
import hashlib
import hmac
import json
import os
import threading

from flask import Flask, request

from billpay_client import BillPayClient

app = Flask(__name__)
WEBHOOK_TOKEN = os.environ.get("BILLPAY_WEBHOOK_TOKEN")   # set only once BillPay has configured it
SECRET_KEY = os.environ.get("BILLPAY_SECRET_KEY", "")


def upsert_billers(billers):          # replace with your DB code
    ...


def store_payment(payment):           # replace with your DB code (key on PaymentId)
    ...


@app.post("/webhooks/billpay/config")
def config_webhook():
    if WEBHOOK_TOKEN:
        # Header format is ambiguous in the official docs -- confirm with Paynow
        provided = request.headers.get("Authorization", "")
        if not hmac.compare_digest(provided, f"Bearer {WEBHOOK_TOKEN}"):
            return "", 401

    body = request.get_json(silent=True)
    codes = [c for c in body if isinstance(c, str) and c] if isinstance(body, list) else []

    def resync():
        try:
            upsert_billers(BillPayClient.from_env().list_billers(codes))   # ALWAYS filtered
        except Exception:                                                   # noqa: BLE001
            app.logger.exception("BillPay config resync failed")

    if codes:                          # never fall back to an unfiltered call
        threading.Thread(target=resync, daemon=True).start()   # or enqueue a Celery/RQ task
    return "", 200                     # respond fast: BillPay retries every 30 s, 3 times


def legacy_hash_ok(data: dict) -> bool:
    plain = "".join(
        f"{p['PaymentId']}{p['BillPayReference']}{p['BankReference']}{p['PaidDate']}"
        f"{p['MemberNumber']}{p['MemberName']}{p['ProductCode']}"
        f"{float(p['ProductPrice']):.2f}{p.get('ProductDepartment') or ''}"
        for p in data.get("Payments", []))
    expected = hashlib.sha256((plain + SECRET_KEY).encode("utf-8")).hexdigest()
    return hmac.compare_digest(expected, str(data.get("Hash", "")).lower())


@app.post("/webhooks/billpay/payments")
def payment_webhook():
    raw = request.get_data()          # RAW bytes -- sign before parsing
    signature = request.headers.get("X-Signature")
    if not SECRET_KEY:
        return "", 401
    if signature:
        expected = base64.b64encode(hmac.new(SECRET_KEY.encode(), raw, hashlib.sha256).digest()).decode()
        if not hmac.compare_digest(expected, signature):
            return "", 401
        data = json.loads(raw)
    else:
        data = json.loads(raw or b"{}")
        if not legacy_hash_ok(data):
            return "", 401

    for payment in data.get("Payments", []):
        store_payment(payment)        # idempotent upsert keyed on PaymentId (recommendation)
    return "", 200
