<?php
/**
 * WMS - Staff accounts.
 */

/*
 * Two audiences, one screen.
 *
 * A platform administrator manages every account on the platform.
 * A provider administrator manages only their own staff - and any account
 * they create is pinned to their provider by the server, whatever the form
 * happens to contain.
 */
// The bootstrap must load first, because the permission this page needs
// depends on whether we are in platform or provider scope.
require_once __DIR__ . '/../../config/config.php';

$requiredPermission = ProviderContext::isGlobalScope() ? 'manage_staff' : 'manage_provider_users';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$users      = new User();
$isPlatform = ProviderContext::isGlobalScope();
$providers  = $isPlatform ? (new Provider())->listAll() : [];

if (is_post()) {
    CSRF::verify();
    $action = post('action');
    $id     = (int)post('id');

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
        if (!$id || $password !== '') {
            $rules['password'] = 'required|password';
        }

        $errors = (new Validator(array_merge($values, ['password' => $password])))->rules($rules)->errors();

        if (!isset($errors['username']) && $users->isTaken('username', $values['username'], $id ?: null)) {
            $errors['username'] = 'That username is already taken.';
        }
        if (!isset($errors['email']) && $users->isTaken('email', $values['email'], $id ?: null)) {
            $errors['email'] = 'That email address is already in use.';
        }
        if ($errors) {
            Response::back('error', reset($errors));
        }

        // A role outside what this account may hand out is refused, so a
        // provider administrator cannot mint a platform administrator.
        $allowedRoleIds = array_map('intval', array_column($users->roles(), 'id'));
        if (!in_array((int)$values['role_id'], $allowedRoleIds, true)) {
            Response::back('error', 'That role is not one you can assign.');
        }

        $data = [
            'full_name' => $values['full_name'],
            'username'  => $values['username'],
            'email'     => $values['email'],
            'phone'     => $values['phone'] ?: null,
            'role_id'   => (int)$values['role_id'],
            'status'    => $values['status'],
        ];

        if ($id) {
            // Nobody may lock themselves out of their own account.
            if ($id === Auth::id() && $values['status'] !== 'active') {
                Response::back('error', 'You cannot suspend your own account.');
            }
            if ($password !== '') {
                $data['password_hash'] = password_hash((string)$password, PASSWORD_DEFAULT);
            }
            $users->updateById($id, $data);
            AuditLog::record('user_update', 'user', $id, 'Updated staff account "' . $values['username'] . '"');
            Response::back('success', 'Staff account saved.');
        }

        /*
         * provider_id comes from the server, never the form.
         *
         * A provider administrator always creates accounts inside their own
         * provider. A platform administrator picks one (or leaves it blank
         * for another platform account).
         */
        if ($isPlatform) {
            $chosen = (int)post('provider_id');
            $data['provider_id'] = $chosen > 0 ? $chosen : null;
            if ($data['provider_id'] !== null && !$users->isPlatformRole((int)$values['role_id'])) {
                // provider account with a provider role - fine
            } elseif ($data['provider_id'] === null && !$users->isPlatformRole((int)$values['role_id'])) {
                Response::back('error', 'A platform account needs a platform role. Choose a provider, or pick the Super Admin role.');
            }
        } else {
            $data['provider_id'] = ProviderContext::requireProvider();
        }

        $newId = $users->createUser($data, (string)$password);
        AuditLog::record('user_create', 'user', $newId, 'Created staff account "' . $values['username'] . '"');
        Response::back('success', 'Staff account created.');
    }

    if ($action === 'delete') {
        if ($id === Auth::id()) {
            Response::back('error', 'You cannot delete your own account.');
        }
        $record = $users->find($id);
        if (!$record) {
            Response::back('error', 'That account no longer exists.');
        }
        if (Database::getInstance()->count('SELECT COUNT(*) FROM users WHERE role_id = 1 AND status = "active"') <= 1 && (int)$record['role_id'] === 1) {
            Response::back('error', 'This is the last active super admin. Promote someone else first.');
        }
        $users->deleteById($id);
        AuditLog::record('user_delete', 'user', $id, 'Deleted staff account "' . $record['username'] . '"');
        Response::back('success', 'Staff account deleted.');
    }

    Response::back('error', 'That action is not supported.');
}

$filters = ['q' => query('q'), 'role_id' => query('role_id'), 'status' => query('status')];
$page    = $users->search($filters, current_page(), 20);
$roles   = $users->roles();
$stats   = $users->stats();
$editing = query('edit') ? $users->find((int)query('edit')) : null;

/* The profile shortcut in the user menu opens this page on your own row. */
if (query('profile') === '1') {
    $editing = $users->find((int)Auth::id());
}

$pageTitle    = $isPlatform ? 'All users' : 'Staff';
$pageSubtitle = $isPlatform
    ? 'Every account on the platform, and which provider it belongs to'
    : 'Who can sign in for ' . ProviderContext::scopeLabel() . ', and what they may do';
$activeNav    = 'users';
$breadcrumbs  = [['label' => 'Administration'], ['label' => 'Staff']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle,
    (Permission::has('manage_staff')
        ? '<a class="btn" href="' . e(url('admin/administration/roles.php')) . '">' . icon('shield', 'ico--sm') . ' Roles &amp; permissions</a>'
        : '')
    . '<button class="btn btn--primary" data-modal-open="user-form">' . icon('plus', 'ico--sm') . ' Add staff</button>'
) ?>

<div class="stat-grid mb-3">
    <?= stat_card(['label' => 'Staff accounts', 'value' => number_format($stats['total']), 'icon' => 'users', 'tone' => 'primary']) ?>
    <?= stat_card(['label' => 'Active', 'value' => number_format($stats['active']), 'icon' => 'check', 'tone' => 'success']) ?>
    <?= stat_card(['label' => 'Suspended', 'value' => number_format($stats['suspended']), 'icon' => 'block', 'tone' => 'warning']) ?>
    <?= stat_card(['label' => 'Roles available', 'value' => number_format(count($roles)), 'icon' => 'shield', 'tone' => 'info',
        'href' => Permission::has('manage_staff') ? url('admin/administration/roles.php') : '']) ?>
</div>

<section class="card">
    <form class="filter-bar" method="get" action="">
        <?= search_field($filters['q'], 'Search staff…') ?>
        <?= filter_select('role_id', $filters['role_id'], array_column($roles, 'name', 'id'), 'Any role') ?>
        <?= filter_select('status', $filters['status'], ['active' => 'Active', 'suspended' => 'Suspended', 'inactive' => 'Inactive'], 'Any status') ?>
        <div class="filter-bar__actions">
            <button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Filter</button>
        </div>
    </form>

    <div class="table-wrap">
        <?php if (!$page['rows']): ?>
            <?= empty_state(['icon' => 'users', 'title' => 'No staff accounts match', 'text' => 'Add colleagues and give each of them the narrowest role that lets them do their job.']) ?>
        <?php else: ?>
            <table class="table table--stack">
                <thead><tr><th>Name</th><th>Contact</th><?php if ($isPlatform): ?><th>Provider</th><?php endif; ?><th>Role</th><th>Last sign in</th><th>Status</th><th class="table__actions">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($page['rows'] as $user): ?>
                    <tr>
                        <td data-label="Name">
                            <?= cell_primary($user['full_name'], '@' . $user['username'], avatar($user['full_name'])) ?>
                            <?php if ((int)$user['id'] === Auth::id()): ?><span class="badge badge--info badge--plain">You</span><?php endif; ?>
                        </td>
                        <td data-label="Contact">
                            <div class="small"><?= e($user['email']) ?></div>
                            <div class="tiny muted"><?= e($user['phone'] ?: '—') ?></div>
                        </td>
                        <?php if ($isPlatform): ?>
                            <td data-label="Provider">
                                <?php if (!empty($user['provider_name'])): ?>
                                    <a href="<?= e(url('admin/providers/view.php?id=' . (int)$user['provider_id'])) ?>"><?= e($user['provider_name']) ?></a>
                                <?php else: ?>
                                    <span class="badge badge--info">Platform</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td data-label="Role"><?= e($user['role_name']) ?></td>
                        <td data-label="Last sign in" class="nowrap">
                            <?= $user['last_login_at'] ? e(time_ago($user['last_login_at'])) : '<span class="faint">Never</span>' ?>
                            <?php if ($user['last_login_ip']): ?><div class="tiny faint mono"><?= e($user['last_login_ip']) ?></div><?php endif; ?>
                        </td>
                        <td data-label="Status"><?= badge($user['status']) ?></td>
                        <td class="table__actions" data-label="Actions">
                            <a class="btn btn--sm" href="<?= e(url('admin/administration/users.php?edit=' . (int)$user['id'])) ?>"><?= icon('edit', 'ico--sm') ?></a>
                            <?php if ((int)$user['id'] !== Auth::id()): ?>
                                <form method="post" style="display:inline" data-confirm="Delete the account for <?= e($user['full_name']) ?>?">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                                    <button class="btn btn--sm btn--danger" name="action" value="delete"><?= icon('trash', 'ico--sm') ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?= pagination($page, 'staff') ?>
</section>

<?php
ob_start();
echo CSRF::field();
echo '<input type="hidden" name="action" value="save">';
echo '<input type="hidden" name="id" value="' . ($editing ? (int)$editing['id'] : '') . '">';
?>
<div class="form-grid">
    <?= field_input(['name' => 'full_name', 'label' => 'Full name', 'value' => $editing['full_name'] ?? '', 'required' => true]) ?>
    <?= field_input(['name' => 'username', 'label' => 'Username', 'value' => $editing['username'] ?? '', 'required' => true, 'attrs' => 'autocomplete="off"']) ?>
    <?= field_input(['name' => 'email', 'type' => 'email', 'label' => 'Email', 'value' => $editing['email'] ?? '', 'required' => true]) ?>
    <?= field_input(['name' => 'phone', 'label' => 'Phone', 'value' => $editing['phone'] ?? '']) ?>
    <?= field_select(['name' => 'role_id', 'label' => 'Role', 'value' => (string)($editing['role_id'] ?? ''), 'required' => true,
        'placeholder' => 'Choose a role', 'options' => array_column($roles, 'name', 'id')]) ?>
    <?php if ($isPlatform): ?>
        <?= field_select([
            'name' => 'provider_id', 'label' => 'Belongs to',
            'value' => (string)($editing['provider_id'] ?? ''),
            'placeholder' => 'The platform itself',
            'options' => array_column($providers, 'business_name', 'id'),
            'hint' => 'Leave blank only for platform administrators.',
        ]) ?>
    <?php endif; ?>
    <?= field_select(['name' => 'status', 'label' => 'Status', 'value' => $editing['status'] ?? 'active',
        'options' => ['active' => 'Active', 'suspended' => 'Suspended', 'inactive' => 'Inactive']]) ?>
    <div class="field--full">
        <?= field_input(['name' => 'password', 'type' => 'password', 'label' => $editing ? 'New password' : 'Password',
            'attrs' => 'autocomplete="new-password"',
            'required' => !$editing,
            'hint' => $editing ? 'Leave blank to keep the current password.' : 'At least 8 characters including a letter and a number.']) ?>
    </div>
</div>
<?php
$userBody = ob_get_clean();
echo '<form method="post" action="">'
    . modal('user-form', $editing ? 'Edit staff account' : 'Add a staff account', $userBody,
        '<a class="btn" href="' . e(url('admin/administration/users.php')) . '">Cancel</a>'
        . '<button type="submit" class="btn btn--primary">Save account</button>', 'modal__panel--wide')
    . '</form>';
?>

<?php if ($editing): ?>
<script>document.addEventListener('DOMContentLoaded', function () { WMS.openModal('user-form'); });</script>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
