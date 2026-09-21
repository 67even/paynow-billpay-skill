// BillPay webhook receivers (ASP.NET Core minimal API, .NET 8).
// In Program.cs:  app.MapBillPayWebhooks();
using System.Globalization;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Routing;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;

namespace BillPay;

public interface IBillPayStore
{
    Task UpsertBillersAsync(JsonElement billers);          // vendor catalogue
    Task UpsertPaymentAsync(JsonElement payment);          // biller: keyed on PaymentId
}

public static class BillPayWebhooks
{
    private static bool SafeEquals(string a, string b) =>
        CryptographicOperations.FixedTimeEquals(Encoding.UTF8.GetBytes(a), Encoding.UTF8.GetBytes(b));

    public static IEndpointRouteBuilder MapBillPayWebhooks(this IEndpointRouteBuilder app)
    {
        // Vendor: ["CODE1","CODE2"] — respond 200 fast, then refresh ONLY those billers
        app.MapPost("/webhooks/billpay/config", async (HttpRequest req, IConfiguration cfg,
            IServiceScopeFactory scopes, ILoggerFactory logs) =>
        {
            var token = cfg["BillPay:WebhookToken"];   // set only once BillPay has configured it
            if (!string.IsNullOrEmpty(token) && !SafeEquals(req.Headers.Authorization.ToString(), "Bearer " + token))
                return Results.Unauthorized();

            List<string> codes;
            try
            {
                codes = (await JsonSerializer.DeserializeAsync<List<string>>(req.Body) ?? new())
                        .Where(c => !string.IsNullOrWhiteSpace(c)).Distinct().ToList();
            }
            catch (JsonException) { codes = new(); }

            if (codes.Count > 0)   // never fall back to an unfiltered call
            {
                _ = Task.Run(async () =>
                {
                    using var scope = scopes.CreateScope();
                    try
                    {
                        var client = scope.ServiceProvider.GetRequiredService<BillPayClient>();
                        var store = scope.ServiceProvider.GetRequiredService<IBillPayStore>();
                        await store.UpsertBillersAsync(await client.ListBillersAsync(codes));
                    }
                    catch (Exception e)
                    {
                        logs.CreateLogger("BillPay").LogError(e, "BillPay config resync failed");
                    }
                });   // prefer a real background queue in production
            }
            return Results.Ok();   // otherwise BillPay retries every 30 s, up to 3 times
        });

        // Biller: X-Signature = base64(HMAC-SHA256(raw body, secret)) over the RAW bytes
        app.MapPost("/webhooks/billpay/payments", async (HttpRequest req, IConfiguration cfg, IBillPayStore store) =>
        {
            var secret = cfg["BillPay:SecretKey"] ?? "";
            if (secret.Length == 0) return Results.Unauthorized();

            using var ms = new MemoryStream();
            await req.Body.CopyToAsync(ms);
            var raw = ms.ToArray();

            JsonDocument doc;
            try { doc = JsonDocument.Parse(raw); } catch (JsonException) { return Results.BadRequest(); }
            using var _ = doc;

            var signature = req.Headers["X-Signature"].ToString();
            if (!string.IsNullOrEmpty(signature))
            {
                var computed = Convert.ToBase64String(HMACSHA256.HashData(Encoding.UTF8.GetBytes(secret), raw));
                if (!SafeEquals(computed, signature)) return Results.Unauthorized();
            }
            else if (!LegacyHashOk(doc.RootElement, secret))
            {
                return Results.Unauthorized();
            }

            foreach (var p in doc.RootElement.GetProperty("Payments").EnumerateArray())
                await store.UpsertPaymentAsync(p.Clone());   // idempotent on PaymentId
            return Results.Ok();
        });

        return app;
    }

    // Legacy: sha256(fields in this exact order + secret), lowercase hex. ProductPrice to 2 dp.
    private static bool LegacyHashOk(JsonElement root, string secret)
    {
        if (!root.TryGetProperty("Hash", out var hash) || !root.TryGetProperty("Payments", out var payments)) return false;
        var sb = new StringBuilder();
        foreach (var p in payments.EnumerateArray())
        {
            string S(string name) => p.TryGetProperty(name, out var v) && v.ValueKind != JsonValueKind.Null
                ? (v.ValueKind == JsonValueKind.String ? v.GetString()! : v.GetRawText()) : "";
            sb.Append(S("PaymentId")).Append(S("BillPayReference")).Append(S("BankReference"))
              .Append(S("PaidDate")).Append(S("MemberNumber")).Append(S("MemberName")).Append(S("ProductCode"))
              .Append(p.GetProperty("ProductPrice").GetDecimal().ToString("0.00", CultureInfo.InvariantCulture))
              .Append(S("ProductDepartment"));
        }
        var expected = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(sb + secret))).ToLowerInvariant();
        return SafeEquals(expected, (hash.GetString() ?? "").ToLowerInvariant());
    }
}
