<?php
/**
 * WMS - Add a customer.
 */

$requiredPermission = 'manage_customers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$customers = new Customer();
$isEdit    = false;
$errors    = [];
$values    = [
    'full_name' => '', 'phone' => '', 'email' => '', 'username' => '',
    'customer_type' => 'individual', 'status' => 'active', 'address' => '', 'notes' => '',
    'provider_id' => '',
];

if (is_post()) {
    CSRF::verify();
    foreach ($values as $key => $default) {
        $values[$key] = post($key, (string)$default);
    }
    $password = $_POST['password'] ?? '';

    $validator = (new Validator(array_merge($values, ['password' => $password])))
        ->labels(['full_name' => 'Full name', 'phone' => 'Phone', 'email' => 'Email', 'username' => 'Username'])
        ->rules([
            'full_name'     => 'required|min:3|max:140',
            'phone'         => 'required|phone',
            'email'         => 'nullable|email|max:160',
            'username'      => 'nullable|username',
            'password'      => 'nullable|password',
            'customer_type' => 'required|in:' . implode(',', array_keys(WMS_CUSTOMER_TYPES)),
            'status'        => 'required|in:' . implode(',', array_keys(WMS_CUSTOMER_STATUSES)),
        ]);
    $errors = $validator->errors();

    if (!isset($errors['phone']) && $customers->isTaken('phone', $values['phone'])) {
        $errors['phone'] = 'Another customer already uses this phone number.';
    }
    if ($values['username'] !== '' && !isset($errors['username']) && $customers->isTaken('username', $values['username'])) {
        $errors['username'] = 'That username is taken.';
    }
    if ($values['email'] !== '' && !isset($errors['email']) && $customers->isTaken('email', $values['email'])) {
        $errors['email'] = 'Another customer already uses this email address.';
    }

    if (!$errors) {
        try {
            /*
             * Whose customer is this? A provider user's own tenant, always.
             * A platform administrator picks one on the form - and the value
             * is checked against real providers before it is used.
             */
            $providerId = ProviderContext::providerId();
            if ($providerId === null) {
                $providerId = (int)post('provider_id');
                if ($providerId <= 0 || (new Provider())->find($providerId) === null) {
                    throw new RuntimeException('Choose which provider this customer belongs to.');
                }
            }

            $id = $customers->create([
                'provider_id'   => $providerId,
                'customer_code' => $customers->nextCode(),
                'full_name'     => $values['full_name'],
                'phone'         => $values['phone'],
                'email'         => $values['email'] ?: null,
                'username'      => $values['username'] ?: $values['phone'],
                'password_hash' => $password !== '' ? password_hash((string)$password, PASSWORD_DEFAULT) : null,
                'status'        => $values['status'],
                'customer_type' => $values['customer_type'],
                'address'       => $values['address'] ?: null,
                'notes'         => $values['notes'] ?: null,
                'created_by'    => Auth::id(),
            ]);
            AuditLog::record('customer_create', 'customer', $id, 'Created customer ' . $values['full_name']);
            Response::redirect('admin/customers/view.php?id=' . $id, 'success', $values['full_name'] . ' has been added.');
        } catch (Throwable $e) {
            Logger::error('Customer create failed: ' . $e->getMessage());
            $errors['full_name'] = str_starts_with($e->getMessage(), 'Choose which')
                ? $e->getMessage()
                : 'The customer could not be saved. Please try again.';
        }
    }
}

$pageTitle   = 'Add customer';
$activeNav   = 'customers';
$breadcrumbs = [['label' => 'Customers', 'url' => 'admin/customers/index.php'], ['label' => 'Add']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head('Add customer', 'Create an account for someone who buys directly from you') ?>

<?php if ($errors): ?><?= alert_box('danger', 'Please correct the highlighted fields.') ?><?php endif; ?>

<form method="post" action="" novalidate style="max-width:920px">
    <?= CSRF::field() ?>
    <?php require __DIR__ . '/_form.php'; ?>
</form>

<?php require INCLUDES_PATH . '/footer.php'; ?>
