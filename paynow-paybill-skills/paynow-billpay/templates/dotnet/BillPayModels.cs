// Paynow BillPay models (.NET 8, System.Text.Json). Field names match the API exactly.
using System.Text.Json;
using System.Text.Json.Serialization;

namespace BillPay;

public static class BillPayStatuses
{
    public static readonly HashSet<string> Final = new() { "Paid", "Failed", "Reversed" };
    // BeingPaid: Test Biller PP prefix (used in UAT). Pending: ZETDC under load.
    public static readonly HashSet<string> Pending = new() { "BeingProcessed", "BeingPaid", "Pending" };
    public static bool IsFinal(string? status) => status is not null && Final.Contains(status);
}

public class PaymentRequest
{
    public string Action { get; set; } = "Auth";
    public string? BillerCode { get; set; }
    public string? MemberNumber { get; set; }
    public string Reference { get; set; } = "";
    public decimal? TotalAmount { get; set; }
    public List<PaymentProduct>? Products { get; set; }
    public PayerDetails? PayerDetails { get; set; }

    /// <summary>Copy for PAY. When AUTH returned the price, TotalAmount and Price are blanked.</summary>
    public PaymentRequest ForPay(bool authReturnsPrice) => new()
    {
        Action = "Pay",
        BillerCode = BillerCode,
        MemberNumber = MemberNumber,
        Reference = Reference,
        TotalAmount = authReturnsPrice ? null : TotalAmount,
        Products = Products?.Select(p => p.Clone(dropPrice: authReturnsPrice)).ToList(),
        PayerDetails = PayerDetails,
    };
}

public sealed record StatusRequest(string Reference)
{
    public string Action => "Status";
}

public class PaymentProduct
{
    public string Code { get; set; } = "";
    public int? Quantity { get; set; }
    public string? Department { get; set; }
    public decimal? Price { get; set; }
    public bool? RequiresForexPayment { get; set; }
    // Dictionary<string,string> (e.g. Liquid Home) or List<Dictionary<string,string>> (e.g. Pink Lotto)
    public object? Metadata { get; set; }

    public PaymentProduct Clone(bool dropPrice) => new()
    {
        Code = Code, Quantity = Quantity, Department = Department,
        Price = dropPrice ? null : Price, RequiresForexPayment = RequiresForexPayment, Metadata = Metadata,
    };
}

public class PayerDetails
{
    public string? BankAccountName { get; set; }
    public string? BankAccountNumber { get; set; }
    public string? BankName { get; set; }
    public string? BankBranch { get; set; }
    public string? BankReference { get; set; }
    public string? ContactNumber { get; set; }
    public string? NationalId { get; set; }
}

public class AuthResponseData
{
    public string? MemberName { get; set; }
    public string? MemberAddress { get; set; }
    public Dictionary<string, string>? AccountDetails { get; set; }
    public Dictionary<string, string>? AccountBalances { get; set; }
    public decimal? AccountBalance { get; set; }
}

public class PaymentResponseData
{
    public List<string>? ReceiptHtml { get; set; }
    public Dictionary<string, string>? DisplayData { get; set; }
    public List<string>? ReceiptSmses { get; set; }
}

public class ProductVoucherData
{
    public string? SerialNumber { get; set; }
    public string? Pin { get; set; }
    public int? ValidDays { get; set; }
    public string? Batch { get; set; }
    public string? VoucherCode { get; set; }
    public DateTime? ExpiryDate { get; set; }
}

public class ProductResponse
{
    public string? Name { get; set; }
    public string? Code { get; set; }
    public int? Quantity { get; set; }
    public string? Department { get; set; }
    public decimal? Price { get; set; }
    public decimal? AccountBalance { get; set; }
    public bool? RequiresForexPayment { get; set; }
    public List<ProductVoucherData>? Vouchers { get; set; }
    public JsonElement? Metadata { get; set; }
    public decimal? VendorCommission { get; set; }
}

public class PaymentResponse
{
    public string? Action { get; set; }
    public string? BillerCode { get; set; }
    public string? Reference { get; set; }
    public string? MemberNumber { get; set; }
    public decimal? TotalAmount { get; set; }
    public string? Status { get; set; }
    public string? MemberName { get; set; }
    public List<ProductResponse>? Products { get; set; }
    public string? Narration { get; set; }
    public string? TechnicalNarration { get; set; }
    public string? BillPayReference { get; set; }
    public AuthResponseData? AuthData { get; set; }
    public PaymentResponseData? PaymentData { get; set; }
    public string? BillerPaymentReference { get; set; }
    public string? VendorServiceFeeCurrency { get; set; }
    public decimal? VendorServiceFee { get; set; }
    public string? Currency { get; set; }
    public string? WalletDebitReference { get; set; }
    public decimal? WalletBalanceAfterDebit { get; set; }
    public DateTime? WalletDebitReversed { get; set; }
    public decimal? WalletBalanceAfterReversal { get; set; }
    public string? VendorInvoiceReference { get; set; }
    public string? VendorFiscalSignature { get; set; }
    public string? VendorFiscalMetadata { get; set; }
    public string? VendorReversalReference { get; set; }
}

public class Wallet
{
    public string Currency { get; set; } = "";
    public decimal Balance { get; set; }
    public decimal LowBalance { get; set; }
    public decimal MinimumBalance { get; set; }
    public string Status { get; set; } = "";   // Open | Suspended | Closed
}

public class MemberLookupResponse
{
    public AuthResponseData? AuthData { get; set; }
    public int ResultCode { get; set; }          // 0 FailedPermanent, 1 Success, 2 Offline
    public string? Narration { get; set; }
    public string? TechnicalNarration { get; set; }
}

public class ReversalResponse
{
    public string? OriginalReference { get; set; }
    public string? Reference { get; set; }
    public int ErrorCode { get; set; }           // 0 ok, 1 not found, 2 dup ref, 3 biller failed, 4 unsupported, 5 already refunded, 99 general
    public string? Narration { get; set; }
    public string? TechnicalNarration { get; set; }
    public string? BillpayReference { get; set; } // note the lowercase "p" in this response
    public string? BillerReference { get; set; }
}

public class BillPayValidationException : Exception
{
    public string Body { get; }
    public BillPayValidationException(string body) : base("BillPay rejected the request: " + body) => Body = body;
}

/// <summary>Timeout, connection failure or HTTP 5xx. For PAY the outcome is UNKNOWN.</summary>
public class BillPayTransportException : Exception
{
    public BillPayTransportException(string message, Exception? inner = null) : base(message, inner) { }
}
