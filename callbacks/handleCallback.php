<?php

namespace Katorymnd\PawaPayIntegration\Callbacks;

class HandleCallback
{
    public function processCallback()
    {
        // Read the incoming webhook payload
        $data = json_decode(file_get_contents('php://input'), true);

        // Check if the payload is valid
        if (!$data || !isset($data['status'])) {
            $this->logError('Invalid callback received.');
            http_response_code(400); // Bad request
            return;
        }

        // Process based on status
        switch ($data['status']) {
            case 'COMPLETED':
                $this->handleCompleted($data);
                break;
            case 'FAILED':
                $this->handleFailed($data);
                break;
            default:
                $this->logError('Unknown status received', $data);
                break;
        }

        http_response_code(200); // OK response
    }

    private function handleCompleted($data)
    {
        // Assuming 'transaction_id' and 'amount' are part of the data
        $logMessage = "Payment completed for transaction {$data['transaction_id']} amount {$data['amount']}.";
        $this->logInfo($logMessage);
        file_put_contents('logs/payment_success.log', print_r($data, true));
    }

    private function handleFailed($data)
    {
        $logMessage = "Payment failed for transaction {$data['transaction_id']} with error: {$data['error']}.";
        $this->logInfo($logMessage);
        file_put_contents('logs/payment_failed.log', print_r($data, true));
    }

    private function logInfo($message)
    {
        // Add additional logging mechanism or integration
        file_put_contents('logs/general.log', $message . PHP_EOL, FILE_APPEND);
    }

    private function logError($message, $data = [])
    {
        // Log errors in a specific error log
        file_put_contents('logs/error.log', $message . ' - ' . print_r($data, true) . PHP_EOL, FILE_APPEND);
    }
}

// Instantiate and process the callback
$callbackHandler = new HandleCallback();
$callbackHandler->processCallback();