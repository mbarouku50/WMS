<?php
/**
 * GET api/packages/index.php
 *
 * The public price list - the one endpoint that needs no session, because a
 * customer must be able to read it before they have paid for anything.
 */

require_once dirname(__DIR__) . '/_bootstrap.php';

api_method('GET');

api_run(static function (): void {
    /*
     * Which provider's price list is this? Signed-in staff get their own;
     * a portal visitor gets the provider their network resolved to. If the
     * tenant cannot be decided, we return nothing rather than everything -
     * showing one provider's prices to another's customers would be worse
     * than an empty list.
     */
    $providerId = ProviderContext::isGlobalScope()
        ? (api_int('provider_id') ?: null)
        : ProviderContext::resolvePortalProvider();

    if ($providerId === null && !ProviderContext::isGlobalScope()) {
        Response::error('This network could not be identified. Open the portal from your Wi-Fi connection and try again.', 409);
    }

    $rows = $providerId === null
        ? (new Package())->active()
        : (new Package())->activeForProvider($providerId);

    $packages = array_map(static fn($p) => [
        'id'            => (int)$p['id'],
        'name'          => $p['name'],
        'code'          => $p['code'],
        'price'         => (float)$p['price'],
        'currency'      => (string)setting('currency_code', 'TZS'),
        'duration'      => format_package_duration((int)$p['duration_value'], $p['duration_unit']),
        'data_limit_mb' => $p['data_limit_mb'] === null ? null : (int)$p['data_limit_mb'],
        'download_kbps' => (int)$p['download_kbps'],
        'upload_kbps'   => (int)$p['upload_kbps'],
        'device_limit'  => (int)$p['device_limit'],
        'description'   => $p['description'],
        'featured'      => (bool)$p['is_featured'],
    ], $rows);

    Response::success($packages, count($packages) . ' package(s) on sale.');
});
