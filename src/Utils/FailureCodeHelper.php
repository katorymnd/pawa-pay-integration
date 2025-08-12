<?php

namespace Katorymnd\PawaPayIntegration\Utils;

class FailureCodeHelper
{
    /**
     * Aliases to normalize code names across V1/V2 and operations.
     * Everything is uppercased, so keys/values must be uppercase.
     */
    private static $aliases = [
        // Provider/Correspondent synonyms
        'CORRESPONDENT_TEMPORARILY_UNAVAILABLE' => 'PROVIDER_TEMPORARILY_UNAVAILABLE',
        // Amount bounds synonyms
        'AMOUNT_TOO_SMALL' => 'AMOUNT_OUT_OF_BOUNDS',
        'AMOUNT_TOO_LARGE' => 'AMOUNT_OUT_OF_BOUNDS',
        // Phone number / format synonyms
        'INVALID_RECIPIENT_FORMAT' => 'INVALID_PHONE_NUMBER',
        'INVALID_PAYER_FORMAT'     => 'INVALID_PHONE_NUMBER',
        // Balance synonyms
        'BALANCE_INSUFFICIENT'           => 'PAWAPAY_WALLET_OUT_OF_FUNDS',
        // Flow synonyms (already-in-process)
        'TRANSACTION_ALREADY_IN_PROCESS' => 'PAYMENT_IN_PROGRESS',
        // Recipient allowed / wallet limits
        'RECIPIENT_NOT_ALLOWED_TO_RECEIVE' => 'WALLET_LIMIT_REACHED',
        // “Not found” mapping across ops
        'DEPOSIT_NOT_FOUND' => 'NOT_FOUND',
        // Generic other error
        'OTHER_ERROR'       => 'UNKNOWN_ERROR',
    ];

    /**
     * Rejection messages (initiation time) — V1 + V2
     */
    private static $rejectionMessages = [
        // Common transport/auth/signature (V2)
        'NO_AUTHENTICATION'          => 'Authentication header is missing.',
        'AUTHENTICATION_ERROR'       => 'The API token is invalid.',
        'AUTHORISATION_ERROR'        => 'The API token is not authorised for this request.',
        'HTTP_SIGNATURE_ERROR'       => 'The HTTP signature failed verification.',
        'INVALID_INPUT'              => 'We could not parse the request payload.',
        'MISSING_PARAMETER'          => 'A required parameter is missing.',
        'UNSUPPORTED_PARAMETER'      => 'An unsupported parameter was provided.',
        'INVALID_PARAMETER'          => 'A parameter contains an invalid value.',
        'DUPLICATE_METADATA_FIELD'   => 'Duplicate field in metadata.',
        // Amount/currency/provider/country
        'INVALID_AMOUNT'             => 'The amount is not valid for this provider.',
        'AMOUNT_OUT_OF_BOUNDS'       => 'The amount is outside provider limits.',
        'INVALID_CURRENCY'           => 'The currency is not supported by this provider.',
        'INVALID_COUNTRY'            => 'The specified country is not supported.',
        'INVALID_PROVIDER'           => 'The provider is invalid for this request.',
        'INVALID_PHONE_NUMBER'       => 'The phone number format is invalid.',
        // Business enablement
        'DEPOSITS_NOT_ALLOWED'       => 'Deposits are not enabled for this provider on your account.',
        'PAYOUTS_NOT_ALLOWED'        => 'Payouts are not enabled for this provider on your account.',
        'REFUNDS_NOT_ALLOWED'        => 'Refunds are not enabled for this provider on your account.',
        'REMITTANCES_NOT_ALLOWED'    => 'Remittances are not enabled for this provider on your account.',
        // Availability
        'PROVIDER_TEMPORARILY_UNAVAILABLE' => 'The provider is temporarily unavailable. Please try again later.',
        // V1-only names (aliased above, but keep messages for clarity)
        'INVALID_PAYER_FORMAT'       => 'The payer phone number format is invalid.',
        'INVALID_RECIPIENT_FORMAT'   => 'The recipient phone number format is invalid.',
        'INVALID_CORRESPONDENT'      => 'The specified correspondent is not supported.',
        'AMOUNT_TOO_SMALL'           => 'The amount is below the minimum.',
        'AMOUNT_TOO_LARGE'           => 'The amount is above the maximum.',
        'CORRESPONDENT_TEMPORARILY_UNAVAILABLE' => 'The MMO (correspondent) is temporarily unavailable.',
        // Refund-specific V1
        'DEPOSIT_NOT_COMPLETED'      => 'The referenced deposit was not completed.',
        'ALREADY_REFUNDED'           => 'The referenced deposit has already been refunded.',
        'IN_PROGRESS'                => 'Another refund transaction is already in progress.',
        'DEPOSIT_NOT_FOUND'          => 'The referenced deposit was not found.',
        // Refund-specific V2
        'NOT_FOUND'                  => 'The referenced deposit was not found.',
        'INVALID_STATE'              => 'The deposit is not in a refundable state (or already refunded).',
        // Wallet balance (initiation level)
        'PAWAPAY_WALLET_OUT_OF_FUNDS'=> 'Your pawaPay wallet does not have sufficient funds.',
        // Generic
        'UNKNOWN_ERROR'              => 'An unknown error occurred while processing the request.',
    ];

    /**
     * Failure messages (processing-time) — V1 + V2 + Remittances
     */
    private static $failureMessages = [
        // Deposits (V1 & V2)
        'PAYER_NOT_FOUND'            => 'The phone number does not belong to the specified provider.',
        'PAYMENT_NOT_APPROVED'       => 'The customer did not approve the payment.',
        'PAYER_LIMIT_REACHED'        => 'The customer has reached a wallet transaction limit.',
        'PAYMENT_IN_PROGRESS'        => 'The customer already has a payment pending.',
        'INSUFFICIENT_BALANCE'       => 'The customer does not have enough funds.',
        'UNSPECIFIED_FAILURE'        => 'The provider reported a failure without a reason.',
        'UNKNOWN_ERROR'              => 'An unknown error occurred.',
        // V1 alias kept for backward compat
        'TRANSACTION_ALREADY_IN_PROCESS' => 'A previous transaction is still being processed.',
        // Payouts (V1 & V2)
        'PAWAPAY_WALLET_OUT_OF_FUNDS'=> 'Your pawaPay wallet does not have sufficient funds.',
        'BALANCE_INSUFFICIENT'       => 'Your pawaPay wallet does not have sufficient funds.',
        'RECIPIENT_NOT_FOUND'        => 'The phone number does not belong to the specified provider.',
        'RECIPIENT_NOT_ALLOWED_TO_RECEIVE' => 'The recipient is temporarily not allowed to receive funds.',
        'MANUALLY_CANCELLED'         => 'The payout was cancelled while in queue.',
        // Remittances (V2 naming)
        'WALLET_LIMIT_REACHED'       => 'The recipient has reached a wallet limit.',
        // Friendly fallbacks
        'OTHER_ERROR'                => 'An unspecified error occurred while processing the transaction.',
        // Non-standard but used in your app
        'NO CALLBACK'                => 'The transaction is pending. Please check the status again shortly.',
    ];

    /**
     * Status messages & terminality — V1 + V2
     * (We don’t separate per operation here; meanings are consistent.)
     */
    private static $statusMessages = [
        'ACCEPTED'          => 'Accepted for processing.',
        'ENQUEUED'          => 'Accepted and queued for later processing.',
        'SUBMITTED'         => 'Submitted to the provider.',
        'PROCESSING'        => 'Processing with the provider.',
        'IN_RECONCILIATION' => 'Being reconciled to determine final status.',
        'COMPLETED'         => 'Successfully completed.',
        'FAILED'            => 'Processed but failed.',
        // V2 “wrapper” statuses for GET /v2/.../{id}
        'FOUND'             => 'Found.',
        'NOT_FOUND'         => 'Not found.',
        // Some V1 payloads use these text keys within responses
        'REJECTED'          => 'Rejected at initiation.',
        'DUPLICATE_IGNORED' => 'Duplicate of an already accepted request; ignored.',
    ];

    private static $finalStatuses = [
        'COMPLETED' => true,
        'FAILED'    => true,
        // “FOUND/NOT_FOUND” are wrapper statuses, not payment lifecycle; treat as final for the lookup call.
        'FOUND'     => true,
        'NOT_FOUND' => true,
    ];

    /**
     * Public API (backward compatible)
     * Returns a friendly message for a processing failure code.
     */
    public static function getFailureMessage($failureCode): string
    {
        $code = self::normalizeCode($failureCode);
        if (isset(self::$failureMessages[$code])) {
            return self::$failureMessages[$code];
        }
        // Check in rejections too (some callers may mix)
        if (isset(self::$rejectionMessages[$code])) {
            return self::$rejectionMessages[$code];
        }
        return 'An unknown error occurred (Code: ' . $code . '). Please contact support.';
    }

    /**
     * NEW: Friendly message for initiation rejection codes.
     */
    public static function getRejectionMessage($rejectionCode): string
    {
        $code = self::normalizeCode($rejectionCode);
        if (isset(self::$rejectionMessages[$code])) {
            return self::$rejectionMessages[$code];
        }
        // Check in failures too (defensive)
        if (isset(self::$failureMessages[$code])) {
            return self::$failureMessages[$code];
        }
        return 'Your request was rejected (Code: ' . $code . '). Please review the parameters or try again later.';
    }

    /**
     * NEW: Friendly message for a lifecycle/status string (ACCEPTED, COMPLETED, FAILED, etc.)
     */
    public static function getStatusMessage($status): string
    {
        $s = strtoupper(trim((string)$status));
        return self::$statusMessages[$s] ?? $s;
    }

    /**
     * NEW: Whether a status is terminal (no more state changes expected).
     */
    public static function isFinalStatus($status): bool
    {
        $s = strtoupper(trim((string)$status));
        return isset(self::$finalStatuses[$s]);
    }

    /**
     * Normalize incoming code:
     * - uppercase/trim
     * - alias to canonical if known
     */
    private static function normalizeCode($code): string
    {
        $c = strtoupper(trim((string)$code));
        if (isset(self::$aliases[$c])) {
            return self::$aliases[$c];
        }
        return $c;
    }
}