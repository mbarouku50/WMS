<?php
/**
 * WMS - System settings.
 *
 * Secrets (payment keys) are write-only from this screen: the stored value is
 * shown masked and is only replaced when a new value is typed.
 */

/*
 * Platform settings and provider settings live on the same screen but are
 * genuinely different things:
 *
 *   Platform - demo mode, router poll intervals, system-wide defaults.
 *              Only a platform administrator can change these.
 *   Provider - business name, logo, currency, voucher format, payment keys.
 *              Each provider keeps their own, overriding the platform value.
 *
 * Setting::set() writes into whichever scope the caller is in, and refuses
 * platform-only keys from a provider, so this page cannot leak across.
 */
// Load the bootstrap first: which permission this page needs depends on
// whether the caller is a platform administrator or a provider.
require_once __DIR__ . '/../../config/config.php';

$requiredPermission = ProviderContext::isGlobalScope() ? 'manage_settings' : 'manage_provider_settings';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$isPlatform = ProviderContext::isGlobalScope();

$tab = query('tab', 'general');
$allowedTabs = ['general' => 'General', 'voucher' => 'Vouchers', 'network' => 'Network', 'payment' => 'Payments', 'notification' => 'Alerts'];
if (!isset($allowedTabs[$tab])) {
    $tab = 'general';
}

if (is_post()) {
    CSRF::verify();
    $action = post('action');

    if ($action === 'test_payment') {
        $provider = new SonicPesaProvider();
        if (!$provider->isConfigured()) {
            Response::back('warning', 'Add your SonicPesa access key first, then test again.');
        }
        $result = $provider->listTransactions();
        Response::back($result['ok'] ? 'success' : 'error',
            $result['ok']
                ? 'SonicPesa answered. ' . count($result['data']) . ' transaction(s) visible to this key.'
                : 'SonicPesa did not accept the request: ' . $result['message']);
    }

    if ($action === 'save') {
        $group = post('group', 'general');

        /* Text and numeric settings, per tab. */
        $plainKeys = match ($group) {
            'general'      => ['app_name', 'company_name', 'company_tagline', 'support_phone', 'support_email', 'timezone', 'currency', 'currency_code', 'records_per_page'],
            'voucher'      => ['voucher_prefix', 'voucher_code_length', 'voucher_charset', 'voucher_validity_days', 'voucher_print_note'],
            'network'      => ['portal_ssid', 'portal_welcome', 'router_poll_seconds', 'session_idle_timeout', 'router_timeout_seconds'],
            'payment'      => ['payment_provider', 'sonicpesa_base_url', 'payment_currency', 'sonicpesa_buyer_email'],
            'notification' => ['alert_high_usage_gb'],
            default        => [],
        };
        $boolKeys = match ($group) {
            'general'      => ['demo_mode'],
            'payment'      => ['sonicpesa_use_simple'],
            'notification' => ['alert_router_offline', 'alert_payment_failed'],
            default        => [],
        };
        $secretKeys = $group === 'payment' ? ['sonicpesa_api_key', 'sonicpesa_api_secret'] : [];

        // Setting::set() already refuses platform-only keys from a provider,
        // but filtering here keeps the intent obvious at the call site too.
        if (!$isPlatform) {
            $plainKeys = array_values(array_diff($plainKeys, Setting::PLATFORM_ONLY_KEYS));
            $boolKeys  = array_values(array_diff($boolKeys, Setting::PLATFORM_ONLY_KEYS));
        }

        foreach ($plainKeys as $key) {
            if (array_key_exists($key, $_POST)) {
                Setting::set($key, Validator::string($_POST[$key], 500), $group);
            }
        }
        foreach ($boolKeys as $key) {
            Setting::set($key, Validator::bool($_POST[$key] ?? '0') ? '1' : '0', $group, 'bool');
        }
        foreach ($secretKeys as $key) {
            $value = trim((string)($_POST[$key] ?? ''));
            // An empty box means "leave the stored secret alone".
            if ($value !== '') {
                Setting::set($key, $value, $group, 'secret');
            }
        }

        /* Logo upload. */
        if ($group === 'general' && !empty($_FILES['logo']['tmp_name'])) {
            $file = $_FILES['logo'];
            $info = @getimagesize($file['tmp_name']);
            $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            if (!$info || !isset($allowed[$info['mime']])) {
                Response::back('error', 'The logo must be a PNG, JPG, GIF or WebP image.');
            }
            if ($file['size'] > 2 * 1024 * 1024) {
                Response::back('error', 'The logo must be smaller than 2 MB.');
            }
            $name = 'logo-' . date('YmdHis') . '.' . $allowed[$info['mime']];
            if (@move_uploaded_file($file['tmp_name'], UPLOADS_PATH . '/logos/' . $name)) {
                Setting::set('logo_path', 'uploads/logos/' . $name, 'general');
                // Keep the provider record's own logo in step.
                if (!$isPlatform) {
                    (new Provider())->reassignProviderLogo(ProviderContext::requireProvider(), 'uploads/logos/' . $name);
                }
            } else {
                Response::back('error', 'The logo could not be saved. Check that uploads/logos is writable.');
            }
        }

        Setting::flush();
        AuditLog::record('settings_update', 'settings', null,
            'Updated ' . $group . ' settings for ' . ($isPlatform ? 'the platform' : ProviderContext::scopeLabel()));
        Response::redirect('admin/settings/index.php?tab=' . $group, 'success', ucfirst($group) . ' settings saved.');
    }

    Response::back('error', 'That action is not supported.');
}

$pageTitle    = $isPlatform ? 'Platform settings' : 'Settings';
$pageSubtitle = $isPlatform
    ? 'Defaults for the whole installation. Providers can override the ones marked as theirs.'
    : 'How ' . ProviderContext::scopeLabel() . ' works. These apply to your business only.';
$activeNav    = 'settings';
$breadcrumbs  = [['label' => 'Settings'], ['label' => $allowedTabs[$tab]]];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle) ?>

<?php if ($isPlatform): ?>
    <?= alert_box('info', 'You are editing the platform defaults. A provider that has set its own value keeps it - these apply where a provider has not overridden them.') ?>
<?php else: ?>
    <?= alert_box('info', 'These settings belong to ' . ProviderContext::scopeLabel() . ' alone. System-wide options such as demo mode and router polling are set by the platform administrator.') ?>
<?php endif; ?>

<div class="tabs mb-3">
    <?php foreach ($allowedTabs as $key => $label): ?>
        <a class="tab<?= $tab === $key ? ' is-active' : '' ?>" href="<?= e(url('admin/settings/index.php?tab=' . $key)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<form method="post" action="" enctype="multipart/form-data" style="max-width:940px">
    <?= CSRF::field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="group" value="<?= e($tab) ?>">

    <?php if ($tab === 'general'): ?>
        <div class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('building') ?> Identity</h2></div>
            <div class="card__body">
                <div class="form-grid">
                    <?= field_input(['name' => 'app_name', 'label' => 'System name', 'value' => (string)setting('app_name', 'WMS'), 'required' => true]) ?>
                    <?= field_input(['name' => 'company_name', 'label' => 'Company name', 'value' => (string)setting('company_name', '')]) ?>
                    <div class="field--full">
                        <?= field_input(['name' => 'company_tagline', 'label' => 'Tagline', 'value' => (string)setting('company_tagline', ''), 'hint' => 'Shown on the public landing page footer.']) ?>
                    </div>
                    <?= field_input(['name' => 'support_phone', 'label' => 'Support phone', 'value' => (string)setting('support_phone', '')]) ?>
                    <?= field_input(['name' => 'support_email', 'type' => 'email', 'label' => 'Support email', 'value' => (string)setting('support_email', '')]) ?>
                </div>

                <div class="divider-label">Logo</div>
                <div class="flex items-center gap-2 flex-wrap">
                    <?php $logo = (string)setting('logo_path', ''); ?>
                    <?php if ($logo && is_file(WMS_ROOT . '/' . $logo)): ?>
                        <img src="<?= e(url($logo)) ?>" alt="Current logo" style="max-height:52px;border:1px solid var(--wms-border);border-radius:var(--wms-radius-sm);padding:4px;background:#fff">
                    <?php else: ?>
                        <span class="brand-mark brand-mark--lg"><?= icon('wifi', 'ico--lg') ?></span>
                    <?php endif; ?>
                    <div class="flex-1" style="min-width:220px">
                        <input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" class="input">
                        <div class="field__hint">PNG, JPG, GIF or WebP, up to 2 MB.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('globe') ?> Locale &amp; behaviour</h2></div>
            <div class="card__body">
                <div class="form-grid">
                    <?= field_select(['name' => 'timezone', 'label' => 'Timezone', 'value' => (string)setting('timezone', 'Africa/Dar_es_Salaam'),
                        'options' => array_combine(
                            ['Africa/Dar_es_Salaam', 'Africa/Nairobi', 'Africa/Kampala', 'Africa/Kigali', 'Africa/Lusaka', 'Africa/Johannesburg', 'UTC'],
                            ['Africa/Dar_es_Salaam', 'Africa/Nairobi', 'Africa/Kampala', 'Africa/Kigali', 'Africa/Lusaka', 'Africa/Johannesburg', 'UTC']
                        )]) ?>
                    <?= field_input(['name' => 'currency', 'label' => 'Currency symbol', 'value' => (string)setting('currency', 'TSh')]) ?>
                    <?= field_input(['name' => 'currency_code', 'label' => 'Currency code', 'value' => (string)setting('currency_code', 'TZS'), 'hint' => 'Sent to the payment provider, e.g. TZS.']) ?>
                    <?= field_input(['name' => 'records_per_page', 'type' => 'number', 'label' => 'Rows per page', 'value' => (string)setting('records_per_page', 20), 'attrs' => 'min="5" max="200"']) ?>
                </div>

                <?php if ($isPlatform): ?>
                    <?= field_checkbox(['name' => 'demo_mode', 'label' => 'Demo mode', 'checked' => demo_mode(),
                        'hint' => 'Platform-wide. On: no router is contacted, and simulated network rows are clearly labelled "Demo". Off: routers set to Live mode are polled for real.']) ?>
                <?php else: ?>
                    <div class="field">
                        <div class="field__label">Demo mode</div>
                        <p class="small muted mb-0">
                            <?= demo_mode() ? 'On' : 'Off' ?> — set by the platform administrator for the whole installation.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card__foot">
                <button type="submit" class="btn btn--primary"><?= icon('check', 'ico--sm') ?> Save general settings</button>
            </div>
        </div>

    <?php elseif ($tab === 'voucher'): ?>
        <div class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('ticket') ?> Voucher defaults</h2></div>
            <div class="card__body">
                <div class="form-grid">
                    <?= field_input(['name' => 'voucher_prefix', 'label' => 'Default prefix', 'value' => (string)setting('voucher_prefix', 'WMS')]) ?>
                    <?= field_input(['name' => 'voucher_code_length', 'type' => 'number', 'label' => 'Default code length', 'value' => (string)setting('voucher_code_length', 8), 'attrs' => 'min="4" max="16"']) ?>
                    <div class="field--full">
                        <?= field_input(['name' => 'voucher_charset', 'label' => 'Character set', 'value' => (string)setting('voucher_charset', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'),
                            'class' => 'input--mono', 'hint' => 'Leave out easily confused characters such as O/0 and I/1.']) ?>
                    </div>
                    <?= field_input(['name' => 'voucher_validity_days', 'type' => 'number', 'label' => 'Activation deadline', 'value' => (string)setting('voucher_validity_days', 90), 'suffix' => 'days', 'hint' => '0 means a voucher never goes stale before use.']) ?>
                    <div class="field--full">
                        <?= field_textarea(['name' => 'voucher_print_note', 'label' => 'Printed instructions', 'value' => (string)setting('voucher_print_note', ''), 'rows' => 2]) ?>
                    </div>
                </div>

                <div class="divider-label">Card preview</div>
                <div class="voucher-grid" style="max-width:260px">
                    <?= voucher_card(['code' => setting('voucher_prefix', 'WMS') . '-4KJ8P2', 'price' => 1000, 'data_limit_mb' => 5120,
                        'duration_value' => 24, 'duration_unit' => 'hours', 'download_kbps' => 5120]) ?>
                </div>
            </div>
            <div class="card__foot">
                <button type="submit" class="btn btn--primary"><?= icon('check', 'ico--sm') ?> Save voucher settings</button>
            </div>
        </div>

    <?php elseif ($tab === 'network'): ?>
        <div class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('router') ?> Network &amp; portal</h2></div>
            <div class="card__body">
                <?= demo_banner('network') ?>
                <div class="form-grid">
                    <?= field_input(['name' => 'portal_ssid', 'label' => 'Hotspot SSID', 'value' => (string)setting('portal_ssid', 'WMS-Hotspot'), 'hint' => 'Shown on the portal and on printed vouchers.']) ?>
                    <?php if ($isPlatform): ?>
                        <?= field_input(['name' => 'router_poll_seconds', 'type' => 'number', 'label' => 'Router poll interval', 'value' => (string)setting('router_poll_seconds', 60), 'suffix' => 'seconds', 'attrs' => 'min="15"']) ?>
                        <?= field_input(['name' => 'router_timeout_seconds', 'type' => 'number', 'label' => 'Router API timeout', 'value' => (string)setting('router_timeout_seconds', 5), 'suffix' => 'seconds', 'attrs' => 'min="2" max="20"', 'hint' => 'How long to wait before declaring a router unreachable.']) ?>
                    <?php endif; ?>
                    <?= field_input(['name' => 'session_idle_timeout', 'type' => 'number', 'label' => 'Session idle timeout', 'value' => (string)setting('session_idle_timeout', 900), 'suffix' => 'seconds', 'attrs' => 'min="60"']) ?>
                    <div class="field--full">
                        <?= field_input(['name' => 'portal_welcome', 'label' => 'Portal welcome message', 'value' => (string)setting('portal_welcome', '')]) ?>
                    </div>
                </div>
            </div>
            <div class="card__foot">
                <button type="submit" class="btn btn--primary"><?= icon('check', 'ico--sm') ?> Save network settings</button>
            </div>
        </div>

    <?php elseif ($tab === 'payment'): ?>
        <div class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('card') ?> Payment provider</h2></div>
            <div class="card__body">
                <?= alert_box('info', 'Keys are stored on the server and never sent to the browser. The boxes below show a mask - type a new value only when you want to replace what is stored.') ?>

                <div class="form-grid">
                    <?= field_select(['name' => 'payment_provider', 'label' => 'Active provider', 'value' => (string)setting('payment_provider', 'demo'),
                        'options' => (new PaymentService())->availableProviders(),
                        'hint' => 'The demo provider settles automatically and moves no money.']) ?>
                    <?= field_input(['name' => 'payment_currency', 'label' => 'Provider currency', 'value' => (string)setting('payment_currency', 'TZS')]) ?>
                    <div class="field--full">
                        <?= field_input(['name' => 'sonicpesa_base_url', 'label' => 'SonicPesa base URL', 'value' => (string)setting('sonicpesa_base_url', 'https://api.sonicpesa.com/api/v1')]) ?>
                    </div>
                    <?= field_input(['name' => 'sonicpesa_api_key', 'label' => 'Access key (X-API-KEY)', 'value' => '',
                        'placeholder' => mask_secret((string)setting('sonicpesa_api_key', '')), 'attrs' => 'autocomplete="off"',
                        'hint' => Setting::hasSecret('sonicpesa_api_key') ? 'A key is stored. Leave blank to keep it.' : 'No key stored yet.']) ?>
                    <?= field_input(['name' => 'sonicpesa_api_secret', 'type' => 'password', 'label' => 'Secret key (X-API-SECRET)', 'value' => '',
                        'placeholder' => mask_secret((string)setting('sonicpesa_api_secret', '')), 'attrs' => 'autocomplete="new-password"',
                        'hint' => 'Used to verify webhooks and to authorise payouts.']) ?>
                    <div class="field--full">
                        <?= field_input(['name' => 'sonicpesa_buyer_email', 'type' => 'email', 'label' => 'Buyer email fallback',
                            'value' => (string)setting('sonicpesa_buyer_email', ''), 'placeholder' => 'billing@yourdomain.co.tz',
                            'hint' => 'SonicPesa rejects an order whose buyer_email is not a valid address. This one is used when the payer has no email on file - leave it blank only if this WMS is served from a real domain.']) ?>
                    </div>
                </div>

                <?= field_checkbox(['name' => 'sonicpesa_use_simple', 'label' => 'Use the long-polling endpoint', 'checked' => (bool)setting('sonicpesa_use_simple', false),
                    'hint' => 'create_order_simple waits up to 45 seconds for the customer to approve. Leave off to return immediately and poll instead - kinder to shared hosting.']) ?>

                <div class="divider-label">Webhook</div>
                <p class="small muted">Set this URL in your SonicPesa API settings so payments confirm even if the customer closes the page:</p>
                <?= code_chip(rtrim(APP_URL, '/') . '/api/payments/webhook.php', true) ?>
            </div>
            <div class="card__foot flex justify-between flex-wrap gap-1">
                <button type="submit" class="btn btn--primary"><?= icon('check', 'ico--sm') ?> Save payment settings</button>
                <button type="submit" class="btn" name="action" value="test_payment" formnovalidate><?= icon('link', 'ico--sm') ?> Test the connection</button>
            </div>
        </div>

    <?php else: ?>
        <div class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('bell') ?> Alert rules</h2></div>
            <div class="card__body">
                <?= field_checkbox(['name' => 'alert_router_offline', 'label' => 'Raise an alert when a router stops answering', 'checked' => (bool)setting('alert_router_offline', true)]) ?>
                <?= field_checkbox(['name' => 'alert_payment_failed', 'label' => 'Raise an alert when a payment fails', 'checked' => (bool)setting('alert_payment_failed', true)]) ?>
                <div class="form-grid">
                    <?= field_input(['name' => 'alert_high_usage_gb', 'type' => 'number', 'label' => 'High usage threshold', 'value' => (string)setting('alert_high_usage_gb', 8), 'suffix' => 'GB / day',
                        'hint' => 'A customer crossing this in one day is flagged for review.']) ?>
                </div>
            </div>
            <div class="card__foot">
                <button type="submit" class="btn btn--primary"><?= icon('check', 'ico--sm') ?> Save alert settings</button>
            </div>
        </div>
    <?php endif; ?>
</form>

<?php if ($isPlatform): ?>
<section class="card mt-3" style="max-width:940px">
    <div class="card__head"><h2 class="card__title"><?= icon('info') ?> Installation</h2></div>
    <div class="card__body">
        <?= key_value([
            'WMS version'   => e(WMS_VERSION),
            'PHP version'   => e(PHP_VERSION),
            'Environment'   => e(ENVIRONMENT),
            'Application URL' => code_chip(rtrim(APP_URL, '/')),
            'Base path'     => code_chip(BASE_PATH),
            'Timezone'      => e(date_default_timezone_get()) . ' · ' . e(date('d M Y H:i')),
            'Mode'          => demo_mode() ? '<span class="badge badge--demo">Demo</span>' : '<span class="badge badge--live">Live</span>',
            'Installer'     => is_file(WMS_ROOT . '/install.php')
                ? '<span class="badge badge--warning">install.php is still present - delete it</span>'
                : '<span class="badge badge--success">Removed</span>',
        ]) ?>
    </div>
</section>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
