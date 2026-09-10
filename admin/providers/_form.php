<?php
/**
 * WMS - Provider form fields (shared by add.php and edit.php).
 * Expects $values, $errors, $isEdit.
 */
?>
<div class="card">
    <div class="card__head">
        <h2 class="card__title"><?= icon('building') ?> <?= $isEdit ? 'Business details' : 'New Wi-Fi provider' ?></h2>
        <?php if ($isEdit): ?><span class="code-chip"><?= e($values['provider_code']) ?></span><?php endif; ?>
    </div>
    <div class="card__body">
        <fieldset class="fieldset">
            <legend>Business</legend>
            <div class="form-grid">
                <?= field_input(['name' => 'business_name', 'label' => 'Business name', 'value' => $values['business_name'], 'required' => true, 'error' => $errors['business_name'] ?? '', 'placeholder' => 'Mbaruku WiFi']) ?>
                <?= field_input(['name' => 'provider_code', 'label' => 'Provider code', 'value' => $values['provider_code'], 'error' => $errors['provider_code'] ?? '', 'hint' => 'Leave blank to generate the next one automatically.', 'class' => 'input--mono']) ?>
                <?= field_select(['name' => 'business_type', 'label' => 'Business type', 'value' => $values['business_type'], 'options' => Provider::BUSINESS_TYPES]) ?>
                <?= field_select(['name' => 'status', 'label' => 'Status', 'value' => $values['status'], 'options' => Provider::STATUSES,
                    'hint' => 'A suspended provider keeps all its data but cannot sign in.']) ?>
                <div class="field--full">
                    <?= field_textarea(['name' => 'description', 'label' => 'Description', 'value' => $values['description'], 'rows' => 2, 'placeholder' => 'What does this business do, and where?']) ?>
                </div>
            </div>
        </fieldset>

        <fieldset class="fieldset">
            <legend>Contact</legend>
            <div class="form-grid">
                <?= field_input(['name' => 'owner_name', 'label' => 'Owner / contact', 'value' => $values['owner_name'], 'error' => $errors['owner_name'] ?? '']) ?>
                <?= field_input(['name' => 'phone', 'label' => 'Phone', 'value' => $values['phone'], 'error' => $errors['phone'] ?? '', 'placeholder' => '0754 000 000']) ?>
                <?= field_input(['name' => 'email', 'type' => 'email', 'label' => 'Email', 'value' => $values['email'], 'error' => $errors['email'] ?? '']) ?>
                <?= field_input(['name' => 'address', 'label' => 'Address', 'value' => $values['address']]) ?>
                <?= field_input(['name' => 'city', 'label' => 'City', 'value' => $values['city'], 'placeholder' => 'Dar es Salaam']) ?>
                <?= field_input(['name' => 'region', 'label' => 'Region', 'value' => $values['region']]) ?>
                <?= field_input(['name' => 'country', 'label' => 'Country', 'value' => $values['country']]) ?>
            </div>
        </fieldset>

        <fieldset class="fieldset">
            <legend>Locale &amp; branding</legend>
            <div class="form-grid">
                <?= field_select(['name' => 'timezone', 'label' => 'Timezone', 'value' => $values['timezone'],
                    'options' => array_combine(
                        ['Africa/Dar_es_Salaam', 'Africa/Nairobi', 'Africa/Kampala', 'Africa/Kigali', 'Africa/Lusaka', 'UTC'],
                        ['Africa/Dar_es_Salaam', 'Africa/Nairobi', 'Africa/Kampala', 'Africa/Kigali', 'Africa/Lusaka', 'UTC']
                    )]) ?>
                <?= field_input(['name' => 'currency', 'label' => 'Currency symbol', 'value' => $values['currency']]) ?>
                <?= field_input(['name' => 'currency_code', 'label' => 'Currency code', 'value' => $values['currency_code'], 'hint' => 'Sent to the payment gateway, e.g. TZS.']) ?>
                <div class="field">
                    <label class="field__label" for="f_logo">Logo</label>
                    <input type="file" id="f_logo" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" class="input">
                    <div class="field__hint">Shown on this provider's portal and printed vouchers. PNG, JPG, GIF or WebP up to 2&nbsp;MB.</div>
                </div>
            </div>
        </fieldset>

        <fieldset class="fieldset">
            <legend>How they may sell</legend>
            <p class="small muted mt-0">
                Every provider can always sell prepaid <b>vouchers</b>. Taking mobile money directly
                from customers is a separate permission, because that money lands in your merchant
                account and is settled to them through their wallet.
            </p>
            <?= field_checkbox([
                'name'    => 'mobile_money_enabled',
                'label'   => 'Allow this provider to sell packages by mobile money',
                'checked' => (bool)($values['mobile_money_enabled'] ?? false),
                'hint'    => 'Off by default. When off, the Buy option is hidden from their portal and any purchase attempt is refused on the server. You can change this at any time.',
            ]) ?>
        </fieldset>

        <fieldset class="fieldset">
            <legend>Platform fee</legend>
            <p class="small muted mt-0">
                What this provider pays you, and when it starts. Set the grace period you agreed —
                for example &ldquo;you start paying two months from today&rdquo;.
            </p>
            <div class="form-grid">
                <?= field_input([
                    'name'   => 'platform_fee_amount',
                    'type'   => 'number',
                    'label'  => 'Fee per cycle',
                    'value'  => (string)($values['platform_fee_amount'] ?? setting('platform_fee_default', 10000)),
                    'prefix' => setting('currency', 'TSh'),
                    'attrs'  => 'min="0" step="any"',
                    'hint'   => 'Set 0 for a provider you are not charging.',
                    'error'  => $errors['platform_fee_amount'] ?? '',
                ]) ?>
                <?= field_select([
                    'name'    => 'platform_fee_cycle_months',
                    'label'   => 'Charged every',
                    'value'   => (string)($values['platform_fee_cycle_months'] ?? 1),
                    'options' => [1 => 'Month', 2 => '2 months', 3 => 'Quarter', 6 => '6 months', 12 => 'Year'],
                ]) ?>
                <?php if (!$isEdit): ?>
                    <?= field_select([
                        'name'    => 'grace_months',
                        'label'   => 'Start charging',
                        'value'   => (string)($values['grace_months'] ?? setting('platform_fee_grace_months', 1)),
                        'options' => [
                            0  => 'Immediately',
                            1  => 'After 1 month',
                            2  => 'After 2 months',
                            3  => 'After 3 months',
                            6  => 'After 6 months',
                            12 => 'After 12 months',
                        ],
                        'hint' => 'The agreed grace period. The first invoice falls due this far from today.',
                    ]) ?>
                <?php else: ?>
                    <?= field_input([
                        'name'  => 'billing_starts_on',
                        'type'  => 'date',
                        'label' => 'Billing starts on',
                        'value' => (string)($values['billing_starts_on'] ?? ''),
                        'hint'  => 'Change this to move the agreed start date.',
                    ]) ?>
                    <?= field_select([
                        'name'    => 'billing_status',
                        'label'   => 'Billing status',
                        'value'   => (string)($values['billing_status'] ?? 'grace'),
                        'options' => [
                            'grace'   => 'Grace period - not charged yet',
                            'current' => 'Current - up to date',
                            'due'     => 'Due - invoice outstanding',
                            'overdue' => 'Overdue',
                            'exempt'  => 'Exempt - never charged',
                        ],
                    ]) ?>
                <?php endif; ?>
            </div>
        </fieldset>

        <?php if (!$isEdit): ?>
        <fieldset class="fieldset">
            <legend>First administrator</legend>
            <p class="small muted mt-0">This account can sign in immediately and run the provider: customers, packages, vouchers, network and its own staff.</p>
            <div class="form-grid">
                <?= field_input(['name' => 'admin_name', 'label' => 'Full name', 'value' => $values['admin_name'], 'required' => true, 'error' => $errors['admin_name'] ?? '']) ?>
                <?= field_input(['name' => 'admin_username', 'label' => 'Username', 'value' => $values['admin_username'], 'required' => true, 'error' => $errors['admin_username'] ?? '', 'attrs' => 'autocomplete="off"']) ?>
                <?= field_input(['name' => 'admin_email', 'type' => 'email', 'label' => 'Email', 'value' => $values['admin_email'], 'required' => true, 'error' => $errors['admin_email'] ?? '']) ?>
                <?= field_input(['name' => 'admin_phone', 'label' => 'Phone', 'value' => $values['admin_phone']]) ?>
                <?= field_input(['name' => 'admin_password', 'type' => 'password', 'label' => 'Password', 'required' => true, 'error' => $errors['admin_password'] ?? '', 'attrs' => 'autocomplete="new-password"', 'hint' => 'At least 8 characters with a letter and a number.']) ?>
                <?= field_input(['name' => 'admin_password_confirm', 'type' => 'password', 'label' => 'Confirm password', 'required' => true, 'error' => $errors['admin_password_confirm'] ?? '', 'attrs' => 'autocomplete="new-password"']) ?>
            </div>
        </fieldset>

        <?= field_checkbox(['name' => 'seed_defaults', 'label' => 'Give this provider a starter set-up', 'checked' => true,
            'hint' => 'Creates one bandwidth profile and three sample packages so they have something to sell on day one. They can edit or delete these.']) ?>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= icon('check', 'ico--sm') ?> <?= $isEdit ? 'Save provider' : 'Create provider' ?></button>
            <a class="btn" href="<?= e(url('admin/providers/index.php')) ?>">Cancel</a>
        </div>
    </div>
</div>
