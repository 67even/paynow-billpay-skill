#!/usr/bin/env bash
# Install the Laravel templates into a fresh Laravel app and run BillPayTest.
# Used by CI; runnable locally:  bash tools/ci_laravel.sh /tmp/billpay-laravel
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$ROOT/paynow-paybill-skills/paynow-billpay/templates/php-laravel"
APP="${1:-/tmp/billpay-laravel}"

rm -rf "$APP"
composer create-project laravel/laravel "$APP" --prefer-dist --no-interaction -q
cd "$APP"
php artisan install:api --no-interaction -q >/dev/null

mkdir -p app/Services/BillPay app/Jobs app/Http/Requests
cp "$T"/{BillPayClient,BillPayPaymentService,BillPayHooks,BillPayValidationException,BillPayTransportException}.php app/Services/BillPay/
cp "$T"/{BillPayTransaction,BillPayBiller,BillPayProduct}.php app/Models/
cp "$T"/{PollBillPayStatus,FetchBillPayFiscalData,SyncBillPayBillers}.php app/Jobs/
cp "$T"/BillPay*Controller.php app/Http/Controllers/
cp "$T"/StartBillPayPurchaseRequest.php app/Http/Requests/
cp "$T"/create_billpay_tables.php database/migrations/2026_01_01_000000_create_billpay_tables.php
cp "$T"/BillPayTest.php tests/Feature/
cp "$ROOT/paynow-paybill-skills/paynow-billpay/config/laravel/billpay.php" config/

# Merge the route block from routes.php (everything before the console section) into routes/api.php
python3 - "$T/routes.php" <<'PY'
import re, sys
src = open(sys.argv[1]).read().split("// routes/console.php")[0]
uses = "\n".join(l for l in src.splitlines() if l.startswith("use "))
body = src.split("use Illuminate\\Support\\Facades\\Route;", 1)[1]
body = body.rsplit("// ---", 1)[0]
api = open("routes/api.php").read()
api = api.replace("<?php", "<?php\n\n" + uses + "\n", 1)
api = re.sub(r"^use Illuminate\\Support\\Facades\\Route;\n", "", api, count=1, flags=re.M)
api = api.replace(uses + "\n", uses + "\nuse Illuminate\\Support\\Facades\\Route;\n", 1)
api += "\n" + body
open("routes/api.php", "w").write(api)
PY
php -l routes/api.php >/dev/null

php artisan test tests/Feature/BillPayTest.php
