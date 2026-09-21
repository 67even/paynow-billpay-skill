<?php

namespace App\Services\BillPay;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Thin HTTP client for the Paynow BillPay Vendor and Biller APIs.
 *
 * - HTTP Basic auth on every request, 60 s timeout (config/billpay.php).
 * - HTTP 400  -> BillPayValidationException (ModelState details).
 * - Timeout, connection error, 5xx -> BillPayTransportException.
 *   For PAY this means "outcome unknown": never re-send PAY, poll STATUS instead.
 * - HTTP 200 does NOT mean success: callers must inspect the "Status" field.
 */
class BillPayClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout = 60,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            rtrim((string) config('billpay.base_url'), '/'),
            (string) config('billpay.username'),
            (string) config('billpay.password'),
            (int) config('billpay.timeout', 60),
        );
    }

    // ------------------------------------------------------------------
    // Vendor API
    // ------------------------------------------------------------------

    /**
     * @param string[]|null $billerCodes null = full catalogue (seed only!),
     *                                   non-empty array = filtered (after a webhook)
     */
    public function listBillers(?array $billerCodes = null): array
    {
        if ($billerCodes !== null && count($billerCodes) === 0) {
            // An empty filter would silently become an unfiltered call (fails UAT test 7)
            throw new InvalidArgumentException('billerCodes must be null or a non-empty array');
        }
        $query = $billerCodes ? ['billerCodes' => implode(',', $billerCodes)] : [];

        return $this->send('get', '/api/payment/ListBillers', $query) ?? [];
    }

    /** ResultCode: 0 = FailedPermanent, 1 = Success, 2 = Offline (retry later) */
    public function member(string $billerCode, string $memberNumber): array
    {
        return $this->send('get', '/api/payment/member', [
            'billerCode'   => $billerCode,
            'memberNumber' => $memberNumber,
        ]) ?? [];
    }

    public function wallets(): array
    {
        return $this->send('get', '/api/wallets') ?? [];
    }

    /** Raw access to /api/payment/process (Auth, Pay, Status, Retry). */
    public function process(array $payload): array
    {
        return $this->send('post', '/api/payment/process', $payload) ?? [];
    }

    public function status(string $reference): array
    {
        // Matches the official sample: only Reference + Action
        return $this->process(['Action' => 'Status', 'Reference' => $reference]);
    }

    /** ErrorCode 0 = success; see references/api-reference.md §12 for others. */
    public function reverse(string $originalReference, string $reversalReference): array
    {
        return $this->send('post', '/api/payment/reverse', [
            'OriginalReference' => $originalReference,
            'Reference'         => $reversalReference,
        ]) ?? [];
    }

    /**
     * @param array $filters From/To ("dd-MMM-yyyy HH:mm:ss"), BillerCode, Status,
     *                       VendorReference, Page, PerPage (max 200). Last 90 days only.
     */
    public function listPayments(array $filters = []): array
    {
        return $this->send('get', '/api/payment/list', $filters) ?? [];
    }

    /** Returns null when the biller has no target configured (200 + empty body). */
    public function targetStats(string $billerCode, ?string $currency = null): ?array
    {
        return $this->send('get', '/api/payment/TargetStats', array_filter([
            'billercode' => $billerCode,
            'currency'   => $currency,
        ]));
    }

    /** @param int|null $beforeUnixMs Unix time in milliseconds of the oldest record already shown */
    public function feed(string $billerCode, ?string $currency = null, ?int $beforeUnixMs = null): array
    {
        return $this->send('get', '/api/payment/feed', array_filter([
            'billercode' => $billerCode,
            'currency'   => $currency,
            'before'     => $beforeUnixMs,
        ])) ?? [];
    }

    // ------------------------------------------------------------------
    // Biller API (offline billers)
    // ------------------------------------------------------------------

    /** Update overwrites omitted optional fields with blanks — always send the full record. */
    public function saveMember(array $member, bool $update = false): void
    {
        if (isset($member['AccountDetails']) && is_array($member['AccountDetails'])) {
            $member['AccountDetails'] = json_encode($member['AccountDetails']); // JSON *string*
        }
        $this->send('post', $update ? '/api/member/update' : '/api/member/create', $member);
    }

    public function deleteMember(string $memberNumber): void
    {
        // Deleted member numbers can never be reused — undelete instead.
        $this->send('post', '/api/member/delete', ['MemberNumber' => $memberNumber]);
    }

    public function undeleteMember(string $memberNumber): void
    {
        $this->send('post', '/api/member/undelete', ['MemberNumber' => $memberNumber]);
    }

    public function listMembers(int $page = 1, int $perPage = 200, ?string $filters = null): array
    {
        return $this->send('get', '/api/member/list', array_filter([
            'Page' => $page, 'PerPage' => $perPage, 'Filters' => $filters,
        ])) ?? [];
    }

    public function getMember(string $memberNumber): array
    {
        return $this->send('get', '/api/member/single/'.rawurlencode($memberNumber)) ?? [];
    }

    /**
     * CSV columns (in order): Member Number, Full Name, Email, Mobile, Postal Address, [extra...]
     * The multipart field name is not documented by Paynow; "file" is an assumption.
     */
    public function uploadMembers(string $csvPath, bool $delete = false): array
    {
        $path = $delete ? '/api/member/uploadmembersdelete' : '/api/member/uploadmembers';

        try {
            $response = $this->http()
                ->attach('file', file_get_contents($csvPath), basename($csvPath))
                ->post($this->baseUrl.$path);
        } catch (ConnectionException $e) {
            throw new BillPayTransportException($e->getMessage(), 0, $e);
        }

        return $this->decode($response) ?? [];
    }

    /** Returns the raw CSV. Dates as "dd-MMM-yyyy HH:mm:ss" (both required). */
    public function downloadPayments(string $from, string $to, ?string $memberNumber = null): string
    {
        try {
            $response = $this->http()->get($this->baseUrl.'/api/member/downloadpayments', array_filter([
                'From' => $from, 'To' => $to, 'MemberNumber' => $memberNumber,
            ]));
        } catch (ConnectionException $e) {
            throw new BillPayTransportException($e->getMessage(), 0, $e);
        }
        $this->assertOk($response);

        return $response->body();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function http(): PendingRequest
    {
        return Http::withBasicAuth($this->username, $this->password)
            ->timeout($this->timeout)
            ->acceptJson();
    }

    private function send(string $method, string $path, array $data = []): ?array
    {
        try {
            $request = $this->http();
            $response = $method === 'get'
                ? $request->get($this->baseUrl.$path, $data)
                : $request->asJson()->post($this->baseUrl.$path, $data);
        } catch (ConnectionException $e) {
            // Timeout or network failure: outcome unknown
            throw new BillPayTransportException($e->getMessage(), 0, $e);
        }

        return $this->decode($response);
    }

    private function decode(Response $response): ?array
    {
        $this->assertOk($response);
        $body = trim($response->body());

        return $body === '' ? null : $response->json();
    }

    private function assertOk(Response $response): void
    {
        if ($response->status() === 400) {
            throw new BillPayValidationException(
                (string) ($response->json('Message') ?? 'The request is invalid.'),
                (array) ($response->json('ModelState') ?? []),
            );
        }
        if (! $response->successful()) {
            throw new BillPayTransportException('BillPay HTTP '.$response->status());
        }
    }
}
