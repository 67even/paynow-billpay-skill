#!/usr/bin/env python3
"""Verify a BillPay biller payment webhook from a saved raw body.

  verify_webhook.py body.json --secret KEY --signature "<X-Signature header>"
  verify_webhook.py body.json --secret KEY            # checks the legacy Hash field
  verify_webhook.py --self-test                       # runs the official worked example

X-Signature = base64(HMAC-SHA256(raw body bytes, secret)). Save the body exactly
as received (no re-formatting) or the HMAC will not match.
Legacy Hash = sha256(PaymentId+BillPayReference+BankReference+PaidDate+MemberNumber
              +MemberName+ProductCode+ProductPrice(2dp)+ProductDepartment ... + secret), lowercase hex.
"""
import argparse
import base64
import hashlib
import hmac
import json
import sys

EXAMPLE = {"Payments": [
    {"PaymentId": 172, "BillPayReference": "FAKE-181211122304615", "BankReference": "9796",
     "PaidDate": "11-Dec-2018 12:24:51", "MemberNumber": "T00001", "MemberName": "John Doe",
     "ProductCode": "LN", "ProductPrice": 3.21, "ProductDepartment": "Sales"},
    {"PaymentId": 245, "BillPayReference": "FAKE-18121112212345", "BankReference": "",
     "PaidDate": "11-Dec-2018 13:14:11", "MemberNumber": "K00123", "MemberName": "Abby Fijngold",
     "ProductCode": "MP", "ProductPrice": 30.00, "ProductDepartment": "Sales"}],
    "Hash": "660ad6a83bdd9993a2ef44e3b02098a6ce62763a145eccf1f669951bdd53ce40"}
EXAMPLE_SECRET = "415b654f-3544-4281-a91e-051e710bfb8d"


def hmac_signature(raw: bytes, secret: str) -> str:
    return base64.b64encode(hmac.new(secret.encode("utf-8"), raw, hashlib.sha256).digest()).decode()


def legacy_plaintext(data: dict) -> str:
    out = []
    for p in data.get("Payments", []):
        out.append(f"{p['PaymentId']}{p['BillPayReference']}{p.get('BankReference') or ''}{p['PaidDate']}"
                   f"{p['MemberNumber']}{p['MemberName']}{p['ProductCode']}"
                   f"{float(p['ProductPrice']):.2f}{p.get('ProductDepartment') or ''}")
    return "".join(out)


def legacy_hash(data: dict, secret: str) -> str:
    return hashlib.sha256((legacy_plaintext(data) + secret).encode("utf-8")).hexdigest()


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("body", nargs="?")
    ap.add_argument("--secret")
    ap.add_argument("--signature")
    ap.add_argument("--self-test", action="store_true")
    a = ap.parse_args()

    if a.self_test:
        ok = legacy_hash(EXAMPLE, EXAMPLE_SECRET) == EXAMPLE["Hash"]
        print("Legacy hash worked example:", "PASS" if ok else "FAIL")
        sys.exit(0 if ok else 1)
    if not a.body or not a.secret:
        ap.error("body and --secret are required (or use --self-test)")

    raw = open(a.body, "rb").read()
    data = json.loads(raw)
    if a.signature:
        computed = hmac_signature(raw, a.secret)
        ok = hmac.compare_digest(computed, a.signature.strip())
        print(f"X-Signature: {'VALID' if ok else 'INVALID'}\n  computed: {computed}\n  received: {a.signature}")
    else:
        computed = legacy_hash(data, a.secret)
        ok = hmac.compare_digest(computed, str(data.get("Hash", "")).lower())
        print(f"Legacy Hash: {'VALID' if ok else 'INVALID'}\n  plaintext: {legacy_plaintext(data)}"
              f"\n  computed: {computed}\n  received: {data.get('Hash')}")
    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main()
