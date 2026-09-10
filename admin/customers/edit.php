<?php
/**
 * WMS - Edit a customer.
 */

$requiredPermission = 'manage_customers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$customers = new Customer();
$id        = (int)query('id');
$record    = $customers->find($id);

if (!$record) {
    Response::redirect('admin/customers/index.php', 'error', 'That customer could not be found.');
}

$isEdit = true;
$errors = [];
$values = [
    'full_name'     => $record['full_name'],
    'phone'         => $record['phone'],
    'email'         => (string)$record['email'],
    'username'      => (string)$record['username'],
    'customer_type' => $record['customer_type'],
    'status'        => $record['status'],
    'address'       => (string)$record['address'],
    'notes'         => (string)$record['notes'],
    'customer_code' => $record['customer_code'],
];

if (is_post()) {
    CSRF::verify();
    foreach (['full_name', 'phone', 'email', 'username', 'customer_type', 'status', 'address', 'notes'] as $key) {
        $values[$key] = post($key, (string)$values[$key]);
    }
    $password = $_POST['password'] ?? '';

    $validator = (new Validator(array_merge($values, ['password' => $password])))
        ->labels(['full_name' => 'Full name', 'phone' => 'Phone', 'email' => 'Email'])
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

    if (!isset($errors['phone']) && $customers->isTaken('phone', $values['phone'], $id)) {
        $errors['phone'] = 'Another customer already uses this phone number.';
    }
    if ($values['username'] !== '' && !isset($errors['username']) && $customers->isTaken('username', $values['username'], $id)) {
        $errors['username'] = 'That username is taken.';
    }

    if (!$errors) {
        $data = [
            'full_name'     => $values['full_name'],
            'phone'         => $values['phone'],
            'email'         => $values['email'] ?: null,
            'username'      => $values['username'] ?: $values['phone'],
            'status'        => $values['status'],
            'customer_type' => $values['customer_type'],
            'address'       => $values['address'] ?: null,
            'notes'         => $values['notes'] ?: null,
        ];
        if ($password !== '') {
            $data['password_hash'] = password_hash((string)$password, PASSWORD_DEFAULT);
        }

        try {
            $customers->updateById($id, $data);
            AuditLog::record('customer_update', 'customer', $id, 'Updated customer ' . $values['full_name']);
            Response::redirect('admin/customers/view.php?id=' . $id, 'success', 'Customer updated.');
        } catch (Throwable $e) {
            Logger::error('Customer update failed: ' . $e->getMessage());
            $errors['full_name'] = 'The changes could not be saved. Please try again.';
        }
    }
}

$pageTitle   = 'Edit ' . $record['full_name'];
$activeNav   = 'customers';
$breadcrumbs = [
    ['label' => 'Customers', 'url' => 'admin/customers/index.php'],
    ['label' => $record['full_name'], 'url' => 'admin/customers/view.php?id=' . $id],
    ['label' => 'Edit'],
];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head('Edit customer', $record['customer_code'] . ' · joined ' . format_date($record['created_at'], 'd M Y'),
    '<a class="btn" href="' . e(url('admin/customers/view.php?id=' . $id)) . '">' . icon('eye', 'ico--sm') . ' View profile</a>'
) ?>

<?php if ($errors): ?><?= alert_box('danger', 'Please correct the highlighted fields.') ?><?php endif; ?>

<form method="post" action="" novalidate style="max-width:920px">
    <?= CSRF::field() ?>
    <?php require __DIR__ . '/_form.php'; ?>
</form>

<?php require INCLUDES_PATH . '/footer.php'; ?>
