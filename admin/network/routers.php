<?php
/**
 * WMS - Router management.
 *
 * Every router belongs to exactly one provider, and that ownership - not
 * anything the browser sends - decides what this page may touch. Router::find()
 * is tenant scoped, so a router id belonging to another provider resolves to
 * nothing here, on every action.
 *
 * Credentials are written encrypted and are never rendered back into the page,
 * the JSON API or the page source.
 */

$requiredPermission = 'manage_routers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$routers = new Router();
$network = new NetworkService();

if (is_post()) {
    CSRF::verify();
    $action = post('action');
    $id     = (int)post('id');

    /* --------------------------------------------------------- save ---- */
    if ($action === 'save') {
        $values = [
            'name'                 => post('name'),
            'ip_address'           => post('ip_address'),
            'api_port'             => post('api_port', '8728'),
            'api_username'         => post('api_username'),
            'connection_type'      => post('connection_type', 'api'),
            'hotspot_server'       => post('hotspot_server'),
            'hotspot_profile'      => post('hotspot_profile'),
            'default_user_profile' => post('default_user_profile'),
            'location'             => post('location'),
            'mode'                 => post('mode', 'demo'),
            'notes'                => post('notes'),
        ];
        $password = (string)($_POST['api_password'] ?? '');

        /*
         * Only the identity and the connection are mandatory. Hotspot names
         * are optional: on a router WMS has not read yet there is nothing
         * honest to put in them.
         */
        $errors = (new Validator($values))
            ->labels([
                'ip_address'      => 'API address',
                'api_port'        => 'API port',
                'api_username'    => 'API username',
                'connection_type' => 'Connection type',
            ])
            ->rules([
                'name'                 => 'required|min:2|max:120',
                'ip_address'           => 'required|host|max:190',
                'api_port'             => 'required|integer|min_value:1|max_value:65535',
                'api_username'         => 'required|max:80',
                'connection_type'      => 'required|in:api,api_tls',
                'hotspot_server'       => 'nullable|max:80',
                'hotspot_profile'      => 'nullable|max:80',
                'default_user_profile' => 'nullable|max:80',
                'location'             => 'nullable|max:160',
                'mode'                 => 'required|in:live,demo',
            ])->errors();

        /*
         * The name must be free against every row, retired ones included:
         * uq_routers_provider_name spans the whole table. A retired router
         * still reserving the name is the interesting case - restoring it
         * gives the operator the router back with its history attached,
         * which is nearly always what was meant by re-adding it.
         */
        if (!$errors && $routers->isNameTaken($values['name'], $id ?: null)) {
            $retired = $routers->findRetiredByName($values['name']);
            $errors['name'] = $retired && (int)$retired['id'] !== $id
                ? 'A retired router still holds the name "' . $values['name'] . '". Restore it from the '
                  . 'Retired filter on this page to get it back with its history, or choose another name.'
                : 'Another of your routers already uses that name.';
        }

        $useTls = $values['connection_type'] === 'api_tls';
        $port   = (int)$values['api_port'];

        /*
         * TLS is never inferred silently from the port. If the two disagree
         * the operator is told, and the explicit choice they made is what
         * gets stored.
         */
        if (!$errors && !$useTls && $port === 8729) {
            $errors['api_port'] = 'Port 8729 is the RouterOS API-SSL port. Choose "RouterOS API (TLS)" as the connection type, or use port 8728.';
        }
        if (!$errors && $useTls && $port === 8728) {
            $errors['api_port'] = 'Port 8728 is the plain RouterOS API port. Choose "RouterOS API" as the connection type, or use port 8729.';
        }

        // Live mode needs credentials, or it would be live in name only.
        $hasStoredPassword = false;
        if ($id) {
            $existing = $routers->find($id);
            if (!$existing) {
                Response::back('error', 'That router could not be found.');
            }
            $hasStoredPassword = !empty($existing['api_password']);
        }
        if (!$errors && $values['mode'] === 'live' && $password === '' && !$hasStoredPassword) {
            $errors['api_password'] = 'A Live router needs an API password. Save it in Demo mode, or enter the password now.';
        }

        if ($errors) {
            Response::back('error', reset($errors));
        }

        $data = [
            'name'                 => $values['name'],
            'ip_address'           => $values['ip_address'],
            'api_port'             => $port,
            'use_tls'              => $useTls ? 1 : 0,
            'api_username'         => $values['api_username'],
            'hotspot_server'       => $values['hotspot_server'] ?: null,
            'hotspot_profile'      => $values['hotspot_profile'] ?: null,
            'default_user_profile' => $values['default_user_profile'] ?: null,
            'location'             => $values['location'] ?: null,
            'mode'                 => $values['mode'],
            'notes'                => $values['notes'] ?: null,
        ];

        // An empty box means "keep the stored credential" - the old password
        // is never sent to the browser, so it cannot be echoed back.
        if ($password !== '') {
            $data['api_password'] = Crypto::encrypt($password);
        }

        if ($id) {
            $routers->updateById($id, $data);
            AuditLog::record('UPDATE_ROUTER', 'router', $id,
                'Updated router "' . $values['name'] . '"' . ($password !== '' ? ' (API password replaced)' : ''));
            Response::back('success', 'Router "' . $values['name'] . '" saved.');
        }

        /*
         * A router must always belong to a provider - it is the boundary that
         * decides who may send it commands. Provider users get their own;
         * a platform administrator picks one. $_POST['provider_id'] is only
         * ever consulted in platform scope.
         */
        $providerId = ProviderContext::providerId();
        if ($providerId === null) {
            $providerId = (int)post('provider_id');
            if ($providerId <= 0 || (new Provider())->find($providerId) === null) {
                Response::back('error', 'Choose which provider this router belongs to.');
            }
        }

        $data['provider_id'] = $providerId;
        $data['status']      = 'unknown';       // nothing has been contacted yet
        $newId = $routers->create($data);
        AuditLog::record('CREATE_ROUTER', 'router', $newId, 'Added router "' . $values['name'] . '"');
        Response::redirect('admin/network/router-view.php?id=' . $newId, 'success',
            'Router added. Run "Test connection" to check that it answers.');
    }

    /* ------------------------------------------------------ lifecycle ---- */

    /*
     * Restore is the one action whose subject is retired by definition, so it
     * looks the router up with findWithRetired() rather than find().
     */
    if ($action === 'restore') {
        $retired = $id ? $routers->findWithRetired($id) : null;
        if (!$retired || $retired['deleted_at'] === null) {
            Response::back('error', 'That retired router could not be found.');
        }
        $routers->restore($id);
        AuditLog::record('RESTORE_ROUTER', 'router', $id,
            'Restored retired router "' . $retired['name'] . '" with its access points');
        Response::back('success', 'Router "' . $retired['name'] . '" is back in your list, in Demo mode. '
            . 'Nothing was contacted while it was retired, so switch it to Live and test the connection '
            . 'before relying on its readings.');
    }

    /*
     * Permanent delete - the one action on this page that cannot be undone.
     * findWithRetired(), because a retired router is the commonest thing to
     * want gone and find() cannot see one.
     */
    if ($action === 'delete') {
        $doomed = $id ? $routers->findWithRetired($id) : null;
        if (!$doomed) {
            Response::back('error', 'That router could not be found.');
        }

        // Counted before the delete - afterwards there is nothing left to ask.
        $deps = $routers->dependencyCounts($id);
        if (!$routers->deletePermanently($id)) {
            Response::back('error', 'Router "' . $doomed['name'] . '" could not be deleted. Nothing was changed; '
                . 'the reason is in the application log.');
        }

        AuditLog::record('DELETE_ROUTER', 'router', $id,
            'Permanently deleted router "' . $doomed['name'] . '" - '
            . $deps['access_points'] . ' access points deleted with it; '
            . $deps['sessions'] . ' sessions, ' . $deps['vouchers'] . ' vouchers, '
            . $deps['batches'] . ' voucher batches, ' . $deps['devices'] . ' devices and '
            . $deps['usage'] . ' usage records kept but no longer naming a router');
        Response::redirect('admin/network/routers.php', 'success',
            'Router "' . $doomed['name'] . '" is deleted and its name is free to use again. Its sessions, '
            . 'vouchers and payments are still in your records - they simply no longer say which router they '
            . 'were on.');
    }

    $record = $id ? $routers->find($id) : null;
    if (in_array($action, ['retire', 'poll', 'disable', 'enable'], true) && !$record) {
        Response::back('error', 'That router could not be found.');
    }

    if ($action === 'retire') {
        /*
         * Retire, not delete. Sessions, vouchers, payments, usage and audit
         * rows all reference this router; removing the row would strand every
         * one of them. A retired router disappears from the working lists and
         * is never contacted again.
         */
        $routers->retire($id);
        AuditLog::record('RETIRE_ROUTER', 'router', $id,
            'Retired router "' . $record['name'] . '" - history preserved');
        Response::back('success', 'Router "' . $record['name'] . '" retired. Its sessions, vouchers and payment history are untouched.');
    }

    if ($action === 'disable') {
        $routers->updateById($id, ['status' => 'disabled', 'mode' => 'demo']);
        AuditLog::record('DISABLE_ROUTER', 'router', $id, 'Disabled router "' . $record['name'] . '"');
        Response::back('warning', $record['name'] . ' is disabled. WMS will not contact it until it is enabled again.');
    }

    if ($action === 'enable') {
        $routers->updateById($id, ['status' => 'unknown', 'failed_checks' => 0]);
        AuditLog::record('ENABLE_ROUTER', 'router', $id, 'Enabled router "' . $record['name'] . '"');
        Response::back('success', $record['name'] . ' is enabled. Set it to Live mode and test the connection.');
    }

    if ($action === 'poll') {
        $status = $network->pollRouter($record);
        AuditLog::record('SYNC_ROUTER', 'router', $id, 'Synced router "' . $record['name'] . '"');
        $resolved = $status['status'] ?? 'unknown';
        Response::back(
            $resolved === 'online' ? 'success' : ($resolved === 'degraded' ? 'warning' : 'warning'),
            $record['name'] . ': ' . ($status['message'] ?? 'sync complete.') . ' Status is now ' . label($resolved) . '.'
        );
    }

    Response::back('error', 'That action is not supported.');
}

/* ---------------------------------------------------------- listing ---- */

$filters = [
    'q'           => query('q'),
    'status'      => query('status'),
    'mode'        => query('mode'),
    'location'    => query('location'),
    'provider_id' => query('provider_id'),
];
$page    = $routers->search($filters, current_page(), 20);
$counts  = $routers->counts();
$editing = query('edit') ? $routers->find((int)query('edit')) : null;

$providerOptions = [];
if (ProviderContext::isGlobalScope()) {
    try {
        $providerOptions = array_column((new Provider())->listAll(true), 'business_name', 'id');
    } catch (Throwable $e) {
        $providerOptions = [];
    }
}

$pageTitle    = 'Routers';
$pageSubtitle = 'The MikroTik gateways that carry and enforce your traffic';
$activeNav    = 'routers';
$breadcrumbs  = [['label' => 'Network', 'href' => url('admin/network/index.php')], ['label' => 'Routers']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle,
    '<a class="btn" href="' . e(url('admin/network/index.php')) . '">' . icon('dashboard', 'ico--sm') . ' Overview</a>'
    . '<a class="btn" href="' . e(url('admin/network/access-points.php')) . '">' . icon('antenna', 'ico--sm') . ' Access points</a>'
    . '<button class="btn btn--primary" data-modal-open="router-form">' . icon('plus', 'ico--sm') . ' Add router</button>'
) ?>

<?= demo_banner('network') ?>

<div class="stat-grid mb-3">
    <?= stat_card(['label' => 'Routers', 'value' => number_format($counts['total']), 'icon' => 'router', 'tone' => 'primary',
        'meta' => $counts['live'] . ' live · ' . $counts['demo'] . ' demo']) ?>
    <?= stat_card(['label' => 'Online', 'value' => number_format($counts['online']), 'icon' => 'check', 'tone' => 'success']) ?>
    <?= stat_card(['label' => 'Degraded', 'value' => number_format($counts['degraded']), 'icon' => 'alert', 'tone' => 'warning',
        'meta' => 'Reachable but unwell']) ?>
    <?= stat_card(['label' => 'Offline', 'value' => number_format($counts['offline']), 'icon' => 'x', 'tone' => 'danger',
        'meta' => $counts['unknown'] . ' never contacted']) ?>
</div>

<div id="router-test-result"></div>

<?php if ($counts['retired'] > 0 && ($filters['status'] ?? '') !== 'retired'): ?>
    <section class="card mb-3"><div class="card__body flex justify-between items-center flex-wrap gap-1">
        <span class="small">
            <?= icon('history', 'ico--sm') ?>
            <b><?= (int)$counts['retired'] ?></b> retired router<?= $counts['retired'] === 1 ? '' : 's' ?>
            kept for history.
            <span class="muted">A retired router keeps its name reserved until it is restored, so that
            name cannot be given to a new router.</span>
        </span>
        <a class="btn btn--sm" href="<?= e(url('admin/network/routers.php?status=retired')) ?>">
            <?= icon('history', 'ico--sm') ?> View retired
        </a>
    </div></section>
<?php endif; ?>

<?php if (!$page['rows'] && !array_filter($filters)): ?>
    <div class="card"><div class="card__body">
        <?= empty_state([
            'icon'  => 'router',
            'title' => 'No routers configured',
            /*
             * Worded to stay true whichever way the platform Demo mode switch
             * is set: with no router there is simply nothing to contact, and
             * saying "demo mode" here would contradict the Live badge.
             */
            'text'  => 'Add a MikroTik router with its API address and credentials so WMS can enforce access on real '
                     . 'hardware. Until one is added and switched to Live mode, no router is contacted: every business '
                     . 'feature still works, and no network reading is invented.',
            'action' => '<button class="btn btn--primary" data-modal-open="router-form">' . icon('plus', 'ico--sm') . ' Add your first router</button>',
        ]) ?>
    </div></div>
<?php else: ?>

<section class="card mb-3">
    <form class="filter-bar" method="get" action="">
        <?= search_field($filters['q'], 'Search name, address, location or identity…') ?>
        <?= filter_select('status', $filters['status'], [
            'online' => 'Online', 'degraded' => 'Degraded', 'offline' => 'Offline',
            'unknown' => 'Unknown', 'disabled' => 'Disabled', 'retired' => 'Retired',
        ], 'Any status') ?>
        <?= filter_select('mode', $filters['mode'], ['live' => 'Live', 'demo' => 'Demo'], 'Any mode') ?>
        <?php if ($providerOptions): ?>
            <?= filter_select('provider_id', $filters['provider_id'], $providerOptions, 'Any provider') ?>
        <?php endif; ?>
        <div class="field filter-bar__item">
            <input type="text" class="input" name="location" value="<?= e($filters['location']) ?>" placeholder="Location">
        </div>
        <div class="filter-bar__actions">
            <button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Filter</button>
            <?php if (array_filter($filters)): ?>
                <a class="btn btn--ghost" href="<?= e(url('admin/network/routers.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php if (!$page['rows']): ?>
    <div class="card"><div class="card__body">
        <?= empty_state(['icon' => 'search', 'title' => 'No routers match those filters',
            'text' => 'Try a different status, mode or search term.',
            'action' => '<a class="btn" href="' . e(url('admin/network/routers.php')) . '">Clear filters</a>']) ?>
    </div></div>
<?php else: ?>

<div class="grid grid--2">
    <?php foreach ($page['rows'] as $router): ?>
        <?php
        $viewUrl  = url('admin/network/router-view.php?id=' . (int)$router['id']);
        $isLive   = $router['mode'] === 'live';
        $stale    = Router::isStale($router);
        $degraded = Router::degradedReason($router);
        // A retired router is never contacted again, so none of the actions
        // that would talk to it - or retire it twice - belong on its card.
        $isRetired = $router['status'] === 'retired';

        /*
         * Deleting cannot be undone, so the confirmation is built from this
         * router's own figures rather than worded in general terms: what goes
         * with it, what survives without it, and what it frees up. The tallies
         * come off the row - search() counts them in the listing query.
         */
        $apCount = (int)$router['ap_count'];
        $kept    = array_values(array_filter([
            (int)$router['session_count'] ? (int)$router['session_count'] . ' sessions'       : null,
            (int)$router['voucher_count'] ? (int)$router['voucher_count'] . ' vouchers'       : null,
            (int)$router['batch_count']   ? (int)$router['batch_count'] . ' voucher batches'  : null,
            (int)$router['device_count']  ? (int)$router['device_count'] . ' devices'         : null,
            (int)$router['usage_count']   ? (int)$router['usage_count'] . ' usage records'    : null,
        ]));
        $deleteConfirm = 'Delete ' . $router['name'] . ' permanently? This cannot be undone.';
        if ($isLive) {
            $deleteConfirm .= ' This router is LIVE'
                . ((int)$router['live_sessions'] > 0 ? ' with ' . (int)$router['live_sessions'] . ' active sessions' : '')
                . ' - WMS will stop enforcing access through it.';
        }
        if ($apCount) {
            $deleteConfirm .= ' Its ' . $apCount . ' access point'
                . ($apCount === 1 ? ' is' : 's are') . ' deleted with it.';
        }
        $deleteConfirm .= $kept
            ? ' ' . implode(', ', $kept) . ' are kept, but will no longer say which router they were on.'
            : ' No sessions, vouchers or usage records reference it.';
        $deleteConfirm .= ' The name "' . $router['name'] . '" becomes free again.';
        ?>
        <section class="card router-card">
            <div class="router-card__top">
                <span class="node__icon node__icon--<?= e(in_array($router['status'], ['online','offline'], true) ? $router['status'] : 'unknown') ?>" style="width:38px;height:38px">
                    <?= icon('router', 'ico--lg') ?>
                </span>
                <div class="flex-1">
                    <div class="flex items-center gap-1 flex-wrap">
                        <a href="<?= e($viewUrl) ?>"><b><?= e($router['name']) ?></b></a>
                        <?= network_status($router['status'], $degraded ?: null) ?>
                        <?= mode_badge($router['mode']) ?>
                        <?php if ($stale && $isLive): ?>
                            <span class="badge badge--warning" title="These readings are older than the polling interval"><?= icon('clock', 'ico--sm') ?>Stale</span>
                        <?php endif; ?>
                    </div>
                    <div class="small muted mono">
                        <?= e($router['ip_address']) ?>:<?= (int)$router['api_port'] ?>
                        <?= !empty($router['use_tls']) ? ' · TLS' : '' ?>
                    </div>
                    <?php if (ProviderContext::isGlobalScope()): ?>
                        <?php $ownerName = Database::getInstance()->fetchColumn('SELECT business_name FROM providers WHERE id = ?', [(int)$router['provider_id']]); ?>
                        <div class="tiny"><span class="pill"><?= icon('building', 'ico--sm') ?> <?= e((string)($ownerName ?: 'Unassigned')) ?></span></div>
                    <?php endif; ?>
                    <div class="tiny muted">
                        <?= e($router['location'] ?: 'No location set') ?>
                        <?= $router['routeros_version'] ? ' · RouterOS ' . e($router['routeros_version']) : '' ?>
                        <?= $router['identity'] ? ' · ' . e($router['identity']) : '' ?>
                    </div>
                    <div class="tiny muted">
                        Hotspot: <?= $router['hotspot_server']
                            ? '<b>' . e($router['hotspot_server']) . '</b>'
                            : '<span class="faint">not set</span>' ?>
                    </div>
                    <?php if ($router['last_error'] && $router['status'] !== 'online'): ?>
                        <div class="tiny" style="color:var(--wms-danger)"><?= icon('alert', 'ico--sm') ?> <?= e(str_limit($router['last_error'], 90)) ?></div>
                    <?php endif; ?>
                </div>
                <div class="dropdown">
                    <button type="button" class="btn btn--sm btn--icon" data-dropdown="rt-<?= (int)$router['id'] ?>" aria-label="Actions"><?= icon('more', 'ico--sm') ?></button>
                    <div class="dropdown__menu" id="rt-<?= (int)$router['id'] ?>">
                        <a class="dropdown__item" href="<?= e($viewUrl) ?>"><?= icon('eye', 'ico--sm') ?> View details</a>
                        <a class="dropdown__item" href="<?= e(url('admin/network/routers.php?edit=' . (int)$router['id'])) ?>"><?= icon('edit', 'ico--sm') ?> Edit</a>
                        <a class="dropdown__item" href="<?= e(url('admin/network/router-setup.php?id=' . (int)$router['id'])) ?>"><?= icon('clipboard', 'ico--sm') ?> Readiness check</a>
                        <a class="dropdown__item" href="<?= e(url('admin/network/access-points.php?router_id=' . (int)$router['id'])) ?>"><?= icon('antenna', 'ico--sm') ?> Access points (<?= (int)$router['ap_count'] ?>)</a>
                        <a class="dropdown__item" href="<?= e(url('admin/network/sessions.php?router_id=' . (int)$router['id'])) ?>"><?= icon('activity', 'ico--sm') ?> Sessions (<?= (int)$router['live_sessions'] ?>)</a>
                        <?php if (!$isRetired): ?>
                        <form method="post">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$router['id'] ?>">
                            <button class="dropdown__item" name="action" value="poll"><?= icon('refresh', 'ico--sm') ?> Sync now</button>
                        </form>
                        <?php endif; ?>
                        <div class="dropdown__divider"></div>
                        <?php if ($isRetired): ?>
                            <form method="post">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="id" value="<?= (int)$router['id'] ?>">
                                <button class="dropdown__item" name="action" value="restore"><?= icon('history', 'ico--sm') ?> Restore router</button>
                            </form>
                        <?php elseif ($router['status'] === 'disabled'): ?>
                            <form method="post">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="id" value="<?= (int)$router['id'] ?>">
                                <button class="dropdown__item" name="action" value="enable"><?= icon('power', 'ico--sm') ?> Enable</button>
                            </form>
                        <?php else: ?>
                            <form method="post" data-confirm="Disable <?= e($router['name']) ?>? WMS will stop contacting it, and it will no longer enforce new access. Nothing is deleted.">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="id" value="<?= (int)$router['id'] ?>">
                                <button class="dropdown__item" name="action" value="disable"><?= icon('block', 'ico--sm') ?> Disable</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!$isRetired): ?>
                        <form method="post" data-confirm="Retire <?= e($router['name']) ?>? It is removed from your router list and never contacted again. Its sessions, vouchers, payments and audit history are kept.">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$router['id'] ?>">
                            <button class="dropdown__item dropdown__item--danger" name="action" value="retire"><?= icon('history', 'ico--sm') ?> Retire router</button>
                        </form>
                        <?php endif; ?>
                        <div class="dropdown__divider"></div>
                        <form method="post" data-confirm="<?= e($deleteConfirm) ?>"
                              data-confirm-title="Delete <?= e($router['name']) ?> permanently"
                              data-confirm-button="Delete permanently">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$router['id'] ?>">
                            <button class="dropdown__item dropdown__item--danger" name="action" value="delete"><?= icon('trash', 'ico--sm') ?> Delete permanently</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="router-card__metrics">
                <div class="router-card__metric">
                    <b><?= $router['cpu_load'] === null ? '<span class="faint">—</span>' : (int)$router['cpu_load'] . '%' ?></b>
                    <span>CPU</span>
                </div>
                <div class="router-card__metric">
                    <b><?= $router['memory_used_pct'] === null ? '<span class="faint">—</span>' : (int)$router['memory_used_pct'] . '%' ?></b>
                    <span>Memory</span>
                </div>
                <div class="router-card__metric">
                    <b><?= $isLive && $router['last_sync_at'] ? (int)$router['active_users'] : '<span class="faint">—</span>' ?></b>
                    <span>Users</span>
                </div>
                <div class="router-card__metric">
                    <b><?= $router['uptime'] ? e(str_limit($router['uptime'], 10)) : '<span class="faint">—</span>' ?></b>
                    <span>Uptime</span>
                </div>
            </div>

            <div class="card__foot flex justify-between items-center flex-wrap gap-1">
                <span class="tiny muted">
                    <?php if ($router['last_seen_at']): ?>
                        Last seen <?= e(time_ago($router['last_seen_at'])) ?>
                    <?php else: ?>
                        Never contacted successfully
                    <?php endif; ?>
                    <br><?= freshness_note($router) ?>
                </span>
                <span class="flex gap-1">
                    <a class="btn btn--sm" href="<?= e($viewUrl) ?>"><?= icon('eye', 'ico--sm') ?> View</a>
                    <?php if ($isRetired): ?>
                        <form method="post" data-confirm="Restore <?= e($router['name']) ?>? It comes back in Demo mode with its access points, and its name is in use again.">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$router['id'] ?>">
                            <button class="btn btn--sm btn--primary" name="action" value="restore"><?= icon('history', 'ico--sm') ?> Restore</button>
                        </form>
                    <?php else: ?>
                        <a class="btn btn--sm" href="<?= e(url('admin/network/routers.php?edit=' . (int)$router['id'])) ?>"><?= icon('edit', 'ico--sm') ?> Edit</a>
                        <button class="btn btn--sm btn--primary" data-router-test="<?= (int)$router['id'] ?>" data-result="#router-test-result">
                            <?= icon('link', 'ico--sm') ?> Test connection
                        </button>
                    <?php endif; ?>
                </span>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<?= pagination($page, 'routers') ?>
<?php endif; ?>
<?php endif; ?>

<?php
/* ------------------------------------------------ add / edit dialogue ---- */
$isEditing = $editing !== null;
$modeValue = $editing['mode'] ?? 'demo';
$connType  = !empty($editing['use_tls']) ? 'api_tls' : 'api';

ob_start();
echo CSRF::field();
echo '<input type="hidden" name="action" value="save">';
echo '<input type="hidden" name="id" value="' . ($isEditing ? (int)$editing['id'] : '') . '">';
?>
<?php if (!$isEditing): ?><?= provider_selector('provider_id', (string)($editing['provider_id'] ?? '')) ?><?php endif; ?>

<fieldset class="fieldset">
    <legend>Router identity</legend>
    <div class="form-grid">
        <?= field_input(['name' => 'name', 'label' => 'Router name', 'value' => $editing['name'] ?? '', 'required' => true, 'placeholder' => 'HQ-Gateway']) ?>
        <?= field_input(['name' => 'location', 'label' => 'Location', 'value' => $editing['location'] ?? '', 'placeholder' => 'Main building']) ?>
        <div class="field--full">
            <?= field_textarea(['name' => 'notes', 'label' => 'Notes', 'value' => $editing['notes'] ?? '', 'rows' => 2,
                'placeholder' => 'Anything the next engineer should know about this router.']) ?>
        </div>
    </div>
</fieldset>

<fieldset class="fieldset">
    <legend>Connection</legend>
    <div class="form-grid">
        <?= field_input(['name' => 'ip_address', 'label' => 'API address', 'value' => $editing['ip_address'] ?? '', 'required' => true,
            'placeholder' => 'router.example.com or 10.0.0.1', 'attrs' => 'autocomplete="off" spellcheck="false"',
            'hint' => 'The address this server can actually reach the router on. IPv4, IPv6 or a hostname.']) ?>
        <?= field_select(['name' => 'connection_type', 'label' => 'Connection type', 'value' => $connType, 'required' => true,
            'options' => ['api' => 'RouterOS API (plain, port 8728)', 'api_tls' => 'RouterOS API TLS (api-ssl, port 8729)'],
            'attrs' => 'data-connection-type',
            'hint' => 'TLS is never assumed - this choice is what WMS uses.']) ?>
        <?= field_input(['name' => 'api_port', 'type' => 'number', 'label' => 'API port', 'value' => (string)($editing['api_port'] ?? 8728), 'required' => true,
            'attrs' => 'min="1" max="65535" data-api-port', 'hint' => '8728 = RouterOS API · 8729 = RouterOS API-SSL.']) ?>
        <?= field_input(['name' => 'api_username', 'label' => 'API username', 'value' => $editing['api_username'] ?? '', 'required' => true,
            'attrs' => 'autocomplete="off"', 'hint' => 'A dedicated RouterOS account for WMS, not the admin account.']) ?>
        <div class="field--full">
            <?= field_input(['name' => 'api_password', 'type' => 'password', 'label' => 'API password', 'attrs' => 'autocomplete="new-password"',
                'placeholder' => $isEditing ? 'Leave unchanged' : '',
                'hint' => $isEditing
                    ? 'Leave blank to keep the stored password. Type a new one to replace it.'
                    : 'Stored encrypted. It is never displayed, returned by the API or written to a log.']) ?>
        </div>
    </div>
</fieldset>

<fieldset class="fieldset">
    <legend>Hotspot</legend>
    <div class="form-grid">
        <div class="field--full flex gap-1 flex-wrap items-center">
            <?php if ($isEditing && $modeValue === 'live'): ?>
                <button type="button" class="btn btn--sm" data-hotspot-discover="<?= (int)$editing['id'] ?>">
                    <?= icon('search', 'ico--sm') ?> Read hotspot servers from the router
                </button>
            <?php endif; ?>
            <span class="tiny muted">WMS does not assume a server called "hotspot1" exists.</span>
        </div>
        <?= field_input(['name' => 'hotspot_server', 'label' => 'Hotspot server', 'value' => $editing['hotspot_server'] ?? '',
            'attrs' => 'list="hotspot-servers" autocomplete="off" data-hotspot-field',
            'hint' => 'The RouterOS hotspot server name. Leave blank to use every server on the router.']) ?>
        <?= field_input(['name' => 'hotspot_profile', 'label' => 'Hotspot profile', 'value' => $editing['hotspot_profile'] ?? '',
            'attrs' => 'list="hotspot-profiles" autocomplete="off"',
            'hint' => 'Optional. The server profile the hotspot uses.']) ?>
        <?= field_input(['name' => 'default_user_profile', 'label' => 'Default user profile', 'value' => $editing['default_user_profile'] ?? '',
            'attrs' => 'list="hotspot-profiles" autocomplete="off"',
            'hint' => 'Optional. Applied to hotspot users when a package carries no bandwidth profile.']) ?>
        <datalist id="hotspot-servers"></datalist>
        <datalist id="hotspot-profiles"></datalist>
    </div>
    <div id="hotspot-discovery" class="tiny muted"></div>
</fieldset>

<fieldset class="fieldset">
    <legend>Operation</legend>
    <div class="form-grid">
        <div class="field--full">
            <div class="field__label">Mode</div>
            <label class="check">
                <input type="radio" name="mode" value="demo" <?= $modeValue !== 'live' ? 'checked' : '' ?>>
                <span><b>Demo</b><div class="field__hint">No commands are sent to MikroTik. No real router is contacted. Network values are not presented as real measurements.</div></span>
            </label>
            <label class="check">
                <input type="radio" name="mode" value="live" <?= $modeValue === 'live' ? 'checked' : '' ?>>
                <span><b>Live</b><div class="field__hint">WMS communicates with this MikroTik router. Network changes may affect real customers.</div></span>
            </label>
        </div>
    </div>
</fieldset>

<?= alert_box('info', 'A stored router password is never shown again - not in this form, not in the page source, not in the API, not in the logs.') ?>
<?php
$routerBody = ob_get_clean();
$footer = '<a class="btn" href="' . e(url('admin/network/routers.php')) . '">Cancel</a>';
if ($isEditing) {
    $footer .= '<button type="button" class="btn" data-router-test="' . (int)$editing['id'] . '" data-result="#router-test-result">'
             . icon('link', 'ico--sm') . ' Test connection</button>';
}
$footer .= '<button type="submit" class="btn btn--primary">' . icon('check', 'ico--sm') . ' Save router</button>';

echo '<form method="post" action="">'
    . modal('router-form', $isEditing ? 'Edit router' : 'Add router', $routerBody, $footer, 'modal__panel--wide')
    . '</form>';
?>

<?php if ($isEditing): ?>
<script>document.addEventListener('DOMContentLoaded', function () { WMS.openModal('router-form'); });</script>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
