<?php
/**
 * WMS - Bandwidth profiles.
 *
 * Profiles describe a rate limit once and are reused by packages. They can
 * be pushed to every live router as a hotspot user profile.
 */

$requiredPermission = 'manage_routers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$profiles = new BandwidthProfile();
$network  = new NetworkService();

if (is_post()) {
    CSRF::verify();
    $action = post('action');
    $id     = (int)post('id');

    if ($action === 'save') {
        $values = [
            'name'                => post('name'),
            'download_kbps'       => post('download_kbps'),
            'upload_kbps'         => post('upload_kbps'),
            'burst_download_kbps' => post('burst_download_kbps'),
            'burst_upload_kbps'   => post('burst_upload_kbps'),
            'burst_time'          => post('burst_time', '8'),
            'priority'            => post('priority', '8'),
            'description'         => post('description'),
            'mikrotik_name'       => post('mikrotik_name'),
            'status'              => post('status', 'active'),
        ];
        $burst = Validator::bool(post('burst_enabled'));

        $rules = [
            'name'          => 'required|min:2|max:100',
            'download_kbps' => 'required|integer|min_value:64',
            'upload_kbps'   => 'required|integer|min_value:64',
            'priority'      => 'required|integer|min_value:1|max_value:8',
        ];
        if ($burst) {
            $rules['burst_download_kbps'] = 'required|integer|min_value:64';
            $rules['burst_upload_kbps']   = 'required|integer|min_value:64';
        }

        $errors = (new Validator($values))->rules($rules)->errors();
        if (!$errors && $profiles->isNameTaken($values['name'], $id ?: null)) {
            $errors['name'] = 'A profile with that name already exists.';
        }
        if ($errors) {
            Response::back('error', reset($errors));
        }

        $data = [
            'name'                => $values['name'],
            'download_kbps'       => (int)$values['download_kbps'],
            'upload_kbps'         => (int)$values['upload_kbps'],
            'burst_enabled'       => $burst,
            'burst_download_kbps' => $burst ? (int)$values['burst_download_kbps'] : null,
            'burst_upload_kbps'   => $burst ? (int)$values['burst_upload_kbps'] : null,
            'burst_time'          => (int)$values['burst_time'],
            'priority'            => (int)$values['priority'],
            'description'         => $values['description'] ?: null,
            'mikrotik_name'       => $values['mikrotik_name'] ?: null,
            'status'              => $values['status'],
        ];

        if ($id) {
            $profiles->updateById($id, $data);
            AuditLog::record('bandwidth_update', 'bandwidth_profile', $id, 'Updated profile "' . $values['name'] . '"');
            Response::back('success', 'Profile saved.');
        }
        $providerId = ProviderContext::providerId();
        if ($providerId === null) {
            $providerId = (int)post('provider_id');
            if ($providerId <= 0 || (new Provider())->find($providerId) === null) {
                Response::back('error', 'Choose which provider this profile belongs to.');
            }
        }
        $data['provider_id'] = $providerId;

        $newId = $profiles->create($data);
        AuditLog::record('bandwidth_create', 'bandwidth_profile', $newId, 'Created profile "' . $values['name'] . '"');
        Response::back('success', 'Bandwidth profile created.');
    }

    if ($action === 'sync') {
        $result = $network->syncBandwidthProfile($id);
        Response::back($result['ok'] ? ($result['live'] ?? false ? 'success' : 'info') : 'error', $result['message']);
    }

    if ($action === 'delete') {
        $inUse = Database::getInstance()->count('SELECT COUNT(*) FROM packages WHERE bandwidth_profile_id = ?', [$id]);
        if ($inUse > 0) {
            Response::back('error', 'This profile is used by ' . $inUse . ' package(s). Detach it from them first.');
        }
        $profiles->deleteById($id);
        AuditLog::record('bandwidth_delete', 'bandwidth_profile', $id, 'Deleted a bandwidth profile');
        Response::back('success', 'Profile deleted.');
    }

    Response::back('error', 'That action is not supported.');
}

$filters = ['q' => query('q'), 'status' => query('status')];
$page    = $profiles->search($filters, current_page(), 20);
$editing = query('edit') ? $profiles->find((int)query('edit')) : null;

$pageTitle    = 'Bandwidth profiles';
$pageSubtitle = 'Speed limits defined once and reused by your packages';
$activeNav    = 'bandwidth';
$breadcrumbs  = [['label' => 'Network'], ['label' => 'Bandwidth']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle,
    '<button class="btn btn--primary" data-modal-open="bp-form">' . icon('plus', 'ico--sm') . ' New profile</button>'
) ?>

<?php if (demo_mode()): ?>
    <?= alert_box('demo', 'Demo mode: profiles are saved in WMS only. Once a router is in Live mode, "Push to routers" creates the matching hotspot user profile on it.', 'Demo mode') ?>
<?php endif; ?>

<section class="card">
    <form class="filter-bar" method="get" action="">
        <?= search_field($filters['q'], 'Search profiles…') ?>
        <?= filter_select('status', $filters['status'], ['active' => 'Active', 'inactive' => 'Inactive'], 'Any status') ?>
        <div class="filter-bar__actions">
            <button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Filter</button>
        </div>
    </form>

    <div class="table-wrap">
        <?php if (!$page['rows']): ?>
            <?= empty_state([
                'icon' => 'sliders', 'title' => 'No bandwidth profiles yet',
                'text' => 'Create a profile such as "Standard 3M/1M" and attach it to the packages that should use it.',
                'action' => '<button class="btn btn--primary" data-modal-open="bp-form">' . icon('plus', 'ico--sm') . ' Create a profile</button>',
            ]) ?>
        <?php else: ?>
            <table class="table table--stack">
                <thead>
                    <tr>
                        <th>Profile</th>
                        <th>Download</th>
                        <th>Upload</th>
                        <th>Burst</th>
                        <th class="text-right">Priority</th>
                        <th class="text-right">Packages</th>
                        <th>RouterOS rate-limit</th>
                        <th>Status</th>
                        <th class="table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($page['rows'] as $profile): ?>
                    <tr>
                        <td data-label="Profile">
                            <b><?= e($profile['name']) ?></b>
                            <div class="tiny muted"><?= e(str_limit((string)$profile['description'], 50)) ?></div>
                        </td>
                        <td data-label="Download" class="nowrap"><?= e(format_speed((int)$profile['download_kbps'])) ?></td>
                        <td data-label="Upload" class="nowrap"><?= e(format_speed((int)$profile['upload_kbps'])) ?></td>
                        <td data-label="Burst" class="nowrap">
                            <?php if ($profile['burst_enabled']): ?>
                                <?= e(format_speed((int)$profile['burst_download_kbps'])) ?>
                                <div class="tiny muted">for <?= (int)$profile['burst_time'] ?>s</div>
                            <?php else: ?>
                                <span class="faint">Off</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Priority" class="text-right"><?= (int)$profile['priority'] ?></td>
                        <td data-label="Packages" class="text-right"><?= (int)$profile['package_count'] ?></td>
                        <td data-label="Rate limit"><?= code_chip(BandwidthProfile::rateLimit($profile)) ?></td>
                        <td data-label="Status">
                            <?= badge($profile['status']) ?>
                            <?php if ($profile['synced_at']): ?>
                                <div class="tiny muted">Synced <?= e(time_ago($profile['synced_at'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="table__actions" data-label="Actions">
                            <a class="btn btn--sm" href="<?= e(url('admin/network/bandwidth.php?edit=' . (int)$profile['id'])) ?>"><?= icon('edit', 'ico--sm') ?></a>
                            <form method="post" style="display:inline">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="id" value="<?= (int)$profile['id'] ?>">
                                <button class="btn btn--sm" name="action" value="sync" title="Push to live routers"><?= icon('upload', 'ico--sm') ?></button>
                            </form>
                            <form method="post" style="display:inline" data-confirm="Delete profile <?= e($profile['name']) ?>?">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="id" value="<?= (int)$profile['id'] ?>">
                                <button class="btn btn--sm btn--danger" name="action" value="delete"><?= icon('trash', 'ico--sm') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?= pagination($page, 'profiles') ?>
</section>

<?php
ob_start();
echo CSRF::field();
echo '<input type="hidden" name="action" value="save">';
echo '<input type="hidden" name="id" value="' . ($editing ? (int)$editing['id'] : '') . '">';
?>
<?php if (!$editing): ?><?= provider_selector('provider_id') ?><?php endif; ?>
<div class="form-grid">
    <?= field_input(['name' => 'name', 'label' => 'Profile name', 'value' => $editing['name'] ?? '', 'required' => true, 'placeholder' => 'Standard 3M/1M']) ?>
    <?= field_input(['name' => 'mikrotik_name', 'label' => 'RouterOS profile name', 'value' => $editing['mikrotik_name'] ?? '', 'hint' => 'Optional. Defaults to the profile name.']) ?>
    <?= field_input(['name' => 'download_kbps', 'type' => 'number', 'label' => 'Download', 'value' => (string)($editing['download_kbps'] ?? 3072), 'suffix' => 'Kbps', 'required' => true, 'attrs' => 'min="64" step="64"']) ?>
    <?= field_input(['name' => 'upload_kbps', 'type' => 'number', 'label' => 'Upload', 'value' => (string)($editing['upload_kbps'] ?? 1024), 'suffix' => 'Kbps', 'required' => true, 'attrs' => 'min="64" step="64"']) ?>
    <div class="field--full">
        <?= field_checkbox(['name' => 'burst_enabled', 'label' => 'Allow burst', 'checked' => !empty($editing['burst_enabled']), 'hint' => 'Lets a customer exceed the limit briefly - pages feel faster without costing much capacity.']) ?>
    </div>
    <?= field_input(['name' => 'burst_download_kbps', 'type' => 'number', 'label' => 'Burst download', 'value' => (string)($editing['burst_download_kbps'] ?? ''), 'suffix' => 'Kbps', 'attrs' => 'min="64" step="64"']) ?>
    <?= field_input(['name' => 'burst_upload_kbps', 'type' => 'number', 'label' => 'Burst upload', 'value' => (string)($editing['burst_upload_kbps'] ?? ''), 'suffix' => 'Kbps', 'attrs' => 'min="64" step="64"']) ?>
    <?= field_input(['name' => 'burst_time', 'type' => 'number', 'label' => 'Burst time', 'value' => (string)($editing['burst_time'] ?? 8), 'suffix' => 'seconds', 'attrs' => 'min="1" max="60"']) ?>
    <?= field_input(['name' => 'priority', 'type' => 'number', 'label' => 'Queue priority', 'value' => (string)($editing['priority'] ?? 8), 'attrs' => 'min="1" max="8"', 'hint' => '1 is highest, 8 is lowest.']) ?>
    <?= field_select(['name' => 'status', 'label' => 'Status', 'value' => $editing['status'] ?? 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']]) ?>
    <div class="field--full">
        <?= field_textarea(['name' => 'description', 'label' => 'Description', 'value' => $editing['description'] ?? '', 'rows' => 2]) ?>
    </div>
</div>
<?php
$bpBody = ob_get_clean();
echo '<form method="post" action="">'
    . modal('bp-form', $editing ? 'Edit bandwidth profile' : 'New bandwidth profile', $bpBody,
        '<a class="btn" href="' . e(url('admin/network/bandwidth.php')) . '">Cancel</a>'
        . '<button type="submit" class="btn btn--primary">Save profile</button>', 'modal__panel--wide')
    . '</form>';
?>

<?php if ($editing): ?>
<script>document.addEventListener('DOMContentLoaded', function () { WMS.openModal('bp-form'); });</script>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
