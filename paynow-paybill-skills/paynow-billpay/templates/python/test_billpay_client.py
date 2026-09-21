"""Offline tests for billpay_client.purchase() - no network, no credentials.

    python3 -m unittest test_billpay_client.py
"""
import unittest

import billpay_client as bc


class FakeClient:
    """Stands in for BillPayClient.process/status."""

    def __init__(self, auth=None, pay=None, pay_exc=None, statuses=()):
        self.calls = []
        self.auth = auth or {"Status": "Authorized", "AuthData": {"MemberName": "Tariro"}}
        self.pay = pay
        self.pay_exc = pay_exc
        self.statuses = list(statuses)

    def process(self, payload):
        self.calls.append(payload)
        if payload["Action"] == "Auth":
            return self.auth
        if payload["Action"] == "Pay":
            if self.pay_exc:
                raise self.pay_exc
            return self.pay
        return self.statuses.pop(0)

    def status(self, reference):
        return self.process({"Action": "Status", "Reference": reference})

    def pays(self):
        return [c for c in self.calls if c["Action"] == "Pay"]


def paid(smses=("t1", "t2")):
    return {"Status": "Paid", "PaymentData": {"ReceiptSmses": list(smses), "ReceiptHtml": ["<p>r</p>"]}}


class PurchaseTests(unittest.TestCase):
    def setUp(self):
        self.log = []
        self.hooks = bc.Hooks(
            charge_customer=lambda ref, amt: self.log.append(("charge", amt)),
            refund_customer=lambda ref, amt, why: self.log.append(("refund", amt)),
            send_receipt_html=lambda ref, html: self.log.append(("html", html)),
            send_sms=lambda ref, text: self.log.append(("sms", text)),
            confirm_with_customer=lambda info: True,
            escalate=lambda ref, status: self.log.append(("escalate", status)),
        )
        self._sleep = bc.time.sleep
        bc.time.sleep = lambda s: None        # no real waiting in tests

    def tearDown(self):
        bc.time.sleep = self._sleep

    def test_happy_path_delivers_each_sms_separately(self):
        c = FakeClient(pay=paid())
        bc.purchase(c, self.hooks, "TEST", "12345", [{"Code": "RV", "Price": 5}], 5)
        self.assertEqual(self.log, [("charge", 5.0), ("html", "<p>r</p>"), ("sms", "t1"), ("sms", "t2")])

    def test_timeout_never_resends_pay_and_treats_beingpaid_as_pending(self):
        c = FakeClient(pay_exc=bc.BillPayTransportError("timed out"),
                       statuses=[{"Status": "BeingPaid"}, {"Status": "Flagged"}, paid()])
        result = bc.purchase(c, self.hooks, "TEST", "PT1", [{"Code": "RV", "Price": 5}], 5)
        self.assertEqual(result["Status"], "Paid")
        self.assertEqual(len(c.pays()), 1)

    def test_auth_returned_price_is_charged_and_blanked_in_pay(self):
        c = FakeClient(auth={"Status": "Authorized", "TotalAmount": 42, "AuthData": {"MemberName": "X"},
                             "Products": [{"RequiresForexPayment": True}]}, pay=paid())
        bc.purchase(c, self.hooks, "TEST", "1", [{"Code": "AM", "Price": 1}], None, auth_returns_price=True)
        pay = c.pays()[0]
        self.assertNotIn("TotalAmount", pay)
        self.assertNotIn("Price", pay["Products"][0])
        self.assertIs(pay["Products"][0]["RequiresForexPayment"], True)   # copied from AUTH
        self.assertEqual(self.log[0], ("charge", 42.0))

    def test_failed_payment_is_refunded(self):
        c = FakeClient(pay={"Status": "Failed", "Narration": "Nope"})
        bc.purchase(c, self.hooks, "TEST", "PF1", [{"Code": "RV", "Price": 5}], 5)
        self.assertIn(("refund", 5.0), self.log)

    def test_auth_failure_charges_nothing(self):
        c = FakeClient(auth={"Status": "Failed", "Narration": "Invalid member"})
        with self.assertRaises(bc.BillPayValidationError):
            bc.purchase(c, self.hooks, "TEST", "AF1", [{"Code": "RV", "Price": 5}], 5)
        self.assertEqual(self.log, [])

    def test_empty_biller_codes_are_rejected(self):
        with self.assertRaises(ValueError):
            bc.BillPayClient("u", "p").list_billers([])


if __name__ == "__main__":
    unittest.main()
