// Vendor purchase flow: AUTH → confirm → charge → PAY → (pending → STATUS polling) → deliver.
// In production run PollUntilFinalAsync from a background job (Hangfire, Quartz, hosted service).
namespace BillPay;

public interface IBillPayHooks
{
    Task ChargeCustomerAsync(string reference, decimal amount);                 // throw on failure
    Task RefundCustomerAsync(string reference, decimal amount, string reason);
    Task SendReceiptHtmlAsync(string reference, string html);                 // one entry per call
    Task SendSmsAsync(string reference, string text);                         // one SMS per call
    Task<bool> ConfirmWithCustomerAsync(AuthResponseData? member, decimal amount);
    Task EscalateAsync(string reference, string? lastStatus);
    Task SaveAsync(string reference, string state, object? data = null);      // persist state
}

public sealed class PurchaseFlow
{
    private readonly BillPayClient _client;
    private readonly IBillPayHooks _hooks;

    public PurchaseFlow(BillPayClient client, IBillPayHooks hooks) => (_client, _hooks) = (client, hooks);

    /// <summary>STATUS 120 s after pending/unknown, then every 180 s (600 s while Flagged).</summary>
    public async Task<PaymentResponse?> PollUntilFinalAsync(string reference, int maxAttempts = 10, CancellationToken ct = default)
    {
        await Task.Delay(TimeSpan.FromSeconds(120), ct);
        PaymentResponse? result = null;
        for (var i = 0; i < maxAttempts; i++)
        {
            try
            {
                result = await _client.StatusAsync(reference, ct);
            }
            catch (Exception e) when (e is BillPayTransportException or BillPayValidationException)
            {
                await Task.Delay(TimeSpan.FromSeconds(180), ct);   // transient: keep the cadence
                continue;
            }
            if (BillPayStatuses.IsFinal(result.Status)) return result;
            await Task.Delay(TimeSpan.FromSeconds(result.Status == "Flagged" ? 600 : 180), ct);
        }
        return result;   // still non-final → escalate
    }

    /// <param name="authReturnsPrice">true when the product's AuthAmountMandated is true or false.</param>
    public async Task<PaymentResponse?> PurchaseAsync(string billerCode, string memberNumber,
        List<PaymentProduct> products, decimal? totalAmount, bool authReturnsPrice = false,
        PayerDetails? payerDetails = null, CancellationToken ct = default)
    {
        var authReq = new PaymentRequest
        {
            BillerCode = billerCode, MemberNumber = memberNumber, Reference = Guid.NewGuid().ToString(),
            TotalAmount = totalAmount, Products = products, PayerDetails = null,
        };
        var reference = authReq.Reference;
        await _hooks.SaveAsync(reference, "created", authReq);                  // persist first

        var auth = await _client.AuthAsync(authReq, ct);                        // nothing charged yet
        if (auth.Status != "Authorized")
        {
            await _hooks.SaveAsync(reference, "auth_failed", auth);
            throw new BillPayValidationException(auth.Narration ?? "Authorisation failed");
        }

        var amountDue = (authReturnsPrice ? auth.TotalAmount : totalAmount) ?? 0m;
        if (!await _hooks.ConfirmWithCustomerAsync(auth.AuthData, amountDue))   // UAT test 2
        {
            await _hooks.SaveAsync(reference, "abandoned");
            return null;
        }

        // PAY mirrors AUTH; TotalAmount/Price blanked when AUTH returned the price.
        // (Part-payment submission is not specified by Paynow — confirm with support.)
        // Forex: where RequiresForexPayment wasn't set (catalogue RequiresForex = null), AUTH decides.
        for (var i = 0; i < products.Count; i++)
            if (products[i].RequiresForexPayment is null && auth.Products?.ElementAtOrDefault(i)?.RequiresForexPayment is bool fx)
                products[i].RequiresForexPayment = fx;
        var payReq = authReq.ForPay(authReturnsPrice);
        payReq.PayerDetails = payerDetails;

        await _hooks.ChargeCustomerAsync(reference, amountDue);
        await _hooks.SaveAsync(reference, "paying");

        PaymentResponse? result;
        try
        {
            result = await _client.PayAsync(payReq, ct);
        }
        catch (BillPayValidationException e)                                   // 400: rejected
        {
            await _hooks.RefundCustomerAsync(reference, amountDue, e.Message);
            await _hooks.SaveAsync(reference, "refunded");
            throw;
        }
        catch (BillPayTransportException)                                      // unknown: never re-send PAY
        {
            result = await PollUntilFinalAsync(reference, ct: ct);
        }

        if (!BillPayStatuses.IsFinal(result?.Status))                          // BeingProcessed/BeingPaid/Pending/Flagged
            result = await PollUntilFinalAsync(reference, ct: ct);

        switch (result?.Status)
        {
            case "Paid":
                foreach (var html in result.PaymentData?.ReceiptHtml ?? new()) await _hooks.SendReceiptHtmlAsync(reference, html);
                foreach (var sms in result.PaymentData?.ReceiptSmses ?? new()) await _hooks.SendSmsAsync(reference, sms);
                await _hooks.SaveAsync(reference, "fulfilled", result);
                break;
            case "Failed":
                await _hooks.RefundCustomerAsync(reference, amountDue, result.Narration ?? "Payment failed");
                await _hooks.SaveAsync(reference, "refunded", result);
                break;
            default:
                await _hooks.EscalateAsync(reference, result?.Status);
                await _hooks.SaveAsync(reference, "needs_attention", result);
                break;
        }
        return result;
    }
}
