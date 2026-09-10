<?php
/**
 * WMS - Package form fields (shared by add.php and edit.php).
 * Expects $values, $errors, $isEdit, $profiles.
 */
?>
<div class="grid grid--2-1">
    <div class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('package') ?> <?= $isEdit ? 'Package details' : 'New package' ?></h2>
        </div>
        <div class="card__body">
            <?= provider_selector('provider_id', (string)($values['provider_id'] ?? '')) ?>

            <fieldset class="fieldset">
                <legend>Identity &amp; price</legend>
                <div class="form-grid">
                    <?= field_input(['name' => 'name', 'label' => 'Package name', 'value' => $values['name'], 'required' => true, 'error' => $errors['name'] ?? '', 'placeholder' => 'Daily 5GB']) ?>
                    <?= field_input(['name' => 'code', 'label' => 'Short code', 'value' => $values['code'], 'required' => true, 'error' => $errors['code'] ?? '', 'placeholder' => 'D5GB', 'hint' => 'Used on reports and voucher batches.']) ?>
                    <?= field_input(['name' => 'price', 'type' => 'number', 'label' => 'Price', 'value' => $values['price'], 'required' => true, 'error' => $errors['price'] ?? '', 'prefix' => setting('currency', 'TSh'), 'attrs' => 'min="0" step="any"']) ?>
                    <?= field_select(['name' => 'status', 'label' => 'Status', 'value' => $values['status'], 'options' => ['active' => 'Active', 'inactive' => 'Inactive']]) ?>
                </div>
            </fieldset>

            <fieldset class="fieldset">
                <legend>Time &amp; data</legend>
                <div class="form-grid">
                    <?= field_input(['name' => 'duration_value', 'type' => 'number', 'label' => 'Duration', 'value' => $values['duration_value'], 'required' => true, 'error' => $errors['duration_value'] ?? '', 'attrs' => 'min="1" step="1"']) ?>
                    <?= field_select(['name' => 'duration_unit', 'label' => 'Duration unit', 'value' => $values['duration_unit'], 'options' => WMS_DURATION_UNITS]) ?>
                    <div class="field--full">
                        <?= field_checkbox([
                            'name' => 'unlimited_data', 'label' => 'Unlimited data', 'checked' => (bool)$values['unlimited_data'],
                            'hint' => 'Tick this for time-only packages with no data cap.',
                            'attrs' => 'data-unlimited-toggle',
                        ]) ?>
                    </div>
                    <?= field_input([
                        'name' => 'data_limit_mb', 'type' => 'number', 'label' => 'Data allowance', 'value' => $values['data_limit_mb'],
                        'suffix' => 'MB', 'error' => $errors['data_limit_mb'] ?? '', 'attrs' => 'min="1" step="1" data-data-limit',
                        'hint' => '5 GB = 5120 MB',
                    ]) ?>
                    <?= field_input(['name' => 'device_limit', 'type' => 'number', 'label' => 'Devices allowed', 'value' => $values['device_limit'], 'required' => true, 'error' => $errors['device_limit'] ?? '', 'attrs' => 'min="1" max="64" step="1"', 'hint' => 'Enforced server side when a device connects.']) ?>
                </div>
            </fieldset>

            <fieldset class="fieldset">
                <legend>Speed</legend>
                <div class="form-grid form-grid--3">
                    <?= field_input(['name' => 'download_kbps', 'type' => 'number', 'label' => 'Download', 'value' => $values['download_kbps'], 'required' => true, 'suffix' => 'Kbps', 'error' => $errors['download_kbps'] ?? '', 'attrs' => 'min="64" step="64"']) ?>
                    <?= field_input(['name' => 'upload_kbps', 'type' => 'number', 'label' => 'Upload', 'value' => $values['upload_kbps'], 'required' => true, 'suffix' => 'Kbps', 'error' => $errors['upload_kbps'] ?? '', 'attrs' => 'min="64" step="64"']) ?>
                    <?= field_select([
                        'name' => 'bandwidth_profile_id', 'label' => 'Bandwidth profile', 'value' => (string)$values['bandwidth_profile_id'],
                        'placeholder' => 'None (use speeds above)',
                        'options' => array_column($profiles, 'name', 'id'),
                        'hint' => 'Profiles can be pushed to MikroTik as a rate limit.',
                    ]) ?>
                </div>
            </fieldset>

            <fieldset class="fieldset">
                <legend>Presentation</legend>
                <div class="form-grid">
                    <div class="field--full">
                        <?= field_textarea(['name' => 'description', 'label' => 'Description', 'value' => $values['description'], 'rows' => 2, 'placeholder' => 'Shown to customers on the portal.']) ?>
                    </div>
                    <?= field_input(['name' => 'sort_order', 'type' => 'number', 'label' => 'Sort order', 'value' => $values['sort_order'], 'hint' => 'Lower numbers appear first.']) ?>
                    <?= field_checkbox(['name' => 'is_featured', 'label' => 'Highlight on the portal', 'checked' => (bool)$values['is_featured']]) ?>
                </div>
            </fieldset>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary"><?= icon('check', 'ico--sm') ?> <?= $isEdit ? 'Save package' : 'Create package' ?></button>
                <a class="btn" href="<?= e(url('admin/packages/index.php')) ?>">Cancel</a>
            </div>
        </div>
    </div>

    <!-- Live preview of the card a customer will see. -->
    <div class="card" style="align-self:start">
        <div class="card__head"><h3 class="card__title"><?= icon('eye') ?> Customer sees</h3></div>
        <div class="card__body">
            <article class="price-card<?= $values['is_featured'] ? ' price-card--featured' : '' ?>">
                <div class="price-card__name"><?= e($values['name'] ?: 'Package name') ?></div>
                <div class="price-card__price"><?= e(money((float)($values['price'] ?: 0))) ?></div>
                <ul class="price-card__specs">
                    <li><?= icon('database', 'ico--sm') ?><?= $values['unlimited_data'] ? 'Unlimited data' : e(format_mb((float)($values['data_limit_mb'] ?: 0))) ?></li>
                    <li><?= icon('clock', 'ico--sm') ?><?= e(format_package_duration((int)($values['duration_value'] ?: 1), (string)$values['duration_unit'])) ?></li>
                    <li><?= icon('signal', 'ico--sm') ?><?= e(format_speed((int)($values['download_kbps'] ?: 0))) ?> down</li>
                    <li><?= icon('device', 'ico--sm') ?><?= (int)($values['device_limit'] ?: 1) ?> device<?= (int)($values['device_limit'] ?: 1) === 1 ? '' : 's' ?></li>
                </ul>
                <span class="btn btn--primary btn--block" style="pointer-events:none">Buy this package</span>
            </article>
            <p class="tiny muted mt-2 mb-0">The preview updates when you save. Vouchers keep a copy of these rules, so editing a package never changes access a customer already bought.</p>
        </div>
    </div>
</div>
