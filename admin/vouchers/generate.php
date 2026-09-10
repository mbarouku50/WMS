<?php
/**
 * WMS - Voucher generator.
 */

$requiredPermission = 'manage_vouchers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$service  = new VoucherService();

/*
 * In platform scope there is no implicit tenant, so the picker lists every
 * active package with its owner, and generateBatch() checks that the chosen
 * package and the chosen provider actually match.
 */
$isPlatform = ProviderContext::isGlobalScope();
$packages   = $isPlatform
    ? Database::getInstance()->fetchAll(
        "SELECT p.*, pr.business_name AS provider_name FROM packages p
           JOIN providers pr ON pr.id = p.provider_id
          WHERE p.status = 'active' ORDER BY pr.business_name, p.sort_order")
    : (new Package())->active();
$routers = (new Router())->listAll();

$errors = [];
$values = [
    'package_id'  => query('package_id'),
    'quantity'    => '50',
    'prefix'      => (string)setting('voucher_prefix', 'WMS'),
    'code_length' => (string)setting('voucher_code_length', 8),
    'price'       => '',
    'router_id'   => '',
    'valid_days'  => (string)setting('voucher_validity_days', 90),
    'name'        => '',
    'notes'       => '',
    'provider_id' => '',
];

if (is_post()) {
    CSRF::verify();
    foreach ($values as $key => $default) {
        $values[$key] = post($key, (string)$default);
    }

    $errors = (new Validator($values))
        ->labels(['package_id' => 'Package', 'quantity' => 'Quantity', 'code_length' => 'Code length'])
        ->rules([
            'package_id'  => 'required|integer|min_value:1',
            'quantity'    => 'required|integer|min_value:1|max_value:2000',
            'prefix'      => 'nullable|alpha_num|max:12',
            'code_length' => 'required|integer|min_value:4|max_value:16',
            'price'       => 'nullable|numeric|min_value:0',
            'valid_days'  => 'nullable|integer|min_value:0|max_value:3650',
        ])->errors();

    if (!$errors) {
        $result = $service->generateBatch($values);
        if ($result['ok']) {
            Response::redirect('admin/vouchers/print.php?batch=' . (int)$result['batch_id'], 'success', $result['message']);
        }
        $errors['package_id'] = $result['message'];
    }
}

$pageTitle   = 'Generate vouchers';
$activeNav   = 'vouchers';
$breadcrumbs = [['label' => 'Vouchers', 'url' => 'admin/vouchers/index.php'], ['label' => 'Generate']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head('Generate vouchers', 'Create a batch of unique codes ready to print and sell',
    '<a class="btn" href="' . e(url('admin/vouchers/batches.php')) . '">' . icon('clipboard', 'ico--sm') . ' Past batches</a>'
) ?>

<?php if ($errors): ?><?= alert_box('danger', $errors['package_id'] ?? 'Please correct the highlighted fields.') ?><?php endif; ?>

<?php if (!$packages): ?>
    <?= alert_box('warning', 'You need at least one active package before you can generate vouchers.', 'No packages yet') ?>
    <a class="btn btn--primary" href="<?= e(url('admin/packages/add.php')) ?>"><?= icon('plus', 'ico--sm') ?> Create a package</a>
<?php else: ?>

<form method="post" action="" novalidate>
    <?= CSRF::field() ?>
    <div class="grid grid--2-1">
        <div class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('ticket') ?> Batch settings</h2></div>
            <div class="card__body">
                <?= provider_selector('provider_id', $values['provider_id']) ?>

                <fieldset class="fieldset">
                    <legend>What is being sold</legend>
                    <div class="form-grid">
                        <?= field_select([
                            'name' => 'package_id', 'label' => 'Package', 'required' => true,
                            'value' => $values['package_id'], 'placeholder' => 'Choose a package',
                            'options' => array_reduce($packages, static function ($carry, $p) use ($isPlatform) {
                                $label = $p['name'] . ' — ' . money((float)$p['price']) . ' · ' . Package::summary($p);
                                if ($isPlatform && !empty($p['provider_name'])) {
                                    $label = $p['provider_name'] . ' · ' . $label;
                                }
                                $carry[$p['id']] = $label;
                                return $carry;
                            }, []),
                            'error' => $errors['package_id'] ?? '',
                        ]) ?>
                        <?= field_input([
                            'name' => 'price', 'type' => 'number', 'label' => 'Selling price', 'value' => $values['price'],
                            'prefix' => setting('currency', 'TSh'), 'attrs' => 'min="0" step="any"',
                            'hint' => 'Leave blank to use the package price.', 'error' => $errors['price'] ?? '',
                        ]) ?>
                        <?= field_input(['name' => 'quantity', 'type' => 'number', 'label' => 'How many vouchers', 'value' => $values['quantity'], 'required' => true, 'attrs' => 'min="1" max="2000" step="1"', 'error' => $errors['quantity'] ?? '', 'hint' => 'Up to 2,000 in one batch.']) ?>
                        <?= field_select([
                            'name' => 'router_id', 'label' => 'Assign to router', 'value' => $values['router_id'],
                            'placeholder' => 'Any router',
                            'options' => array_column($routers, 'name', 'id'),
                            'hint' => 'Optional. Restricts where the voucher may be used.',
                        ]) ?>
                    </div>
                </fieldset>

                <fieldset class="fieldset">
                    <legend>Code format</legend>
                    <div class="form-grid">
                        <?= field_input(['name' => 'prefix', 'label' => 'Prefix', 'value' => $values['prefix'], 'placeholder' => 'WMS', 'error' => $errors['prefix'] ?? '', 'hint' => 'Letters and numbers only. Leave blank for none.']) ?>
                        <?= field_input(['name' => 'code_length', 'type' => 'number', 'label' => 'Total code length', 'value' => $values['code_length'], 'required' => true, 'attrs' => 'min="4" max="16" step="1"', 'error' => $errors['code_length'] ?? '']) ?>
                        <?= field_input(['name' => 'valid_days', 'type' => 'number', 'label' => 'Must be activated within', 'value' => $values['valid_days'], 'suffix' => 'days', 'attrs' => 'min="0" step="1"', 'hint' => '0 means no activation deadline.']) ?>
                        <?= field_input(['name' => 'name', 'label' => 'Batch name', 'value' => $values['name'], 'placeholder' => 'Kariakoo counter, September']) ?>
                        <div class="field--full">
                            <?= field_textarea(['name' => 'notes', 'label' => 'Notes', 'value' => $values['notes'], 'rows' => 2, 'placeholder' => 'Who is this batch for? Where will it be sold?']) ?>
                        </div>
                    </div>
                </fieldset>

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary"><?= icon('ticket', 'ico--sm') ?> Generate <span data-voucher-count><?= (int)$values['quantity'] ?></span> vouchers</button>
                    <a class="btn" href="<?= e(url('admin/vouchers/index.php')) ?>">Cancel</a>
                </div>
            </div>
        </div>

        <div style="align-self:start">
            <div class="card mb-2">
                <div class="card__head"><h3 class="card__title"><?= icon('eye') ?> Code preview</h3></div>
                <div class="card__body text-center">
                    <div class="eyebrow mb-1">Example code</div>
                    <div class="mono" data-voucher-preview style="font-size:1.5rem;font-weight:700;letter-spacing:.12em;color:var(--wms-ink)">WMS-XXXXXX</div>
                    <p class="tiny muted mt-2 mb-0">
                        Codes use an unambiguous alphabet - no O/0 or I/1 mix-ups when a customer reads a
                        printed card. Every code is checked for uniqueness before it is saved.
                    </p>
                </div>
            </div>

            <div class="card">
                <div class="card__head"><h3 class="card__title"><?= icon('info') ?> What happens next</h3></div>
                <div class="card__body">
                    <ul class="timeline">
                        <li class="is-success"><b>Codes are created</b><div class="muted">Each one keeps a copy of the package rules.</div></li>
                        <li><b>Print the batch</b><div class="muted">You go straight to a print-ready sheet of cards.</div></li>
                        <li><b>Customer activates</b><div class="muted">Entering the code on the portal starts the clock.</div></li>
                        <li><b>Access is granted</b><div class="muted"><?= demo_mode() ? 'In demo mode nothing is pushed to hardware.' : 'A hotspot user is created on the router.' ?></div></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</form>

<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
