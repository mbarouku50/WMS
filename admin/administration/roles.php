<?php
/**
 * WMS - Roles and permissions.
 *
 * Permissions are enforced on the server by Permission::require() on every
 * protected page and API endpoint - this screen only decides what is in each
 * role, it is not the enforcement point.
 */

$requiredPermission = 'manage_staff';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$users = new User();

if (is_post()) {
    CSRF::verify();
    $action = post('action');
    $id     = (int)post('id');

    if ($action === 'permissions') {
        $role = $users->role($id);
        if (!$role) {
            Response::back('error', 'That role no longer exists.');
        }
        if ($role['slug'] === 'super_admin') {
            Response::back('error', 'Super Admin always holds every permission - it is the recovery role.');
        }
        try {
            $users->syncRolePermissions($id, (array)($_POST['permissions'] ?? []));
            Permission::forCurrentUser(true);
            AuditLog::record('role_update', 'role', $id, 'Updated permissions for role "' . $role['name'] . '"');
            Response::back('success', 'Permissions updated for ' . $role['name'] . '.');
        } catch (Throwable $e) {
            Logger::error('Role permission sync failed: ' . $e->getMessage());
            Response::back('error', 'The permissions could not be saved. Please try again.');
        }
    }

    if ($action === 'save') {
        $name = Validator::string(post('name'), 80);
        $slug = strtolower(preg_replace('/[^a-z0-9_]/i', '_', post('slug') ?: $name) ?? '');
        if ($name === '' || $slug === '') {
            Response::back('error', 'A role needs a name.');
        }
        $data = ['name' => $name, 'slug' => $slug, 'description' => Validator::string(post('description'), 255) ?: null];

        if ($id) {
            Database::getInstance()->update('roles', $data, 'id = ?', [$id]);
            AuditLog::record('role_update', 'role', $id, 'Renamed role to "' . $name . '"');
            Response::back('success', 'Role saved.');
        }
        try {
            $newId = Database::getInstance()->insert('roles', $data);
            AuditLog::record('role_create', 'role', $newId, 'Created role "' . $name . '"');
            Response::back('success', 'Role created. Now choose its permissions.');
        } catch (Throwable $e) {
            Response::back('error', 'A role with that name already exists.');
        }
    }

    if ($action === 'delete') {
        $role = $users->role($id);
        if (!$role) {
            Response::back('error', 'That role no longer exists.');
        }
        if ($role['is_system']) {
            Response::back('error', 'Built-in roles cannot be deleted. Change their permissions instead.');
        }
        if (Database::getInstance()->count('SELECT COUNT(*) FROM users WHERE role_id = ?', [$id]) > 0) {
            Response::back('error', 'Move the staff in this role to another role first.');
        }
        Database::getInstance()->delete('roles', 'id = ?', [$id]);
        AuditLog::record('role_delete', 'role', $id, 'Deleted role "' . $role['name'] . '"');
        Response::back('success', 'Role deleted.');
    }

    Response::back('error', 'That action is not supported.');
}

$roles       = $users->roles();
$permissions = Permission::all();
$grouped     = [];
foreach ($permissions as $permission) {
    $grouped[$permission['group_name']][] = $permission;
}

$selectedId = (int)query('role', (string)($roles[0]['id'] ?? 0));
$selected   = $users->role($selectedId) ?: ($roles[0] ?? null);
$rolePerms  = $selected ? Permission::forRole((int)$selected['id']) : [];

$pageTitle    = 'Roles & permissions';
$pageSubtitle = 'What each kind of staff member may see and change';
$activeNav    = 'roles';
$breadcrumbs  = [['label' => 'Administration'], ['label' => 'Roles']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle,
    '<button class="btn btn--primary" data-modal-open="role-form">' . icon('plus', 'ico--sm') . ' New role</button>'
) ?>

<?= alert_box('info', 'Every protected page checks these permissions on the server before it renders. Hiding a button is never the control.') ?>

<div class="grid grid--1-2">
    <section class="card" style="align-self:start">
        <div class="card__head"><h2 class="card__title"><?= icon('shield') ?> Roles</h2></div>
        <div class="card__body--flush">
            <?php foreach ($roles as $role): ?>
                <a class="node" style="border:0;border-bottom:1px solid var(--wms-border);border-radius:0;background:<?= (int)$role['id'] === (int)($selected['id'] ?? 0) ? 'var(--wms-primary-soft)' : 'transparent' ?>"
                   href="<?= e(url('admin/administration/roles.php?role=' . (int)$role['id'])) ?>">
                    <span class="node__icon"><?= icon('shield') ?></span>
                    <span class="node__text">
                        <b><?= e($role['name']) ?><?= $role['is_system'] ? ' <span class="badge badge--neutral badge--plain">System</span>' : '' ?></b>
                        <span><?= e(str_limit((string)$role['description'], 52)) ?></span>
                    </span>
                    <span class="node__metrics">
                        <span><b><?= (int)$role['user_count'] ?></b>Staff</span>
                        <span><b><?= $role['slug'] === 'super_admin' ? 'All' : (int)$role['permission_count'] ?></b>Perms</span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($selected): ?>
    <section class="card">
        <div class="card__head">
            <div>
                <h2 class="card__title"><?= icon('key') ?> <?= e($selected['name']) ?></h2>
                <p class="card__subtitle"><?= e($selected['description'] ?: 'No description') ?></p>
            </div>
            <?php if (!$selected['is_system']): ?>
                <form method="post" data-confirm="Delete the role <?= e($selected['name']) ?>?">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="id" value="<?= (int)$selected['id'] ?>">
                    <button class="btn btn--sm btn--danger" name="action" value="delete"><?= icon('trash', 'ico--sm') ?> Delete role</button>
                </form>
            <?php endif; ?>
        </div>

        <form method="post" action="">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="permissions">
            <input type="hidden" name="id" value="<?= (int)$selected['id'] ?>">
            <div class="card__body">
                <?php if ($selected['slug'] === 'super_admin'): ?>
                    <?= alert_box('warning', 'Super Admin always has every permission so you can never lock yourself out of the system. Its permission set cannot be edited.') ?>
                <?php endif; ?>

                <?php foreach ($grouped as $group => $items): ?>
                    <fieldset class="fieldset">
                        <legend><?= e($group) ?></legend>
                        <div class="form-grid">
                            <?php foreach ($items as $permission): ?>
                                <label class="check mb-2">
                                    <input type="checkbox" name="permissions[]" value="<?= (int)$permission['id'] ?>"
                                        <?= in_array($permission['slug'], $rolePerms, true) || $selected['slug'] === 'super_admin' ? 'checked' : '' ?>
                                        <?= $selected['slug'] === 'super_admin' ? 'disabled' : '' ?>>
                                    <span>
                                        <b><?= e($permission['name']) ?></b>
                                        <div class="tiny faint mono"><?= e($permission['slug']) ?></div>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endforeach; ?>
            </div>
            <?php if ($selected['slug'] !== 'super_admin'): ?>
                <div class="card__foot flex justify-between items-center">
                    <span class="small muted"><?= count($rolePerms) ?> permission(s) currently granted</span>
                    <button type="submit" class="btn btn--primary"><?= icon('check', 'ico--sm') ?> Save permissions</button>
                </div>
            <?php endif; ?>
        </form>
    </section>
    <?php endif; ?>
</div>

<?php
ob_start();
echo CSRF::field();
echo '<input type="hidden" name="action" value="save">';
echo field_input(['name' => 'name', 'label' => 'Role name', 'required' => true, 'placeholder' => 'Shop attendant']);
echo field_input(['name' => 'slug', 'label' => 'Slug', 'placeholder' => 'shop_attendant', 'hint' => 'Optional. Generated from the name if left blank.']);
echo field_textarea(['name' => 'description', 'label' => 'Description', 'rows' => 2, 'placeholder' => 'What is this role for?']);
$roleBody = ob_get_clean();
echo '<form method="post" action="">'
    . modal('role-form', 'New role', $roleBody,
        '<button type="button" class="btn" data-modal-close>Cancel</button><button type="submit" class="btn btn--primary">Create role</button>')
    . '</form>';
?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
