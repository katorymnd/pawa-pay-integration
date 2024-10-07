<?php

namespace Katorymnd\PawaPayIntegration\Utils;

class FailureCodeHelper
{
    // Map failure codes to user-friendly messages
    private static $failureCodeMessages = [
    // Transaction failure codes
    'PAYER_LIMIT_REACHED' => 'You have reached a transaction limit on your wallet.',
    'PAYER_NOT_FOUND' => 'The phone number entered is not registered.',
    'PAYMENT_NOT_APPROVED' => 'The payment was not approved.',
    'INSUFFICIENT_BALANCE' => 'You do not have enough funds to complete this transaction.',
    'TRANSACTION_ALREADY_IN_PROCESS' => 'A transaction is already in process. Please wait a few minutes and try again.',
    'BALANCE_INSUFFICIENT' => 'The merchant does not have sufficient balance to process the payout.',
    'RECIPIENT_NOT_FOUND' => 'The recipient phone number is not registered.',
    'RECIPIENT_NOT_ALLOWED_TO_RECEIVE' => 'The recipient is not allowed to receive payments.',
    'MANUALLY_CANCELLED' => 'The transaction was cancelled.',
    'OTHER_ERROR' => 'An unknown error occurred. Please try again later.',
    'NO CALLBACK' => 'The transaction is pending. Please wait a few minutes and check again.',

    // Technical failure codes
    'INVALID_RECIPIENT_FORMAT' => 'The recipient format is incorrect. Please check the phone number.',
    'INVALID_PAYER_FORMAT' => 'The payer format is incorrect. Please check the phone number.',
    'INVALID_CURRENCY' => 'The selected currency is not supported.',
    'INVALID_AMOUNT' => 'The amount format is invalid.',
    'INVALID_COUNTRY' => 'The specified country is not supported by this service.',
    'AMOUNT_TOO_SMALL' => 'The amount is too small to process.',
    'AMOUNT_TOO_LARGE' => 'The amount exceeds the allowed limit.',
    'PARAMETER_INVALID' => 'An invalid parameter was found in the request.',
    'DEPOSITS_NOT_ALLOWED' => 'Deposits are not allowed for this mobile operator.',
    'PAYOUTS_NOT_ALLOWED' => 'Payouts are not allowed for this mobile operator.',
    'AUTHENTICATION_ERROR' => 'Authentication failed. Please contact support for assistance.',
    'INVALID_INPUT' => 'The input data is invalid and cannot be processed.',
    'CORRESPONDENT_TEMPORARILY_UNAVAILABLE' => 'The mobile network operator is currently unavailable. Please try again later.',

    // Add other failure codes as necessary
    ];


    public static function getFailureMessage($failureCode)
    {
        if (isset(self::$failureCodeMessages[$failureCode])) {
            return self::$failureCodeMessages[$failureCode];
        } else {
            return 'An unknown error occurred (Code: ' . $failureCode . '). Please contact support.';
        }
    }
}