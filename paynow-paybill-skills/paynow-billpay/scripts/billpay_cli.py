#!/usr/bin/env python3
"""BillPay smoke-test CLI (standard library only).

Env: BILLPAY_USERNAME, BILLPAY_PASSWORD, optional BILLPAY_BASE_URL (default
https://billpay.paynow.co.zw), BILLPAY_TIMEOUT (default 60).

Examples
  billpay_cli.py wallets
  billpay_cli.py billers --codes ZETDC,EVD          # filtered (use --all only to seed)
  billpay_cli.py member ZETDC 37132567431
  billpay_cli.py auth TEST PP12345 --product AI --price 10 --total 10 --ref <uuid>
  billpay_cli.py pay  TEST PP12345 --product AI --price 10 --total 10 --ref <same uuid>
  billpay_cli.py status <reference>
  billpay_cli.py payments --from "01-Sep-2026 00:00:00" --to "20-Sep-2026 23:59:59"

`pay` sends a real payment that debits your wallet. It refuses to run without
--ref, so you cannot accidentally create a second payment: reuse the AUTH
reference. After a timeout, use `status` -- never re-run `pay`.
"""
import argparse
import base64
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid


def call(method, path, params=None, body=None):
    base = os.environ.get("BILLPAY_BASE_URL", "https://billpay.paynow.co.zw").rstrip("/")
    user, pwd = os.environ.get("BILLPAY_USERNAME"), os.environ.get("BILLPAY_PASSWORD")
    if not user or not pwd:
        sys.exit("Set BILLPAY_USERNAME and BILLPAY_PASSWORD")
    url = base + path + ("?" + urllib.parse.urlencode(params) if params else "")
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Authorization", "Basic " + base64.b64encode(f"{user}:{pwd}".encode()).decode())
    req.add_header("Accept", "application/json")
    if data is not None:
        req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=int(os.environ.get("BILLPAY_TIMEOUT", "60"))) as r:
            text = r.read().decode()
            return r.status, (json.loads(text) if text.strip() else None)
    except urllib.error.HTTPError as e:
        text = e.read().decode()
        try:
            return e.code, json.loads(text)
        except ValueError:
            return e.code, text
    except (urllib.error.URLError, TimeoutError) as e:
        print(f"TRANSPORT ERROR: {e}. If this was PAY the outcome is unknown -- "
              f"wait 120 s and run `status`, do not re-run `pay`.", file=sys.stderr)
        sys.exit(2)


def payment_body(a, action):
    product = {"Code": a.product, "Quantity": a.quantity}
    if a.price is not None:
        product["Price"] = a.price
    if a.forex:
        product["RequiresForexPayment"] = True
    if a.metadata:
        product["Metadata"] = json.loads(a.metadata)
    body = {"Action": action, "BillerCode": a.biller, "MemberNumber": a.member,
            "Reference": a.ref or str(uuid.uuid4()), "Products": [product]}
    if a.total is not None:
        body["TotalAmount"] = a.total
    return body


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest="cmd", required=True)
    sub.add_parser("wallets")
    b = sub.add_parser("billers")
    g = b.add_mutually_exclusive_group(required=True)
    g.add_argument("--codes", help="comma-separated biller codes (filtered call)")
    g.add_argument("--all", action="store_true", help="full catalogue -- seed only, large payload")
    m = sub.add_parser("member")
    m.add_argument("biller"), m.add_argument("member")
    for name in ("auth", "pay"):
        s = sub.add_parser(name)
        s.add_argument("biller"), s.add_argument("member")
        s.add_argument("--product", required=True)
        s.add_argument("--quantity", type=int, default=1)
        s.add_argument("--price", type=float, help="omit when AUTH returns the price")
        s.add_argument("--total", type=float, help="omit when AUTH returns the price")
        s.add_argument("--forex", action="store_true", help="set RequiresForexPayment=true")
        s.add_argument("--metadata", help='JSON, e.g. \'{"Service Login":"x@y.com"}\'')
        s.add_argument("--ref", help="reference (required for pay: reuse the AUTH reference)")
    st = sub.add_parser("status")
    st.add_argument("reference")
    pl = sub.add_parser("payments")
    pl.add_argument("--from", dest="date_from"), pl.add_argument("--to", dest="date_to")
    pl.add_argument("--biller"), pl.add_argument("--page", type=int, default=1)
    a = p.parse_args()

    if a.cmd == "wallets":
        res = call("GET", "/api/wallets")
    elif a.cmd == "billers":
        res = call("GET", "/api/payment/ListBillers", None if a.all else {"billerCodes": a.codes})
    elif a.cmd == "member":
        res = call("GET", "/api/payment/member", {"billerCode": a.biller, "memberNumber": a.member})
    elif a.cmd == "auth":
        body = payment_body(a, "Auth")
        print(f"Reference: {body['Reference']}  (reuse it for pay/status)", file=sys.stderr)
        res = call("POST", "/api/payment/process", body=body)
    elif a.cmd == "pay":
        if not a.ref:
            sys.exit("pay requires --ref <the AUTH reference>")
        res = call("POST", "/api/payment/process", body=payment_body(a, "Pay"))
    elif a.cmd == "status":
        res = call("POST", "/api/payment/process", body={"Action": "Status", "Reference": a.reference})
    else:
        params = {k: v for k, v in {"From": a.date_from, "To": a.date_to,
                                     "BillerCode": a.biller, "Page": a.page}.items() if v}
        res = call("GET", "/api/payment/list", params)

    code, data = res
    print(f"HTTP {code}", file=sys.stderr)
    if code == 401:
        print("Unauthorized: check BILLPAY_USERNAME/BILLPAY_PASSWORD (vendor needs the 'API' role).", file=sys.stderr)
    print(json.dumps(data, indent=2) if not isinstance(data, str) else data)
    if isinstance(data, dict) and "Status" in data:
        s = data["Status"]
        hint = {"Paid": "final", "Failed": "final", "Reversed": "final",
                "Authorized": "ready for PAY", "Flagged": "poll every 600 s"}.get(s, "pending: poll every 180 s (first at 120 s)")
        print(f"Status: {s} -> {hint}", file=sys.stderr)


if __name__ == "__main__":
    main()
