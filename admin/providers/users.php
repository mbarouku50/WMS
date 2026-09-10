<?php
/**
 * WMS - Staff accounts for one provider (platform view).
 *
 * The Super Admin can add, edit and reset the password of a provider's
 * staff. Every account created here is pinned to this provider and can only
 * ever hold a provider-scope role.
 */

$requirePlatform    = true;
$requiredPermission = 'manage_providers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$providers = new Provider();
$users     = new User();
$db        = Database::getInstance();

$id       = (int)query('id');
$provider = $id ? $providers->find($id) : null;

if (!$provider) {
    Response::redirect('admin/providers/index.php', 'error', 'That provider could not be found.');
}

/** Provider-scope roles only - a tenant account can never be a platform one. */
$roles = $db->fetchAll("SELECT * FROM roles WHERE scope = 'provider' ORDER BY id");
$roleIds = array_map('intval', array_column($roles, 'id'));

if (is_post()) {
    CSRF::verify();
    $action = post('action');
    $userId = (int)post('user_id');

    /* Any user we act on must already belong to this provider. */
    $target = $userId
        ? $db->fetchOne('SELECT * FROM users WHERE id = ? AND provider_id = ? LIMIT 1', [$userId, $id])
        : null;

    if ($action === 'save') {
        $values = [
            'full_name' => post('full_name'),
            'username'  => post('username'),
            'email'     => post('email'),
            'phone'     => post('phone'),
            'role_id'   => post('role_id'),
            'status'    => post('status', 'active'),
        ];
        $password = $_POST['password'] ?? '';

        $rules = [
            'full_name' => 'required|min:3|max:120',
            'username'  => 'required|username',
            'email'     => 'required|email|max:160',
            'phone'     => 'nullable|phone',
            'role_id'   => 'required|integer|min_value:1',
            'status'    => 'required|in:active,suspended,inactive',
        ];
        if (!$userId || $password !== '') {
            $rules['password'] = 'required|password';
        }

        $errors = (new Validator(array_merge($values, ['password' => $password])))->rules($rules)->errors();

        // The role must be one of the provider-scope roles offered above.
        if (!in_array((int)$values['role_id'], $roleIds, true)) {
            $errors['role_id'] = 'Choose a provider role. Platform roles cannot be given to provider staff.';
        }
        if (!isset($errors['username']) && $users->isTaken('username', $values['username'], $userId ?: null)) {
            $errors['username'] = 'That username is already taken on this platform.';
        }
        if (!isset($errors['email']) && $users->isTaken('email', $values['email'], $userId ?: null)) {
            $errors['email'] = 'That email address is already in use.';
        }
        if ($errors) {
            Response::back('error', reset($errors));
        }

        $data = [
            'full_name' => $values['full_name'],
            'username'  => $values['username'],
            'email'     => $values['email'],
            'phone'     => $values['phone'] ?: null,
            'role_id'   => (int)$values['role_id'],
            'status'    => $values['status'],
        ];

        if ($userId) {
            if (!$target) {
                Response::back('error', 'That account does not belong to this provider.');
            }
            if ($password !== '') {
                $data['password_hash'] = password_hash((string)$password, PASSWORD_DEFAULT);
            }
            $db->update('users', $data, 'id = ? AND provider_id = ?', [$userId, $id]);
            AuditLog::record('provider_user_update', 'user', $userId,
                'Updated ' . $values['username'] . ' for ' . $provider['business_name']);
            Response::back('success', 'Account saved.');
        }

        // provider_id is set here, by the server, from the page's provider.
        $data['provider_id']   = $id;
        $data['password_hash'] = password_hash((string)$password, PASSWORD_DEFAULT);
        $newId = $db->insert('users', $data);

        AuditLog::record('provider_user_create', 'user', $newId,
            'Created ' . $values['username'] . ' for ' . $provider['business_name']);
        Response::back('success', $values['full_name'] . ' can now sign in for ' . $provider['business_name'] . '.');
    }

    if (!$target) {
        Response::back('error', 'That account does not belong to this provider.');
    }

    if ($action === 'reset_password') {
        $password = $_POST['new_password'] ?? '';
        $check = (new Validator(['password' => $password]))->rules(['password' => 'required|password']);
        if ($check->fails()) {
            Response::back('error', (string)$check->firstError());
        }
        $db->update('users', ['password_hash' => password_hash((string)$password, PASSWORD_DEFAULT)], 'id = ? AND provider_id = ?', [$userId, $id]);
        AuditLog::record('provider_user_password', 'user', $userId,
            'Reset the password for ' . $target['username'] . ' (' . $provider['business_name'] . ')');
        Response::back('success', 'Password reset for ' . $target['username'] . '. Share it with them over a channel you trust.');
    }

    if ($action === 'toggle') {
        $new = $target['status'] === 'active' ? 'suspended' : 'active';
        $db->update('users', ['status' => $new], 'id = ? AND provider_id = ?', [$userId, $id]);
        AuditLog::record('provider_user_status', 'user', $userId, 'Set ' . $target['username'] . ' to ' . $new);
        Response::back('success', $target['username'] . ' is now ' . $new . '.');
    }

    if ($action === 'delete') {
        $db->delete('users', 'id = ? AND provider_id = ?', [$userId, $id]);
        AuditLog::record('provider_user_delete', 'user', $userId,
            'Deleted ' . $target['username'] . ' from ' . $provider['business_name']);
        Response::back('success', 'Account deleted.');
    }

    Response::back('error', 'That action is not supported.');
}

$staff   = $providers->users($id);
$editing = query('edit') ? $db->fetchOne('SELECT * FROM users WHERE id = ? AND provider_id = ? LIMIT 1', [(int)query('edit'), $id]) : null;

$pageTitle   = 'Staff · ' . $provider['business_name'];
$activeNav   = 'providers';
$breadcrumbs = [
    ['label' => 'Providers', 'url' => 'admin/providers/index.php'],
    ['label' => $provider['business_name'], 'url' => 'admin/providers/view.php?id=' . $id],
    ['label' => 'Staff'],
];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head('Staff for ' . $provider['business_name'],
    'Accounts that can sign in and run this provider',
    '<button class="btn btn--primary" data-modal-open="user-form">' . icon('plus', 'ico--sm') . ' Add staff account</button>'
    . '<a class="btn" href="' . e(url('admin/providers/view.php?id=' . $id)) . '">Back to overview</a>'
) ?>

<?php if ($provider['status'] !== 'active'): ?>
    <?= alert_box('warning', 'This provider is ' . $provider['status'] . ', so none of these accounts can sign in until you activate it again.') ?>
<?php endif; ?>

<section class="card">
    <div class="table-wrap">
        <?php if (!$staff): ?>
            <?= empty_state([
                'icon' => 'users', 'title' => 'No staff accounts',
                'text' => 'Nobody can sign in for this provider yet. Create their first administrator.',
                'action' => '<button class="btn btn--primary" data-modal-open="user-form">' . icon('plus', 'ico--sm') . ' Add the first account</button>',
            ]) ?>
        <?php else: ?>
            <table class="table table--stack">
                <thead><tr><th>Name</th><th>Contact</th><th>Role</th><th>Last sign in</th><th>Status</th><th class="table__actions">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($staff as $person): ?>
                    <tr>
                        <td data-label="Name"><?= cell_primary($person['full_name'], '@' . $person['username'], avatar($person['full_name'])) ?></td>
                        <td data-label="Contact">
                            <div class="small"><?= e($person['email']) ?></div>
                            <div class="tiny muted"><?= e($person['phone'] ?: '—') ?></div>
                        </td>
                        <td data-label="Role"><?= e($person['role_name']) ?></td>
                        <td data-label="Last sign in" class="nowrap"><?= $person['last_login_at'] ? e(time_ago($person['last_login_at'])) : '<span class="faint">Never</span>' ?></td>
                        <td data-label="Status"><?= badge($person['status']) ?></td>
                        <td class="table__actions" data-label="Actions">
                            <a class="btn btn--sm" href="<?= e(url('admin/providers/users.php?id=' . $id . '&edit=' . (int)$person['id'])) ?>"><?= icon('edit', 'ico--sm') ?></a>
                            <div class="dropdown" style="display:inline-block">
                                <button type="button" class="btn btn--sm btn--icon" data-dropdown="pu-<?= (int)$person['id'] ?>" aria-label="More"><?= icon('more', 'ico--sm') ?></button>
                                <div class="dropdown__menu" id="pu-<?= (int)$person['id'] ?>">
                                    <button type="button" class="dropdown__item" data-modal-open="reset-<?= (int)$person['id'] ?>"><?= icon('key', 'ico--sm') ?> Reset password</button>
                                    <form method="post">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="user_id" value="<?= (int)$person['id'] ?>">
                                        <button class="dropdown__item" name="action" value="toggle">
                                            <?= icon('power', 'ico--sm') ?> <?= $person['status'] === 'active' ? 'Suspend' : 'Activate' ?>
                                        </button>
                                    </form>
                                    <form method="post" data-confirm="Delete the account for <?= e($person['full_name']) ?>?">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="user_id" value="<?= (int)$person['id'] ?>">
                                        <button class="dropdown__item dropdown__item--danger" name="action" value="delete"><?= icon('trash', 'ico--sm') ?> Delete</button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>

<?php
/* ---- add / edit dialogue ---- */
ob_start();
echo CSRF::field();
echo '<input type="hidden" name="action" value="save">';
echo '<input type="hidden" name="user_id" value="' . ($editing ? (int)$editing['id'] : '') . '">';
?>
<p class="small muted">This account belongs to <b><?= e($provider['business_name']) ?></b>. Only provider roles are offered - a tenant account can never hold platform permissions.</p>
<div class="form-grid">
    <?= field_input(['name' => 'full_name', 'label' => 'Full name', 'value' => $editing['full_name'] ?? '', 'required' => true]) ?>
    <?= field_input(['name' => 'username', 'label' => 'Username', 'value' => $editing['username'] ?? '', 'required' => true, 'attrs' => 'autocomplete="off"']) ?>
    <?= field_input(['name' => 'email', 'type' => 'email', 'label' => 'Email', 'value' => $editing['email'] ?? '', 'required' => true]) ?>
    <?= field_input(['name' => 'phone', 'label' => 'Phone', 'value' => $editing['phone'] ?? '']) ?>
    <?= field_select(['name' => 'role_id', 'label' => 'Role', 'value' => (string)($editing['role_id'] ?? ''), 'required' => true,
        'placeholder' => 'Choose a role', 'options' => array_column($roles, 'name', 'id')]) ?>
    <?= field_select(['name' => 'status', 'label' => 'Status', 'value' => $editing['status'] ?? 'active',
        'options' => ['active' => 'Active', 'suspended' => 'Suspended', 'inactive' => 'Inactive']]) ?>
    <div class="field--full">
        <?= field_input(['name' => 'password', 'type' => 'password', 'label' => $editing ? 'New password' : 'Password',
            'required' => !$editing, 'attrs' => 'autocomplete="new-password"',
            'hint' => $editing ? 'Leave blank to keep the current password.' : 'At least 8 characters with a letter and a number.']) ?>
    </div>
</div>
<?php
$body = ob_get_clean();
echo '<form method="post" action="">'
    . modal('user-form', $editing ? 'Edit staff account' : 'Add a staff account', $body,
        '<a class="btn" href="' . e(url('admin/providers/users.php?id=' . $id)) . '">Cancel</a>'
        . '<button type="submit" class="btn btn--primary">Save account</button>', 'modal__panel--wide')
    . '</form>';

/* ---- one password reset dialogue per row ---- */
foreach ($staff as $person) {
    ob_start();
    echo CSRF::field();
    echo '<input type="hidden" name="action" value="reset_password">';
    echo '<input type="hidden" name="user_id" value="' . (int)$person['id'] . '">';
    echo '<p class="small muted">Set a new password for <b>' . e($person['full_name']) . '</b> (@' . e($person['username']) . '). '
       . 'They are not told automatically - pass it on over a channel you trust.</p>';
    echo field_input(['name' => 'new_password', 'type' => 'password', 'label' => 'New password', 'required' => true,
        'attrs' => 'autocomplete="new-password"', 'hint' => 'At least 8 characters with a letter and a number.']);
    $resetBody = ob_get_clean();

    echo '<form method="post" action="">'
        . modal('reset-' . (int)$person['id'], 'Reset password', $resetBody,
            '<button type="button" class="btn" data-modal-close>Cancel</button>'
            . '<button type="submit" class="btn btn--primary">Reset password</button>')
        . '</form>';
}
?>

<?php if ($editing): ?>
<script>document.addEventListener('DOMContentLoaded', function () { WMS.openModal('user-form'); });</script>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
