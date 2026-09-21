<?php

namespace Tests\Feature;

use App\Jobs\PollBillPayStatus;
use App\Jobs\SyncBillPayBillers;
use App\Models\BillPayTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Services\BillPay\BillPayClient;
use App\Services\BillPay\BillPayHooks;
use App\Services\BillPay\BillPayPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FakeHooks implements BillPayHooks
{
    public array $log = [];
    public function chargeCustomer(BillPayTransaction $tx, float $amount): void { $this->log[] = ['charge', $amount]; }
    public function refundCustomer(BillPayTransaction $tx, string $reason): void { $this->log[] = ['refund', $reason]; }
    public function sendReceiptHtml(BillPayTransaction $tx, string $html, int $index): void { $this->log[] = ['receipt', $index]; }
    public function sendSms(BillPayTransaction $tx, string $text, int $index): void { $this->log[] = ['sms', $index]; }
    public function notifyCustomerDelayed(BillPayTransaction $tx): void { $this->log[] = ['delayed']; }
    public function alertOperations(string $subject, array $context = []): void { $this->log[] = ['alert', $subject]; }
}

class BillPayTest extends TestCase
{
    use RefreshDatabase;

    private FakeHooks $hooks;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billpay.username' => 'u', 'billpay.password' => 'p', 'billpay.base_url' => 'https://billpay.paynow.co.zw']);
        $this->hooks = new FakeHooks();
        $this->app->instance(BillPayHooks::class, $this->hooks);
        $this->app->singleton(BillPayClient::class, fn () => BillPayClient::fromConfig());
    }

    private function service(): BillPayPaymentService { return $this->app->make(BillPayPaymentService::class); }

    public function test_happy_path_delivers_each_receipt_and_sms(): void
    {
        Queue::fake();
        Http::fake(function (Request $r) {
            $this->assertTrue($r->hasHeader('Authorization', 'Basic '.base64_encode('u:p')));
            return $r['Action'] === 'Auth'
                ? Http::response(['Status' => 'Authorized', 'AuthData' => ['MemberName' => 'Bulawayo Guy', 'MemberAddress' => '1 Main']])
                : Http::response(['Status' => 'Paid', 'Currency' => 'USD', 'WalletBalanceAfterDebit' => 900,
                    'PaymentData' => ['ReceiptHtml' => ['<p>1</p>', '<p>2</p>'], 'ReceiptSmses' => ['t1', 't2']]]);
        });
        $tx = $this->service()->authorize('TEST', '12345', [['Code' => 'RV', 'Quantity' => 1, 'Price' => 10]], 10.0);
        $this->assertSame('authorized', $tx->state);
        $this->assertSame('Bulawayo Guy', $tx->member_name);
        $tx = $this->service()->confirmAndPay($tx);
        $this->assertSame('fulfilled', $tx->state);
        $this->assertSame([['charge', 10.0], ['receipt', 0], ['receipt', 1], ['sms', 0], ['sms', 1]], $this->hooks->log);
        Http::assertSent(fn (Request $r) => $r['Action'] === 'Pay' && $r['Reference'] === $tx->reference && $r['TotalAmount'] == 10);
    }

    public function test_auth_returned_price_is_charged_and_blanked_in_pay(): void
    {
        Queue::fake();
        Http::fake(fn (Request $r) => $r['Action'] === 'Auth'
            ? Http::response(['Status' => 'Authorized', 'TotalAmount' => 55.5, 'AuthData' => ['MemberName' => 'X']])
            : Http::response(['Status' => 'Paid']));
        $tx = $this->service()->authorize('TEST', '12345', [['Code' => 'AM', 'Quantity' => 1]], null, authReturnsPrice: true);
        $this->assertEquals(55.5, (float) $tx->amount_due);
        $this->service()->confirmAndPay($tx);
        $this->assertSame(['charge', 55.5], $this->hooks->log[0]);
        Http::assertSent(fn (Request $r) => $r['Action'] === 'Pay' && ! array_key_exists('TotalAmount', $r->data())
            && ! array_key_exists('Price', $r['Products'][0]));
    }

    public function test_pay_timeout_polls_status_and_never_resends_pay(): void
    {
        Queue::fake();
        $statuses = ['BeingPaid', 'Flagged', 'Paid'];
        $pays = 0;
        Http::fake(function (Request $r) use (&$statuses, &$pays) {
            if ($r['Action'] === 'Auth') return Http::response(['Status' => 'Authorized', 'AuthData' => ['MemberName' => 'X']]);
            if ($r['Action'] === 'Pay') { $pays++; throw new ConnectionException('timed out'); }
            return Http::response(['Status' => array_shift($statuses), 'PaymentData' => ['ReceiptSmses' => ['s']]]);
        });
        $tx = $this->service()->authorize('TEST', 'PT12345', [['Code' => 'RV', 'Price' => 5]], 5.0);
        $tx = $this->service()->confirmAndPay($tx);
        $this->assertSame('pending', $tx->state);
        Queue::assertPushed(PollBillPayStatus::class, fn ($job) => $job->delay->diffInSeconds(now(), true) >= 119);

        $this->assertSame(180, $this->service()->checkStatus($tx->refresh()));   // BeingPaid treated as pending
        $this->assertSame(600, $this->service()->checkStatus($tx->refresh()));   // Flagged
        $this->assertNull($this->service()->checkStatus($tx->refresh()));        // Paid
        $this->assertSame('fulfilled', $tx->refresh()->state);
        $this->assertSame(1, $pays); // PAY sent exactly once
        $this->assertSame(3, collect(Http::recorded())->filter(fn ($p) => $p[0]['Action'] === 'Status')->count());
    }

    public function test_failed_payment_refunds(): void
    {
        Queue::fake();
        Http::fake(fn (Request $r) => $r['Action'] === 'Auth'
            ? Http::response(['Status' => 'Authorized', 'AuthData' => ['MemberName' => 'X']])
            : Http::response(['Status' => 'Failed', 'Narration' => 'Nope']));
        $tx = $this->service()->confirmAndPay($this->service()->authorize('TEST', 'PF1', [['Code' => 'RV', 'Price' => 5]], 5.0));
        $this->assertSame('refunded', $tx->state);
        $this->assertSame(['refund', 'Nope'], $this->hooks->log[1]);
    }

    public function test_polling_cap_escalates(): void
    {
        Queue::fake();
        config(['billpay.polling.max_attempts' => 2]);
        Http::fake(fn () => Http::response(['Status' => 'BeingProcessed']));
        $tx = BillPayTransaction::create(['reference' => (string) \Illuminate\Support\Str::uuid(), 'biller_code' => 'T',
            'member_number' => '1', 'auth_request' => [], 'state' => 'pending']);
        $this->assertSame(180, $this->service()->checkStatus($tx));
        $this->assertNull($this->service()->checkStatus($tx->refresh()));
        $this->assertSame('needs_attention', $tx->refresh()->state);
        $this->assertContains(['delayed'], $this->hooks->log);
    }

    public function test_config_webhook(): void
    {
        Queue::fake();
        $this->postJson('/api/webhooks/billpay/config', ['ZETDC', 'EVD'])->assertOk();
        Queue::assertPushed(SyncBillPayBillers::class, fn ($j) => $j->billerCodes === ['ZETDC', 'EVD']);

        Queue::fake();
        $this->postJson('/api/webhooks/billpay/config', [])->assertOk();
        Queue::assertNothingPushed();

        config(['billpay.webhook.token' => 'sekret']);
        $this->postJson('/api/webhooks/billpay/config', ['A'])->assertUnauthorized();
        $this->postJson('/api/webhooks/billpay/config', ['A'], ['Authorization' => 'Bearer sekret'])->assertOk();
    }

    public function test_sync_job_uses_filtered_call(): void
    {
        Http::fake(['*' => Http::response([['Code' => 'EVD', 'Name' => 'EVD', 'Enabled' => true,
            'Products' => [['Code' => 'x', 'Name' => 'X', 'AuthAmountMandated' => null, 'Enabled' => true]]]])]);
        (new SyncBillPayBillers(['EVD']))->handle($this->app->make(BillPayClient::class));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'billerCodes=EVD'));
        $this->assertDatabaseHas('billpay_products', ['biller_code' => 'EVD', 'code' => 'x']);
    }

    public function test_payment_webhook_hmac_and_legacy(): void
    {
        config(['billpay.webhook.secret_key' => '415b654f-3544-4281-a91e-051e710bfb8d']);
        $raw = '{"Payments":[{"PaymentId":172,"BillPayReference":"FAKE-181211122304615","BankReference":"9796","PaidDate":"11-Dec-2018 12:24:51","MemberNumber":"T00001","MemberName":"John Doe","ProductCode":"LN","ProductPrice":3.21,"ProductDepartment":"Sales"},{"PaymentId":245,"BillPayReference":"FAKE-18121112212345","BankReference":"","PaidDate":"11-Dec-2018 13:14:11","MemberNumber":"K00123","MemberName":"Abby Fijngold","ProductCode":"MP","ProductPrice":30.00,"ProductDepartment":"Sales"}],"Hash":"660ad6a83bdd9993a2ef44e3b02098a6ce62763a145eccf1f669951bdd53ce40"}';
        $sig = base64_encode(hash_hmac('sha256', $raw, '415b654f-3544-4281-a91e-051e710bfb8d', true));

        $this->call('POST', '/api/webhooks/billpay/payments', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => 'bad'], $raw)->assertUnauthorized();
        $this->call('POST', '/api/webhooks/billpay/payments', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => $sig], $raw)->assertOk();
        $this->assertDatabaseCount('billpay_received_payments', 2);
        // legacy (no header) + idempotent redelivery
        $this->call('POST', '/api/webhooks/billpay/payments', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)->assertOk();
        $this->assertDatabaseCount('billpay_received_payments', 2);
    }

    public function test_empty_biller_codes_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(BillPayClient::class)->listBillers([]);
    }

    private function seedCatalogue(): void
    {
        DB::table('billpay_billers')->insert(['code' => 'ZETDC', 'name' => 'ZETDC', 'enabled' => true,
            'member_number_regex' => '^[0-9]{11}$', 'member_number_label' => 'Meter number']);
        DB::table('billpay_products')->insert([
            ['biller_code' => 'ZETDC', 'code' => 'ZESA', 'name' => 'Tokens', 'price' => null, 'enabled' => true,
             'requires_forex' => null, 'auth_amount_mandated' => null, 'min_amount' => 1, 'max_amount' => 500],
        ]);
    }

    public function test_http_purchase_flow_with_forex_from_auth_and_double_confirm_guard(): void
    {
        Queue::fake();
        $this->seedCatalogue();
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/api/payment/member')) return Http::response(['ResultCode' => 1, 'AuthData' => ['MemberName' => 'Tariro']]);
            return $r['Action'] === 'Auth'
                ? Http::response(['Status' => 'Authorized', 'AuthData' => ['MemberName' => 'Tariro', 'MemberAddress' => '1 Main'],
                    'Products' => [['Code' => 'ZESA', 'RequiresForexPayment' => true]]])
                : Http::response(['Status' => 'Paid', 'Currency' => 'USD',
                    'PaymentData' => ['ReceiptHtml' => ['<p>a</p>', '<p>b</p>'], 'ReceiptSmses' => ['t1', 't2']],
                    'Products' => [['Code' => 'ZESA', 'Vouchers' => [['VoucherCode' => '1234']]]]]);
        });
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/billpay/purchases', ['biller_code' => 'ZETDC', 'product_code' => 'ZESA',
            'member_number' => '123', 'amount' => 20])->assertStatus(422)->assertJsonValidationErrors('member_number');
        $this->actingAs($user)->postJson('/api/billpay/purchases', ['biller_code' => 'ZETDC', 'product_code' => 'ZESA',
            'member_number' => '37132567431', 'amount' => 900])->assertStatus(422)->assertJsonValidationErrors('amount');

        $ref = $this->actingAs($user)->postJson('/api/billpay/purchases', ['biller_code' => 'ZETDC', 'product_code' => 'ZESA',
            'member_number' => '3713 2567 431', 'amount' => 20])
            ->assertCreated()->assertJsonPath('member_name', 'Tariro')->assertJsonPath('member_address', '1 Main')->json('reference');

        $this->actingAs($user)->postJson("/api/billpay/purchases/{$ref}/confirm")->assertOk()
            ->assertJsonPath('state', 'fulfilled')->assertJsonPath('sms', ['t1', 't2'])
            ->assertJsonPath('vouchers.0.VoucherCode', '1234')->assertJsonCount(2, 'receipts');
        $this->actingAs($user)->postJson("/api/billpay/purchases/{$ref}/confirm")->assertStatus(409);

        Http::assertSent(fn (Request $r) => ($r->data()['Action'] ?? null) === 'Pay' && $r['Products'][0]['RequiresForexPayment'] === true
            && $r['Products'][0]['Price'] == 20 && $r['TotalAmount'] == 20);
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($p) => ($p[0]->data()['Action'] ?? null) === 'Pay')->count());
        $this->assertSame([['charge', 20.0], ['receipt', 0], ['receipt', 1], ['sms', 0], ['sms', 1]], $this->hooks->log);

        $this->actingAs($user)->get("/api/billpay/purchases/{$ref}/receipts/1")->assertOk()->assertSee('<p>b</p>', false)
            ->assertHeader('Content-Security-Policy');
    }

    public function test_charge_failure_never_pays(): void
    {
        Queue::fake();
        Http::fake(fn (Request $r) => Http::response(['Status' => 'Authorized', 'AuthData' => ['MemberName' => 'X']]));
        $hooks = new class extends FakeHooks { public function chargeCustomer(BillPayTransaction $tx, float $amount): void { throw new \RuntimeException('card declined'); } };
        $this->app->instance(BillPayHooks::class, $hooks);
        $tx = $this->service()->authorize('TEST', '1', [['Code' => 'RV', 'Price' => 5]], 5.0);
        try { $this->service()->confirmAndPay($tx); $this->fail('expected exception'); } catch (\RuntimeException $e) {}
        $this->assertSame('charge_failed', $tx->refresh()->state);
        Http::assertNotSent(fn (Request $r) => ($r->data()['Action'] ?? null) === 'Pay');
    }
}
