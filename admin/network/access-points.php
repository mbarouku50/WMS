<?php
/**
 * WMS - Access point management.
 *
 * An access point is a Wi-Fi radio, not a router: it carries client devices
 * to the gateway but authenticates nobody and enforces no bandwidth. WMS
 * records it, links it to its parent router, and reports a status only when a
 * monitoring source has actually measured one.
 *
 * Ownership rules enforced on every write, not just in the dropdown:
 *   - the parent router must belong to the acting provider (Router::find()
 *     is tenant scoped, so another provider's id resolves to nothing);
 *   - access_points.provider_id must equal routers.provider_id;
 *   - a MAC address may appear once per provider.
 */

$requiredPermission = 'manage_routers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$accessPoints = new AccessPoint();
$routers      = new Router();

if (is_post()) {
    CSRF::verify();
    $action = post('action');
    $id     = (int)post('id');

    /* --------------------------------------------------------- save ---- */
    if ($action === 'save') {
        $values = [
            'name'        => post('name'),
            'location'    => post('location'),
            'ip_address'  => post('ip_address'),
            'mac_address' => post('mac_address'),
            'ssid'        => post('ssid'),
            'model'       => post('model'),
            'router_id'   => post('router_id'),
            'notes'       => post('notes'),
        ];

        $errors = (new Validator($values))
            ->labels(['ip_address' => 'Management IP address', 'mac_address' => 'MAC address'])
            ->rules([
                'name'        => 'required|min:2|max:120',
                'location'    => 'nullable|max:160',
                'ip_address'  => 'nullable|host|max:45',
                'mac_address' => 'nullable|mac',
                'ssid'        => 'nullable|max:80',
                'model'       => 'nullable|max:80',
            ])->errors();

        $mac = normalise_mac($values['mac_address']);

        // One physical radio, one record - per provider.
        if (!$errors && $mac !== null && $accessPoints->isMacTaken($mac, $id ?: null)) {
            $errors['mac_address'] = 'Another access point of yours already uses the MAC address ' . $mac . '.';
        }

        if ($errors) {
            Response::back('error', reset($errors));
        }

        /*
         * The parent router decides ownership. Router::find() is tenant
         * scoped, so submitting another provider's router id - by editing the
         * form, or by posting the endpoint directly - simply does not resolve,
         * and the access point is refused rather than silently re-parented.
         */
        $parent     = null;
        $providerId = ProviderContext::providerId();

        if ($values['router_id'] !== '') {
            $parent = $routers->find((int)$values['router_id']);
            if (!$parent) {
                Logger::network('Blocked an access point write against an out-of-scope router', [
                    'router_id' => (int)$values['router_id'],
                    'provider'  => $providerId,
                ]);
                AuditLog::record('access_denied', 'access_point', $id ?: null,
                    'Blocked an attempt to attach an access point to a router belonging to another provider');
                Response::back('error', 'That router could not be found.');
            }
            $providerId = (int)$parent['provider_id'];
        }

        $data = [
            'name'        => $values['name'],
            'location'    => $values['location'] ?: null,
            'ip_address'  => $values['ip_address'] ?: null,
            'mac_address' => $mac,
            'ssid'        => $values['ssid'] ?: null,
            'model'       => $values['model'] ?: null,
            'notes'       => $values['notes'] ?: null,
            'router_id'   => $parent ? (int)$parent['id'] : null,
        ];

        if ($id) {
            $existing = $accessPoints->find($id);
            if (!$existing) {
                Response::back('error', 'That access point could not be found.');
            }

            /*
             * An access point and its router must always agree about who owns
             * them. Re-parenting across providers is refused outright - it
             * would move a record between tenants.
             */
            if ($parent && (int)$parent['provider_id'] !== (int)$existing['provider_id']) {
                AuditLog::record('access_denied', 'access_point', $id,
                    'Blocked a cross-provider re-parent of an access point');
                Response::back('error', 'That router belongs to a different provider, so this access point cannot be attached to it.');
            }

            $accessPoints->updateById($id, $data);
            AuditLog::record('UPDATE_ACCESS_POINT', 'access_point', $id, 'Updated access point "' . $values['name'] . '"');
            Response::back('success', 'Access point saved.');
        }

        // Creating without a router: a platform administrator must say whose.
        if ($providerId === null) {
            $providerId = (int)post('provider_id');
            if ($providerId <= 0 || (new Provider())->find($providerId) === null) {
                Response::back('error', 'Choose a router, or pick which provider this access point belongs to.');
            }
        }
        $data['provider_id'] = $providerId;

        /*
         * A brand new record has not been measured by anything, so it starts
         * as UNKNOWN with a manual source rather than pretending to be online.
         */
        $data['status']            = 'unknown';
        $data['monitoring_source'] = 'manual';

        $newId = $accessPoints->create($data);
        AuditLog::record('CREATE_ACCESS_POINT', 'access_point', $newId, 'Added access point "' . $values['name'] . '"');
        Response::redirect('admin/network/access-point-view.php?id=' . $newId, 'success',
            'Access point added. Its status stays Unknown until a monitoring source reports on it.');
    }

    /* ------------------------------------------------------ lifecycle ---- */
    $record = $id ? $accessPoints->find($id) : null;
    if (in_array($action, ['retire'], true) && !$record) {
        Response::back('error', 'That access point could not be found.');
    }

    if ($action === 'retire') {
        // Sessions and devices reference this AP; retire rather than delete.
        $accessPoints->retire($id);
        AuditLog::record('RETIRE_ACCESS_POINT', 'access_point', $id,
            'Retired access point "' . $record['name'] . '" - history preserved');
        Response::back('success', 'Access point "' . $record['name'] . '" retired. Its session and device history is kept.');
    }

    Response::back('error', 'That action is not supported.');
}

/* ---------------------------------------------------------- listing ---- */

$filters = [
    'q'         => query('q'),
    'status'    => query('status'),
    'router_id' => query('router_id'),
    'location'  => query('location'),
];
$page       = $accessPoints->search($filters, current_page(), 20);
$counts     = $accessPoints->counts();
$routerList = $routers->listAll();          // tenant scoped - never another provider's
$locations  = $accessPoints->locations();
$editing    = query('edit') ? $accessPoints->find((int)query('edit')) : null;

$pageTitle    = 'Access points';
$pageSubtitle = 'The radios your customers actually associate with';
$activeNav    = 'access-points';
$breadcrumbs  = [['label' => 'Network', 'href' => url('admin/network/index.php')], ['label' => 'Access points']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle,
    '<a class="btn" href="' . e(url('admin/network/routers.php')) . '">' . icon('router', 'ico--sm') . ' Routers</a>'
    . '<button class="btn btn--primary" data-modal-open="ap-form">' . icon('plus', 'ico--sm') . ' Add access point</button>'
) ?>

<?= demo_banner('network') ?>

<div class="stat-grid mb-3">
    <?= stat_card(['label' => 'Access points', 'value' => number_format($counts['total']), 'icon' => 'antenna', 'tone' => 'primary',
        'meta' => $counts['monitored'] . ' with a monitoring source']) ?>
    <?= stat_card(['label' => 'Online', 'value' => number_format($counts['online']), 'icon' => 'check', 'tone' => 'success']) ?>
    <?= stat_card(['label' => 'Offline', 'value' => number_format($counts['offline']), 'icon' => 'x', 'tone' => 'danger']) ?>
    <?= stat_card(['label' => 'Status unknown', 'value' => number_format($counts['unknown']), 'icon' => 'info', 'tone' => 'neutral',
        'meta' => 'Nothing has measured them']) ?>
</div>

<section class="card">
    <form class="filter-bar" method="get" action="">
        <?= search_field($filters['q'], 'Search name, location, IP or MAC…') ?>
        <?= filter_select('status', $filters['status'], ['online' => 'Online', 'offline' => 'Offline', 'unknown' => 'Unknown'], 'Any status') ?>
        <?= filter_select('router_id', $filters['router_id'], array_column($routerList, 'name', 'id'), 'Any router') ?>
        <?= filter_select('location', $filters['location'], array_combine($locations, $locations) ?: [], 'Any location') ?>
        <div class="filter-bar__actions">
            <button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Filter</button>
            <?php if (array_filter($filters)): ?>
                <a class="btn btn--ghost" href="<?= e(url('admin/network/access-points.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="table-wrap">
        <?php if (!$page['rows']): ?>
            <?= empty_state([
                'icon' => 'antenna',
                'title' => array_filter($filters) ? 'No access points match those filters' : 'No access points yet',
                'text' => array_filter($filters)
                    ? 'Try a different status, router or search term.'
                    : 'Add your real Wi-Fi access points so WMS can associate customers and network activity with physical '
                      . 'locations. An access point is a radio, not a gateway: it never authenticates anyone, and its status '
                      . 'stays Unknown until something actually measures it.',
                'action' => array_filter($filters)
                    ? '<a class="btn" href="' . e(url('admin/network/access-points.php')) . '">Clear filters</a>'
                    : '<button class="btn btn--primary" data-modal-open="ap-form">' . icon('plus', 'ico--sm') . ' Add an access point</button>',
            ]) ?>
        <?php else: ?>
            <table class="table table--stack">
                <thead>
                    <tr>
                        <th>Access point</th>
                        <th>Router</th>
                        <th>SSID</th>
                        <th>Management IP</th>
                        <th class="text-right">Clients</th>
                        <th>Last seen</th>
                        <th>Status</th>
                        <th class="table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($page['rows'] as $ap): ?>
                    <?php $measured = AccessPoint::isMeasured($ap); ?>
                    <tr>
                        <td data-label="Access point">
                            <a href="<?= e(url('admin/network/access-point-view.php?id=' . (int)$ap['id'])) ?>"><b><?= e($ap['name']) ?></b></a>
                            <div class="tiny muted"><?= e($ap['location'] ?: '—') ?><?= $ap['model'] ? ' · ' . e($ap['model']) : '' ?></div>
                            <div class="tiny faint mono"><?= e($ap['mac_address'] ?: 'No MAC recorded') ?></div>
                        </td>
                        <td data-label="Router">
                            <?php if ($ap['router_id']): ?>
                                <a href="<?= e(url('admin/network/router-view.php?id=' . (int)$ap['router_id'])) ?>"><?= e($ap['router_name'] ?? '—') ?></a>
                                <div class="tiny muted"><?= e(ucfirst((string)($ap['router_mode'] ?? ''))) ?> mode</div>
                            <?php else: ?>
                                <span class="faint">Not linked</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="SSID"><?= e($ap['ssid'] ?: '—') ?></td>
                        <td data-label="Management IP"><?= code_chip($ap['ip_address']) ?></td>
                        <td data-label="Clients" class="text-right">
                            <?php if ($measured && $ap['status'] === 'online'): ?>
                                <?= (int)$ap['connected_users'] ?>
                            <?php else: ?>
                                <span class="faint" title="Not measured">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Last seen" class="nowrap"><?= $ap['last_seen_at'] ? e(time_ago($ap['last_seen_at'])) : '<span class="faint">Never</span>' ?></td>
                        <td data-label="Status">
                            <?= network_status($ap['status']) ?>
                            <div class="tiny muted"><?= e(AccessPoint::sourceLabel($ap)) ?></div>
                        </td>
                        <td class="table__actions" data-label="Actions">
                            <a class="btn btn--sm" href="<?= e(url('admin/network/access-point-view.php?id=' . (int)$ap['id'])) ?>" title="View"><?= icon('eye', 'ico--sm') ?></a>
                            <a class="btn btn--sm" href="<?= e(url('admin/network/access-points.php?edit=' . (int)$ap['id'])) ?>" title="Edit"><?= icon('edit', 'ico--sm') ?></a>
                            <form method="post" style="display:inline" data-confirm="Retire access point <?= e($ap['name']) ?>? It is removed from your list but its session and device history is kept.">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="id" value="<?= (int)$ap['id'] ?>">
                                <button class="btn btn--sm btn--danger" name="action" value="retire" title="Retire"><?= icon('history', 'ico--sm') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?= pagination($page, 'access points') ?>
</section>

<?php
/* ------------------------------------------------ add / edit dialogue ---- */
ob_start();
echo CSRF::field();
echo '<input type="hidden" name="action" value="save">';
echo '<input type="hidden" name="id" value="' . ($editing ? (int)$editing['id'] : '') . '">';
?>
<?php if (!$editing): ?><?= provider_selector('provider_id', '', false) ?><?php endif; ?>

<fieldset class="fieldset">
    <legend>Identity</legend>
    <div class="form-grid">
        <?= field_input(['name' => 'name', 'label' => 'Name', 'value' => $editing['name'] ?? '', 'required' => true, 'placeholder' => 'AP-01 Main Hall']) ?>
        <?= field_input(['name' => 'location', 'label' => 'Location', 'value' => $editing['location'] ?? '', 'placeholder' => 'Reception hall']) ?>
        <?= field_input(['name' => 'model', 'label' => 'Model', 'value' => $editing['model'] ?? '', 'placeholder' => 'cAP ax']) ?>
        <?= field_input(['name' => 'ssid', 'label' => 'SSID', 'value' => $editing['ssid'] ?? '', 'placeholder' => setting('portal_ssid', 'WMS-Hotspot'),
            'hint' => 'The network name this radio broadcasts.']) ?>
    </div>
</fieldset>

<fieldset class="fieldset">
    <legend>Network</legend>
    <div class="form-grid">
        <?= field_select(['name' => 'router_id', 'label' => 'Parent router', 'value' => (string)($editing['router_id'] ?? ''),
            'placeholder' => 'Not linked', 'options' => array_column($routerList, 'name', 'id'),
            'hint' => 'Only your own routers are listed, and only they are accepted.']) ?>
        <?= field_input(['name' => 'ip_address', 'label' => 'Management IP address', 'value' => $editing['ip_address'] ?? '',
            'placeholder' => '10.0.0.11', 'attrs' => 'autocomplete="off" spellcheck="false"',
            'hint' => 'The address you administer this AP on. Not a customer address.']) ?>
        <?= field_input(['name' => 'mac_address', 'label' => 'MAC address', 'value' => $editing['mac_address'] ?? '',
            'placeholder' => 'AA:BB:CC:11:22:33', 'class' => 'input--mono',
            'hint' => 'The hardware identifier. Used to recognise this radio through the router; unique per provider.']) ?>
        <div class="field--full">
            <?= field_textarea(['name' => 'notes', 'label' => 'Notes', 'value' => $editing['notes'] ?? '', 'rows' => 2,
                'placeholder' => 'Mounting position, power source, anything the next engineer needs.']) ?>
        </div>
    </div>
</fieldset>

<?php if ($editing): ?>
    <?= alert_box('info',
        'Status is set by monitoring, not by hand. This access point currently reads "' . label($editing['status'])
        . '" from: ' . AccessPoint::sourceLabel($editing) . '.',
        'Monitoring source') ?>
<?php else: ?>
    <?= alert_box('info',
        'A new access point starts as Unknown. WMS only reports it as Online or Offline once a monitoring source - today, its parent MikroTik - has actually observed it.',
        'Status comes from monitoring') ?>
<?php endif; ?>
<?php
$apBody = ob_get_clean();
echo '<form method="post" action="">'
    . modal('ap-form', $editing ? 'Edit access point' : 'Add an access point', $apBody,
        '<a class="btn" href="' . e(url('admin/network/access-points.php')) . '">Cancel</a>'
        . '<button type="submit" class="btn btn--primary">' . icon('check', 'ico--sm') . ' Save</button>', 'modal__panel--wide')
    . '</form>';
?>

<?php if ($editing): ?>
<script>document.addEventListener('DOMContentLoaded', function () { WMS.openModal('ap-form'); });</script>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
