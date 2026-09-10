<?php
/**
 * WMS - Payment provider contract.
 *
 * A provider turns a WMS payment row into a request at a payment gateway and
 * translates the gateway's answer back into WMS vocabulary.  Adding another
 * Tanzanian provider later means writing one class here - nothing else in the
 * system has to change.
 *
 *      Page -> PaymentService -> PaymentProvider -> Gateway
 *
 * Every method returns an array rather than throwing, so a gateway outage
 * shows the customer a clear message instead of a stack trace.
 */
interface PaymentProvider
{
    /** Machine name stored in payments.provider (e.g. "sonicpesa"). */
    public function name(): string;

    /** Human name shown in the UI. */
    public function label(): string;

    /** True when the credentials this provider needs are present. */
    public function isConfigured(): bool;

    /**
     * Starts a charge (for mobile money this triggers the USSD push).
     *
     * @param array $payment amount, currency, reference, payer_name,
     *                       payer_phone, payer_email, description
     * @return array{ok:bool,status:string,provider_ref:?string,message:string,raw:mixed}
     *         status is a WMS status: pending|successful|failed|cancelled
     */
    public function createOrder(array $payment): array;

    /**
     * Polls the gateway for the current state of an order.
     *
     * @return array{ok:bool,status:string,provider_ref:?string,txn_id:?string,channel:?string,message:string,raw:mixed}
     */
    public function checkStatus(string $providerRef): array;

    /** Verifies the signature of an incoming webhook body. */
    public function verifyWebhook(string $rawBody, array $headers): bool;

    /**
     * Normalises a webhook body into the fields WMS stores.
     *
     * @return array{provider_ref:?string,status:string,txn_id:?string,channel:?string,amount:?float,raw:mixed}
     */
    public function parseWebhook(string $rawBody): array;
}
