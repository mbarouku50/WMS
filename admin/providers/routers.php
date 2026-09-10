<?php
/**
 * WMS - Router assignment.
 *
 * Only the platform owner decides which provider owns which router. This is
 * the gate: once a router belongs to a provider, that provider - and nobody
 * else - can operate it.
 */

$requirePlatform    = true;
$requiredPermission = 'manage_providers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$providers = new Provider();
$routers   = new Router();
$db        = Database::getInstance();

$id       = (int)query('id');
$provider = $id ? $providers->find($id) : null;

if (!$provider) {
    Response::redirect('admin/providers/index.php', 'error', 'That provider could not be found.');
}

if (is_post()) {
    CSRF::verify();
    $action   = post('action');
    $routerId = (int)post('router_id');

    if ($action === 'assign') {
        $router = $routers->find($routerId);   // platform scope: sees all
        if (!$router) {
            Response::back('error', 'That router could not be found.');
        }
        $previous = $router['provider_id'] === null ? null : (int)$router['provider_id'];

        $db->beginTransaction();
        try {
            // Move the router and everything hanging off it, so the tenant
            // boundary stays consistent.
            $routers->reassignProvider($routerId, $id);
            $db->execute('UPDATE access_points SET provider_id = ? WHERE router_id = ?', [$id, $routerId]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            Logger::error('Router assignment failed: ' . $e->getMessage());
            Response::back('error', 'The router could not be reassigned. Please try again.');
        }

        AuditLog::record('router_assign', 'router', $routerId,
            'Assigned router "' . $router['name'] . '" to ' . $provider['business_name']
            . ($previous ? ' (previously provider #' . $previous . ')' : ''));
        Response::back('success', $router['name'] . ' now belongs to ' . $provider['business_name'] . '.');
    }

    if ($action === 'unassign') {
        $router = $routers->find($routerId);
        if (!$router || (int)$router['provider_id'] !== $id) {
            Response::back('error', 'That router is not assigned to this provider.');
        }
        Response::back('warning', 'A router must always belong to a provider. Assign it to another provider instead of removing it.');
    }

    if ($action === 'create') {
        $values = [
            'name'         => post('name'),
            'ip_address'   => post('ip_address'),
            'api_port'     => post('api_port', '8728'),
            'api_username' => post('api_username'),
            'location'     => post('location'),
            'mode'         => post('mode', 'demo'),
        ];
        $password = $_POST['api_password'] ?? '';

        $errors = (new Validator($values))->rules([
            'name'         => 'required|min:2|max:120',
            'ip_address'   => 'required|ip',
            'api_port'     => 'required|integer|min_value:1|max_value:65535',
            'api_username' => 'required|max:80',
            'mode'         => 'required|in:live,demo',
        ])->errors();

        if ($errors) {
            Response::back('error', reset($errors));
        }

        $newId = $db->insert('routers', [
            'provider_id'    => $id,
            'name'           => $values['name'],
            'ip_address'     => $values['ip_address'],
            'api_port'       => (int)$values['api_port'],
            'use_tls'        => Validator::bool(post('use_tls')),
            'api_username'   => $values['api_username'],
            'api_password'   => $password !== '' ? Crypto::encrypt((string)$password) : null,
            'hotspot_server' => post('hotspot_server') ?: null,
            'location'       => $values['location'] ?: null,
            'mode'           => $values['mode'],
            'status'         => 'unknown',
        ]);

        AuditLog::record('router_create', 'router', $newId,
            'Added router "' . $values['name'] . '" for ' . $provider['business_name']);
        Response::back('success', 'Router added and assigned to ' . $provider['business_name'] . '.');
    }

    Response::back('error', 'That action is not supported.');
}

$assigned   = $db->fetchAll('SELECT * FROM routers WHERE provider_id = ? ORDER BY name', [$id]);
$unassigned = $db->fetchAll(
    'SELECT r.*, p.business_name AS owner_name FROM routers r
       LEFT JOIN providers p ON p.id = r.provider_id
      WHERE r.provider_id IS NULL OR r.provider_id <> ?
      ORDER BY (r.provider_id IS NULL) DESC, p.business_name, r.name',
    [$id]
);

$pageTitle   = 'Routers · ' . $provider['business_name'];
$activeNav   = 'providers';
$breadcrumbs = [
    ['label' => 'Providers', 'url' => 'admin/providers/index.php'],
    ['label' => $provider['business_name'], 'url' => 'admin/providers/view.php?id=' . $id],
    ['label' => 'Routers'],
];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head('Routers for ' . $provider['business_name'],
    'A provider can run as many routers as you assign them',
    '<button class="btn btn--primary" data-modal-open="add-router">' . icon('plus', 'ico--sm') . ' Add a router here</button>'
    . '<a class="btn" href="' . e(url('admin/providers/view.php?id=' . $id)) . '">Back to overview</a>'
) ?>

<?= alert_box('info', 'Assignment is the security boundary: once a router belongs to a provider, only that provider can test it, read its sessions or push hotspot users to it. Changing the owner moves its access points too.') ?>

<div class="grid grid--2">
    <!-- ------------------------------------------------- assigned --- -->
    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('router') ?> Assigned to this provider</h2>
            <span class="badge badge--success"><?= count($assigned) ?></span>
        </div>
        <div class="table-wrap">
            <?php if (!$assigned): ?>
                <?= empty_state([
                    'icon' => 'router', 'title' => 'No routers yet',
                    'text' => 'Assign an existing router from the list beside this one, or add a new one for this provider.',
                    'action' => '<button class="btn btn--primary" data-modal-open="add-router">' . icon('plus', 'ico--sm') . ' Add a router</button>',
                ]) ?>
            <?php else: ?>
                <table class="table table--stack table--compact">
                    <thead><tr><th>Router</th><th>Address</th><th>Mode</th><th>Status</th><th class="table__actions">Access points</th></tr></thead>
                    <tbody>
                    <?php foreach ($assigned as $router): ?>
                        <?php $apCount = $db->count('SELECT COUNT(*) FROM access_points WHERE router_id = ?', [(int)$router['id']]); ?>
                        <tr>
                            <td data-label="Router"><b><?= e($router['name']) ?></b><div class="tiny muted"><?= e($router['location'] ?: '—') ?></div></td>
                            <td data-label="Address"><?= code_chip($router['ip_address'] . ':' . $router['api_port']) ?></td>
                            <td data-label="Mode"><?= $router['mode'] === 'live' ? '<span class="badge badge--live">Live</span>' : '<span class="badge badge--demo">Demo</span>' ?></td>
                            <td data-label="Status"><?= badge($router['status']) ?></td>
                            <td data-label="Access points" class="table__actions"><?= (int)$apCount ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <!-- ----------------------------------------------- assignable --- -->
    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('link') ?> Available to assign</h2>
        </div>
        <div class="table-wrap">
            <?php if (!$unassigned): ?>
                <?= empty_state(['icon' => 'router', 'title' => 'Every router is already here', 'text' => 'There are no other routers on the platform to move across.']) ?>
            <?php else: ?>
                <table class="table table--stack table--compact">
                    <thead><tr><th>Router</th><th>Currently owned by</th><th>Status</th><th class="table__actions">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($unassigned as $router): ?>
                        <tr>
                            <td data-label="Router">
                                <b><?= e($router['name']) ?></b>
                                <div class="tiny faint mono"><?= e($router['ip_address']) ?></div>
                            </td>
                            <td data-label="Owner">
                                <?= $router['owner_name'] ? e($router['owner_name']) : '<span class="badge badge--warning">Unassigned</span>' ?>
                            </td>
                            <td data-label="Status"><?= badge($router['status']) ?></td>
                            <td data-label="Action" class="table__actions">
                                <form method="post" style="display:inline"
                                      data-confirm="<?= $router['owner_name']
                                          ? 'Move ' . e($router['name']) . ' from ' . e($router['owner_name']) . ' to ' . e($provider['business_name']) . '? Its access points move with it, and the old provider loses access immediately.'
                                          : 'Assign ' . e($router['name']) . ' to ' . e($provider['business_name']) . '?' ?>"
                                      data-confirm-button="Assign" data-confirm-tone="primary">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="router_id" value="<?= (int)$router['id'] ?>">
                                    <button class="btn btn--sm btn--primary" name="action" value="assign"><?= icon('check', 'ico--sm') ?> Assign</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php
ob_start();
echo CSRF::field();
echo '<input type="hidden" name="action" value="create">';
?>
<p class="small muted">The router is created already belonging to <b><?= e($provider['business_name']) ?></b>.</p>
<div class="form-grid">
    <?= field_input(['name' => 'name', 'label' => 'Router name', 'required' => true, 'placeholder' => 'Main gateway']) ?>
    <?= field_input(['name' => 'location', 'label' => 'Location', 'placeholder' => 'Head office']) ?>
    <?= field_input(['name' => 'ip_address', 'label' => 'API address', 'required' => true, 'placeholder' => '192.168.88.1']) ?>
    <?= field_input(['name' => 'api_port', 'type' => 'number', 'label' => 'API port', 'value' => '8728', 'required' => true]) ?>
    <?= field_input(['name' => 'api_username', 'label' => 'API username', 'required' => true, 'attrs' => 'autocomplete="off"']) ?>
    <?= field_input(['name' => 'api_password', 'type' => 'password', 'label' => 'API password', 'attrs' => 'autocomplete="new-password"', 'hint' => 'Stored encrypted; never shown again.']) ?>
    <?= field_input(['name' => 'hotspot_server', 'label' => 'Hotspot server', 'placeholder' => 'all']) ?>
    <?= field_select(['name' => 'mode', 'label' => 'Mode', 'value' => 'demo',
        'options' => ['demo' => 'Demo - not contacted', 'live' => 'Live - poll this router']]) ?>
    <div class="field--full">
        <?= field_checkbox(['name' => 'use_tls', 'label' => 'Use TLS (api-ssl)']) ?>
    </div>
</div>
<?php
$body = ob_get_clean();
echo '<form method="post" action="">'
    . modal('add-router', 'Add a router for ' . $provider['business_name'], $body,
        '<button type="button" class="btn" data-modal-close>Cancel</button>'
        . '<button type="submit" class="btn btn--primary">Add router</button>', 'modal__panel--wide')
    . '</form>';
?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
