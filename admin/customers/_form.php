<?php
/**
 * WMS - Customer form fields (shared by add.php and edit.php).
 *
 * Expects $values (array), $errors (array) and $isEdit (bool).
 */
?>
<div class="card">
    <div class="card__head">
        <h2 class="card__title"><?= icon('user') ?> <?= $isEdit ? 'Customer details' : 'New customer' ?></h2>
        <?php if ($isEdit): ?><span class="code-chip"><?= e($values['customer_code']) ?></span><?php endif; ?>
    </div>
    <div class="card__body">
        <?= provider_selector('provider_id', (string)($values['provider_id'] ?? '')) ?>

        <fieldset class="fieldset">
            <legend>Identity</legend>
            <div class="form-grid">
                <?= field_input(['name' => 'full_name', 'label' => 'Full name', 'value' => $values['full_name'], 'required' => true, 'error' => $errors['full_name'] ?? '', 'placeholder' => 'Amina Mushi']) ?>
                <?= field_input(['name' => 'phone', 'label' => 'Phone', 'value' => $values['phone'], 'required' => true, 'error' => $errors['phone'] ?? '', 'placeholder' => '0754 000 000', 'hint' => 'Used for mobile money payments and portal sign in.']) ?>
                <?= field_input(['name' => 'email', 'type' => 'email', 'label' => 'Email', 'value' => $values['email'], 'error' => $errors['email'] ?? '', 'placeholder' => 'optional']) ?>
                <?= field_input(['name' => 'username', 'label' => 'Portal username', 'value' => $values['username'], 'error' => $errors['username'] ?? '', 'hint' => 'Optional. Defaults to the phone number.']) ?>
            </div>
        </fieldset>

        <fieldset class="fieldset">
            <legend>Account</legend>
            <div class="form-grid">
                <?= field_select(['name' => 'customer_type', 'label' => 'Customer type', 'value' => $values['customer_type'], 'options' => WMS_CUSTOMER_TYPES]) ?>
                <?= field_select(['name' => 'status', 'label' => 'Status', 'value' => $values['status'], 'options' => WMS_CUSTOMER_STATUSES]) ?>
                <?= field_input([
                    'name' => 'password', 'type' => 'password',
                    'label' => $isEdit ? 'New portal password' : 'Portal password',
                    'error' => $errors['password'] ?? '',
                    'hint' => $isEdit ? 'Leave blank to keep the current password.' : 'Optional. Needed only if the customer signs in to the portal.',
                ]) ?>
                <?= field_input(['name' => 'address', 'label' => 'Address', 'value' => $values['address'], 'placeholder' => 'optional']) ?>
                <div class="field--full">
                    <?= field_textarea(['name' => 'notes', 'label' => 'Notes', 'value' => $values['notes'], 'rows' => 3, 'placeholder' => 'Anything the team should know about this customer.']) ?>
                </div>
            </div>
        </fieldset>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= icon('check', 'ico--sm') ?> <?= $isEdit ? 'Save changes' : 'Create customer' ?></button>
            <a class="btn" href="<?= e(url('admin/customers/index.php')) ?>">Cancel</a>
        </div>
    </div>
</div>
