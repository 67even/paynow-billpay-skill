// Paynow BillPay client (.NET 8). Register as a typed HttpClient:
//   builder.Services.AddHttpClient<BillPayClient>(c => BillPayClient.Configure(c, builder.Configuration));
using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using Microsoft.Extensions.Configuration;

namespace BillPay;

public sealed class BillPayClient
{
    private readonly HttpClient _http;

    // Omit nulls instead of sending "Field": null
    public static readonly JsonSerializerOptions Json = new()
    {
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
        PropertyNameCaseInsensitive = true,
    };

    public BillPayClient(HttpClient http) => _http = http;

    public static void Configure(HttpClient c, IConfiguration cfg)
    {
        c.BaseAddress = new Uri(cfg["BillPay:BaseUrl"] ?? "https://billpay.paynow.co.zw");
        c.Timeout = TimeSpan.FromSeconds(int.Parse(cfg["BillPay:TimeoutSeconds"] ?? "60"));
        var token = Convert.ToBase64String(Encoding.UTF8.GetBytes($"{cfg["BillPay:Username"]}:{cfg["BillPay:Password"]}"));
        c.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Basic", token);
        c.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
    }

    // ---------------- vendor API ----------------

    /// <summary>codes == null → full catalogue (seed only). After a webhook ALWAYS pass codes.</summary>
    public Task<JsonElement> ListBillersAsync(IReadOnlyCollection<string>? codes = null, CancellationToken ct = default)
    {
        if (codes is { Count: 0 })
            throw new ArgumentException("codes must be null or non-empty (avoid an unfiltered call)");
        var qs = codes is null ? "" : "?billerCodes=" + Uri.EscapeDataString(string.Join(',', codes));
        return SendAsync<JsonElement>(HttpMethod.Get, "/api/payment/ListBillers" + qs, null, ct);
    }

    public Task<MemberLookupResponse> MemberAsync(string billerCode, string memberNumber, CancellationToken ct = default) =>
        SendAsync<MemberLookupResponse>(HttpMethod.Get,
            $"/api/payment/member?billerCode={Uri.EscapeDataString(billerCode)}&memberNumber={Uri.EscapeDataString(memberNumber)}", null, ct);

    public Task<List<Wallet>> WalletsAsync(CancellationToken ct = default) =>
        SendAsync<List<Wallet>>(HttpMethod.Get, "/api/wallets", null, ct);

    public Task<PaymentResponse> AuthAsync(PaymentRequest r, CancellationToken ct = default)
    {
        r.Action = "Auth";
        return SendAsync<PaymentResponse>(HttpMethod.Post, "/api/payment/process", r, ct);
    }

    public Task<PaymentResponse> PayAsync(PaymentRequest payRequest, CancellationToken ct = default) =>
        SendAsync<PaymentResponse>(HttpMethod.Post, "/api/payment/process", payRequest, ct);

    /// <summary>Only Reference + Action are sent, as in the official sample.</summary>
    public Task<PaymentResponse> StatusAsync(string reference, CancellationToken ct = default) =>
        SendAsync<PaymentResponse>(HttpMethod.Post, "/api/payment/process", new StatusRequest(reference), ct);

    public Task<ReversalResponse> ReverseAsync(string originalReference, string reversalReference, CancellationToken ct = default) =>
        SendAsync<ReversalResponse>(HttpMethod.Post, "/api/payment/reverse",
            new { OriginalReference = originalReference, Reference = reversalReference }, ct);

    // ---------------- biller API ----------------

    /// <summary>Update blanks omitted optional fields — send the full record. AccountDetails is a JSON string.</summary>
    public Task SaveMemberAsync(object member, bool update = false, CancellationToken ct = default) =>
        SendAsync<JsonElement?>(HttpMethod.Post, update ? "/api/member/update" : "/api/member/create", member, ct);

    public async Task<string> DownloadPaymentsAsync(string from, string to, string? memberNumber = null, CancellationToken ct = default)
    {
        var url = $"/api/member/downloadpayments?From={Uri.EscapeDataString(from)}&To={Uri.EscapeDataString(to)}"
                  + (memberNumber is null ? "" : "&MemberNumber=" + Uri.EscapeDataString(memberNumber));
        using var res = await SendRawAsync(new HttpRequestMessage(HttpMethod.Get, url), ct);
        return await res.Content.ReadAsStringAsync(ct);
    }

    // ---------------- internals ----------------

    private async Task<T> SendAsync<T>(HttpMethod method, string url, object? body, CancellationToken ct)
    {
        var req = new HttpRequestMessage(method, url);
        if (body is not null) req.Content = JsonContent.Create(body, body.GetType(), options: Json);
        using var res = await SendRawAsync(req, ct);
        var text = await res.Content.ReadAsStringAsync(ct);
        return string.IsNullOrWhiteSpace(text) ? default! : JsonSerializer.Deserialize<T>(text, Json)!;
    }

    private async Task<HttpResponseMessage> SendRawAsync(HttpRequestMessage req, CancellationToken ct)
    {
        HttpResponseMessage res;
        try
        {
            res = await _http.SendAsync(req, ct);
        }
        catch (Exception e) when (e is HttpRequestException or TaskCanceledException)
        {
            // Timeout or network failure: outcome unknown
            throw new BillPayTransportException("BillPay request failed: " + e.Message, e);
        }

        if (res.StatusCode == HttpStatusCode.BadRequest)
        {
            var body = await res.Content.ReadAsStringAsync(ct);
            res.Dispose();
            throw new BillPayValidationException(body);
        }
        if (!res.IsSuccessStatusCode)
        {
            var code = (int)res.StatusCode;
            res.Dispose();
            throw new BillPayTransportException($"BillPay HTTP {code}");
        }
        return res;
    }
}
