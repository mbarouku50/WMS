<?php
/**
 * WMS - Reusable UI components.
 *
 * Every repeated piece of interface lives here as a small function, so the
 * pages stay readable and a change to (say) the badge or the pagination
 * happens once rather than forty times.
 *
 * All of these escape their own output.
 */

/* ================================================================= icons */

/**
 * Inline SVG icon set (16x16, stroked, no external dependency).
 * Keeping icons inline means no icon-font download on a captive portal
 * where the customer has not paid for data yet.
 */
function wms_icon_paths(): array
{
    static $icons = [
        'dashboard'   => '<rect x="2" y="2" width="5.5" height="5.5" rx="1"/><rect x="8.5" y="2" width="5.5" height="9" rx="1"/><rect x="2" y="8.5" width="5.5" height="5.5" rx="1"/><rect x="8.5" y="12" width="5.5" height="2" rx="1"/>',
        'users'       => '<path d="M11 14v-1.5a3 3 0 0 0-3-3H4a3 3 0 0 0-3 3V14"/><circle cx="6" cy="4.5" r="2.5"/><path d="M15 14v-1.5a3 3 0 0 0-2.2-2.9"/><path d="M10.5 2.1a3 3 0 0 1 0 4.8"/>',
        'user'        => '<path d="M13 14v-1.5a3.5 3.5 0 0 0-3.5-3.5h-3A3.5 3.5 0 0 0 3 12.5V14"/><circle cx="8" cy="4.5" r="2.6"/>',
        'package'     => '<path d="M14 5.5 8 2 2 5.5v5L8 14l6-3.5z"/><path d="M2 5.5 8 9l6-3.5M8 9v5"/>',
        'ticket'      => '<path d="M2 6V4.5a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1V6a2 2 0 0 0 0 4v1.5a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V10a2 2 0 0 0 0-4z"/><path d="M9.5 4v1.5M9.5 7.2v1.6M9.5 10.5V12" stroke-dasharray="1.5 1.4"/>',
        'card'        => '<rect x="1.5" y="3.5" width="13" height="9" rx="1.5"/><path d="M1.5 6.8h13"/><path d="M4 10h2.5"/>',
        'wifi'        => '<path d="M2 6.2a9 9 0 0 1 12 0"/><path d="M4.3 8.6a5.7 5.7 0 0 1 7.4 0"/><path d="M6.6 11a2.4 2.4 0 0 1 2.8 0"/><path d="M8 13.4v.01"/>',
        'router'      => '<rect x="1.5" y="8.5" width="13" height="5.5" rx="1.2"/><path d="M4.2 11.2v.01M6.6 11.2v.01"/><path d="M11.5 11.2h1.5"/><path d="M8 8.5V6"/><path d="M5.6 4a3.4 3.4 0 0 1 4.8 0"/><path d="M3.7 2.2a6 6 0 0 1 8.6 0"/>',
        'antenna'     => '<path d="M8 14V7"/><circle cx="8" cy="5" r="1.8"/><path d="M4.6 8.4a4.8 4.8 0 0 1 0-6.8"/><path d="M11.4 1.6a4.8 4.8 0 0 1 0 6.8"/>',
        'activity'    => '<path d="M1.5 8h3l2-5 3 10 2-5h3"/>',
        'device'      => '<rect x="4.5" y="1.5" width="7" height="13" rx="1.5"/><path d="M7 12.5h2"/>',
        'chart'       => '<path d="M2 14V2"/><path d="M2 14h12"/><rect x="4.5" y="8" width="2.5" height="4"/><rect x="9" y="5" width="2.5" height="7"/>',
        'report'      => '<path d="M9 1.5H4a1.5 1.5 0 0 0-1.5 1.5v10A1.5 1.5 0 0 0 4 14.5h8a1.5 1.5 0 0 0 1.5-1.5V6z"/><path d="M9 1.5V6h4.5"/><path d="M5.5 9h5M5.5 11.5h3"/>',
        'bell'        => '<path d="M12 6a4 4 0 1 0-8 0c0 4-1.5 5-1.5 5h11S12 10 12 6"/><path d="M6.8 13.5a1.5 1.5 0 0 0 2.4 0"/>',
        'settings'    => '<circle cx="8" cy="8" r="2.2"/><path d="M12.9 9.8a1.2 1.2 0 0 0 .24 1.32l.04.05a1.4 1.4 0 1 1-2 2l-.04-.05a1.2 1.2 0 0 0-1.32-.24 1.2 1.2 0 0 0-.73 1.1v.12a1.4 1.4 0 1 1-2.8 0v-.06a1.2 1.2 0 0 0-.79-1.1 1.2 1.2 0 0 0-1.32.24l-.04.05a1.4 1.4 0 1 1-2-2l.05-.04a1.2 1.2 0 0 0 .24-1.32 1.2 1.2 0 0 0-1.1-.73H1.2a1.4 1.4 0 1 1 0-2.8h.06a1.2 1.2 0 0 0 1.1-.79 1.2 1.2 0 0 0-.24-1.32l-.05-.04a1.4 1.4 0 1 1 2-2l.04.05a1.2 1.2 0 0 0 1.32.24h.06a1.2 1.2 0 0 0 .73-1.1V1.2a1.4 1.4 0 1 1 2.8 0v.06a1.2 1.2 0 0 0 .73 1.1 1.2 1.2 0 0 0 1.32-.24l.04-.05a1.4 1.4 0 1 1 2 2l-.05.04a1.2 1.2 0 0 0-.24 1.32v.06a1.2 1.2 0 0 0 1.1.73h.12a1.4 1.4 0 1 1 0 2.8h-.06a1.2 1.2 0 0 0-1.1.73z"/>',
        'shield'      => '<path d="M8 1.5 2.5 3.8v3.7c0 3.3 2.3 6.3 5.5 7 3.2-.7 5.5-3.7 5.5-7V3.8z"/><path d="m5.8 8 1.5 1.5 3-3"/>',
        'search'      => '<circle cx="7" cy="7" r="4.5"/><path d="m10.5 10.5 3 3"/>',
        'plus'        => '<path d="M8 3v10M3 8h10"/>',
        'edit'        => '<path d="M11.2 2.3a1.6 1.6 0 0 1 2.3 2.3L5 13l-3 .8L2.8 11z"/>',
        'trash'       => '<path d="M2.5 4h11"/><path d="M5.5 4V2.8a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1V4"/><path d="M12.2 4v8.5a1.4 1.4 0 0 1-1.4 1.4H5.2a1.4 1.4 0 0 1-1.4-1.4V4"/><path d="M6.6 7v4M9.4 7v4"/>',
        'eye'         => '<path d="M1 8s2.6-4.5 7-4.5S15 8 15 8s-2.6 4.5-7 4.5S1 8 1 8"/><circle cx="8" cy="8" r="2"/>',
        'download'    => '<path d="M8 1.8v8.4"/><path d="m4.8 7 3.2 3.2L11.2 7"/><path d="M2 13.2h12"/>',
        'upload'      => '<path d="M8 10.2V1.8"/><path d="M4.8 5 8 1.8 11.2 5"/><path d="M2 13.2h12"/>',
        'print'       => '<path d="M4.5 6V2.2h7V6"/><rect x="2" y="6" width="12" height="5" rx="1"/><path d="M4.5 9.5h7v4.3h-7z"/>',
        'check'       => '<path d="m3 8.5 3.2 3.2L13 4.8"/>',
        'x'           => '<path d="m4 4 8 8M12 4l-8 8"/>',
        'chevron'     => '<path d="m6 3.5 4.5 4.5L6 12.5"/>',
        'chevron-down'=> '<path d="M3.5 6 8 10.5 12.5 6"/>',
        'menu'        => '<path d="M2.5 4.5h11M2.5 8h11M2.5 11.5h11"/>',
        'logout'      => '<path d="M6 14H3.4A1.4 1.4 0 0 1 2 12.6V3.4A1.4 1.4 0 0 1 3.4 2H6"/><path d="m10.5 11 3-3-3-3"/><path d="M13.5 8h-8"/>',
        'refresh'     => '<path d="M13.7 7a5.8 5.8 0 0 0-10-2.6L2 6"/><path d="M2.3 9a5.8 5.8 0 0 0 10 2.6L14 10"/><path d="M2 2.5V6h3.5M14 13.5V10h-3.5"/>',
        'filter'      => '<path d="M14 2.5H2l4.8 5.6v4l2.4 1.4v-5.4z"/>',
        'calendar'    => '<rect x="2" y="3" width="12" height="11" rx="1.4"/><path d="M2 6.5h12M5.5 1.8v2.4M10.5 1.8v2.4"/>',
        'clock'       => '<circle cx="8" cy="8" r="6.2"/><path d="M8 4.5V8l2.4 1.4"/>',
        'money'       => '<circle cx="8" cy="8" r="6.2"/><path d="M8 4.4v7.2"/><path d="M9.9 5.9H7.1a1.4 1.4 0 0 0 0 2.8h1.8a1.4 1.4 0 0 1 0 2.8H6"/>',
        'trend-up'    => '<path d="m2 11 4-4 2.5 2.5L14 4"/><path d="M10.5 4H14v3.5"/>',
        'trend-down'  => '<path d="m2 5 4 4 2.5-2.5L14 12"/><path d="M10.5 12H14V8.5"/>',
        'alert'       => '<path d="M7 2.4 1.4 12a1.1 1.1 0 0 0 1 1.7h11.2a1.1 1.1 0 0 0 1-1.7L9 2.4a1.1 1.1 0 0 0-2 0"/><path d="M8 6.2v2.6M8 11.2v.01"/>',
        'info'        => '<circle cx="8" cy="8" r="6.2"/><path d="M8 7.4v3.6M8 5.2v.01"/>',
        'signal'      => '<path d="M2 13.5V11M6 13.5V8M10 13.5V5M14 13.5V2.5"/>',
        'database'    => '<ellipse cx="8" cy="3.6" rx="5.5" ry="2.1"/><path d="M2.5 3.6v8.8c0 1.2 2.5 2.1 5.5 2.1s5.5-.9 5.5-2.1V3.6"/><path d="M2.5 8c0 1.2 2.5 2.1 5.5 2.1s5.5-.9 5.5-2.1"/>',
        'lock'        => '<rect x="2.8" y="7" width="10.4" height="7" rx="1.4"/><path d="M5.2 7V4.8a2.8 2.8 0 0 1 5.6 0V7"/>',
        'key'         => '<circle cx="5" cy="11" r="2.6"/><path d="m7 9 6.5-6.5M11 5l1.6 1.6M9.4 6.6 11 8.2"/>',
        'globe'       => '<circle cx="8" cy="8" r="6.2"/><path d="M1.8 8h12.4"/><path d="M8 1.8a10 10 0 0 1 0 12.4A10 10 0 0 1 8 1.8"/>',
        'pin'         => '<path d="M13 6.8c0 4-5 8-5 8s-5-4-5-8a5 5 0 0 1 10 0"/><circle cx="8" cy="6.6" r="1.9"/>',
        'phone'       => '<path d="M14.2 11.4v2a1.4 1.4 0 0 1-1.5 1.4A13.4 13.4 0 0 1 1.2 3.3 1.4 1.4 0 0 1 2.6 1.8h2a1.4 1.4 0 0 1 1.4 1.2c.1.7.3 1.3.5 1.9a1.4 1.4 0 0 1-.3 1.5l-.8.8a11 11 0 0 0 4 4l.8-.8a1.4 1.4 0 0 1 1.5-.3c.6.2 1.2.4 1.9.5a1.4 1.4 0 0 1 1.2 1.4"/>',
        'mail'        => '<rect x="1.5" y="3" width="13" height="10" rx="1.4"/><path d="m1.8 4 6.2 4.4L14.2 4"/>',
        'more'        => '<circle cx="8" cy="3" r="1.2"/><circle cx="8" cy="8" r="1.2"/><circle cx="8" cy="13" r="1.2"/>',
        'external'    => '<path d="M9 2.5h4.5V7"/><path d="M13.5 2.5 7 9"/><path d="M12 9.5v3a1 1 0 0 1-1 1H3.5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h3"/>',
        'power'       => '<path d="M8 2v6"/><path d="M11.7 4.3a5.2 5.2 0 1 1-7.4 0"/>',
        'link'        => '<path d="M6.5 8.6a2.8 2.8 0 0 0 4.2.3l1.7-1.7a2.8 2.8 0 0 0-4-4l-1 1"/><path d="M9.5 7.4a2.8 2.8 0 0 0-4.2-.3L3.6 8.8a2.8 2.8 0 0 0 4 4l1-1"/>',
        'sliders'     => '<path d="M2.5 4.5h5M10.5 4.5h3M2.5 11.5h3M8.5 11.5h5"/><circle cx="9" cy="4.5" r="1.6"/><circle cx="7" cy="11.5" r="1.6"/>',
        'clipboard'   => '<rect x="3.5" y="2.8" width="9" height="11.2" rx="1.3"/><path d="M6 2.8V2a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v.8"/><path d="M6 7h4M6 10h2.5"/>',
        'building'    => '<rect x="3" y="2" width="10" height="12" rx="1.2"/><path d="M6 5h1M9 5h1M6 8h1M9 8h1M6.8 14v-3h2.4v3"/>',
        'copy'        => '<rect x="5.5" y="5.5" width="8" height="8" rx="1.3"/><path d="M10.5 5.5V3.8a1.3 1.3 0 0 0-1.3-1.3H3.8a1.3 1.3 0 0 0-1.3 1.3v5.4a1.3 1.3 0 0 0 1.3 1.3h1.7"/>',
        'block'       => '<circle cx="8" cy="8" r="6.2"/><path d="m3.8 3.8 8.4 8.4"/>',
        'history'     => '<path d="M2.4 8a5.6 5.6 0 1 0 1.7-4L2 6"/><path d="M2 2.5V6h3.5"/><path d="M8 5v3.2l2.2 1.3"/>',
    ];
    return $icons;
}

/** Renders an inline icon. */
function icon(string $name, string $class = ''): string
{
    $paths = wms_icon_paths();
    $body  = $paths[$name] ?? $paths['info'];
    return '<svg class="ico ' . e($class) . '" viewBox="0 0 16 16" aria-hidden="true" focusable="false">' . $body . '</svg>';
}

/* ================================================================ badges */

/**
 * Status chip using the shared semantic tones.
 */
function badge(?string $status, ?string $label = null, string $extraClass = ''): string
{
    $tone = status_tone($status);
    $text = $label ?? label($status);
    return '<span class="badge badge--' . $tone . ' ' . e($extraClass) . '">' . e($text) . '</span>';
}

/** "Live" or "Demo" marker - the system never presents demo data as live. */
function source_badge(?string $source): string
{
    return ($source === 'live')
        ? '<span class="badge badge--live">Live</span>'
        : '<span class="badge badge--demo" title="Simulated - no live router is attached">Demo</span>';
}

/** Small pill, used for secondary metadata. */
function pill(string $text, string $iconName = ''): string
{
    return '<span class="pill">' . ($iconName ? icon($iconName, 'ico--sm') : '') . e($text) . '</span>';
}

/* ================================================================= stats */

/**
 * Dashboard stat tile.
 *
 * @param array $config label, value, meta, tone, icon, href, delta
 */
function stat_card(array $config): string
{
    $tone  = $config['tone'] ?? 'neutral';
    $inner = '<div class="stat__label">'
        . (!empty($config['icon']) ? icon($config['icon'], 'ico--sm') : '')
        . e($config['label'] ?? '') . '</div>'
        . '<div class="stat__value"' . (!empty($config['live']) ? ' data-live="' . e($config['live']) . '"' : '') . '>'
        . e((string)($config['value'] ?? '0'))
        . (!empty($config['unit']) ? ' <small>' . e($config['unit']) . '</small>' : '')
        . '</div>';

    if (!empty($config['meta'])) {
        $deltaClass = '';
        if (isset($config['delta'])) {
            $deltaClass = $config['delta'] >= 0 ? 'stat__delta--up' : 'stat__delta--down';
        }
        $inner .= '<div class="stat__meta"><span class="' . $deltaClass . '">' . e($config['meta']) . '</span></div>';
    }

    $classes = 'stat stat--' . e($tone);
    if (!empty($config['href'])) {
        return '<a class="' . $classes . '" href="' . e($config['href']) . '" style="display:block;color:inherit;text-decoration:none">' . $inner . '</a>';
    }
    return '<div class="' . $classes . '">' . $inner . '</div>';
}

/* ============================================================ page parts */

/** Page title block with optional action buttons on the right. */
function page_head(string $title, string $subtitle = '', string $actions = ''): string
{
    return '<div class="page-head"><div class="page-head__text">'
        . '<h1>' . e($title) . '</h1>'
        . ($subtitle !== '' ? '<p class="page-head__sub">' . e($subtitle) . '</p>' : '')
        . '</div>'
        . ($actions !== '' ? '<div class="page-head__actions">' . $actions . '</div>' : '')
        . '</div>';
}

/** Renders and clears queued flash messages. */
function flash_messages(): string
{
    $out = '';
    foreach (Session::takeFlashes() as $flash) {
        $type = in_array($flash['type'], ['success', 'error', 'warning', 'info', 'danger'], true) ? $flash['type'] : 'info';
        $iconName = match ($type) {
            'success' => 'check',
            'error', 'danger' => 'alert',
            'warning' => 'alert',
            default => 'info',
        };
        $out .= '<div class="alert alert--' . e($type) . '">'
            . icon($iconName, 'alert__icon')
            . '<div class="alert__body">' . e($flash['message']) . '</div>'
            . '<button type="button" class="alert__close" data-dismiss aria-label="Dismiss">' . icon('x', 'ico--sm') . '</button>'
            . '</div>';
    }
    return $out;
}

/** Static alert block. */
function alert_box(string $type, string $message, string $title = ''): string
{
    $iconName = match ($type) {
        'success' => 'check',
        'danger', 'error', 'warning' => 'alert',
        'demo' => 'info',
        default => 'info',
    };
    return '<div class="alert alert--' . e($type) . '">'
        . icon($iconName, 'alert__icon')
        . '<div class="alert__body">'
        . ($title !== '' ? '<div class="alert__title">' . e($title) . '</div>' : '')
        . e($message) . '</div></div>';
}

/**
 * The banner that keeps demo mode honest wherever network data is shown.
 */
function demo_banner(string $context = 'network'): string
{
    if (!demo_mode()) {
        return '';
    }
    $message = match ($context) {
        'payment' => 'Demo mode is on. Payments use the demo provider and no real money moves. Switch provider under Settings → Payments.',
        'session' => 'Demo mode is on. Session and usage rows marked "Demo" are simulated records, not readings from a live router.',
        default   => 'Demo mode is on. Router and access point readings are only shown once a router is configured in Live mode - nothing here is invented.',
    };
    return '<div class="alert alert--demo">' . icon('info', 'alert__icon')
        . '<div class="alert__body"><div class="alert__title">Demo mode</div>' . e($message) . '</div></div>';
}

/* ================================================================= forms */

/**
 * Text input field with label, hint and error.
 *
 * @param array $config name, label, value, type, placeholder, hint, error,
 *                      required, attrs, prefix, suffix, class
 */
function field_input(array $config): string
{
    $name  = $config['name'] ?? '';
    $id    = $config['id'] ?? ('f_' . preg_replace('/[^a-z0-9_]/i', '_', $name));
    $error = $config['error'] ?? '';
    $attrs = $config['attrs'] ?? '';

    $input = '<input type="' . e($config['type'] ?? 'text') . '"'
        . ' id="' . e($id) . '" name="' . e($name) . '"'
        . ' class="input ' . e($config['class'] ?? '') . ($error ? ' is-invalid' : '') . '"'
        . ' value="' . e((string)($config['value'] ?? '')) . '"'
        . (!empty($config['placeholder']) ? ' placeholder="' . e($config['placeholder']) . '"' : '')
        . (!empty($config['required']) ? ' required' : '')
        . ' ' . $attrs . '>';

    if (!empty($config['prefix']) || !empty($config['suffix'])) {
        $input = '<div class="input-affix">'
            . (!empty($config['prefix']) ? '<span class="input-affix__text">' . e($config['prefix']) . '</span>' : '')
            . $input
            . (!empty($config['suffix']) ? '<span class="input-affix__text input-affix__text--end">' . e($config['suffix']) . '</span>' : '')
            . '</div>';
    }

    return '<div class="field">'
        . field_label($config, $id)
        . $input
        . field_foot($config)
        . '</div>';
}

/** Select field. options is a value => label map. */
function field_select(array $config): string
{
    $name    = $config['name'] ?? '';
    $id      = $config['id'] ?? ('f_' . preg_replace('/[^a-z0-9_]/i', '_', $name));
    $value   = (string)($config['value'] ?? '');
    $error   = $config['error'] ?? '';
    $options = $config['options'] ?? [];

    $html = '<select id="' . e($id) . '" name="' . e($name) . '" class="select' . ($error ? ' is-invalid' : '') . '"'
        . (!empty($config['required']) ? ' required' : '') . ' ' . ($config['attrs'] ?? '') . '>';

    if (isset($config['placeholder'])) {
        $html .= '<option value="">' . e($config['placeholder']) . '</option>';
    }
    foreach ($options as $optionValue => $optionLabel) {
        $html .= '<option value="' . e((string)$optionValue) . '"'
            . ((string)$optionValue === $value ? ' selected' : '') . '>' . e((string)$optionLabel) . '</option>';
    }
    $html .= '</select>';

    return '<div class="field">' . field_label($config, $id) . $html . field_foot($config) . '</div>';
}

function field_textarea(array $config): string
{
    $name = $config['name'] ?? '';
    $id   = $config['id'] ?? ('f_' . preg_replace('/[^a-z0-9_]/i', '_', $name));
    $html = '<textarea id="' . e($id) . '" name="' . e($name) . '" class="textarea' . (!empty($config['error']) ? ' is-invalid' : '') . '"'
        . (!empty($config['placeholder']) ? ' placeholder="' . e($config['placeholder']) . '"' : '')
        . ' rows="' . (int)($config['rows'] ?? 3) . '">' . e((string)($config['value'] ?? '')) . '</textarea>';
    return '<div class="field">' . field_label($config, $id) . $html . field_foot($config) . '</div>';
}

function field_checkbox(array $config): string
{
    $name = $config['name'] ?? '';
    $id   = $config['id'] ?? ('f_' . preg_replace('/[^a-z0-9_]/i', '_', $name));
    return '<div class="field"><label class="check" for="' . e($id) . '">'
        . '<input type="checkbox" id="' . e($id) . '" name="' . e($name) . '" value="1"'
        . (!empty($config['checked']) ? ' checked' : '') . ' ' . ($config['attrs'] ?? '') . '>'
        . '<span><b>' . e($config['label'] ?? '') . '</b>'
        . (!empty($config['hint']) ? '<div class="field__hint" style="margin-top:.1rem">' . e($config['hint']) . '</div>' : '')
        . '</span></label></div>';
}

function field_label(array $config, string $id): string
{
    if (empty($config['label'])) {
        return '';
    }
    return '<label class="field__label" for="' . e($id) . '">' . e($config['label'])
        . (!empty($config['required']) ? ' <span class="req">*</span>' : '') . '</label>';
}

function field_foot(array $config): string
{
    if (!empty($config['error'])) {
        return '<div class="field__error">' . e($config['error']) . '</div>';
    }
    if (!empty($config['hint'])) {
        return '<div class="field__hint">' . e($config['hint']) . '</div>';
    }
    return '';
}

/** Search box for a filter bar. */
function search_field(string $value, string $placeholder = 'Search…', string $name = 'q'): string
{
    return '<div class="field filter-bar__search"><div class="search-input">'
        . icon('search', 'ico--sm')
        . '<input type="search" class="input" name="' . e($name) . '" value="' . e($value) . '"'
        . ' placeholder="' . e($placeholder) . '" aria-label="' . e($placeholder) . '" data-search-submit>'
        . '</div></div>';
}

/** Select used inside a filter bar (auto-submits on change). */
function filter_select(string $name, string $value, array $options, string $placeholder): string
{
    $html = '<div class="field filter-bar__item">'
        . '<select name="' . e($name) . '" class="select" aria-label="' . e($placeholder) . '" data-auto-submit>'
        . '<option value="">' . e($placeholder) . '</option>';
    foreach ($options as $optionValue => $optionLabel) {
        $html .= '<option value="' . e((string)$optionValue) . '"' . ((string)$optionValue === $value ? ' selected' : '') . '>'
            . e((string)$optionLabel) . '</option>';
    }
    return $html . '</select></div>';
}

/** Date input used inside a filter bar. */
function filter_date(string $name, string $value, string $label): string
{
    $id = 'flt_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
    return '<div class="field filter-bar__item">'
        . '<label class="field__label tiny" for="' . e($id) . '">' . e($label) . '</label>'
        . '<input type="date" id="' . e($id) . '" class="input" name="' . e($name) . '"'
        . ' value="' . e($value) . '" data-auto-submit></div>';
}

/* ================================================================ tables */

/** Sortable column header link. */
function th_sort(string $label, string $column, string $currentSort): string
{
    [$currentColumn, $currentDirection] = array_pad(explode(' ', $currentSort), 2, 'DESC');
    $direction = ($currentColumn === $column && strtoupper($currentDirection) === 'ASC') ? 'DESC' : 'ASC';
    $marker = $currentColumn === $column ? (strtoupper($currentDirection) === 'ASC' ? '↑' : '↓') : '';
    return '<th class="sortable"><a href="' . e(query_string(['sort' => $column . ' ' . $direction])) . '">'
        . e($label) . ' <span class="faint">' . $marker . '</span></a></th>';
}

/**
 * Empty state block.
 *
 * @param array $config title, text, icon, action (html)
 */
function empty_state(array $config): string
{
    return '<div class="empty">'
        . '<div class="empty__icon">' . icon($config['icon'] ?? 'search', 'ico--lg') . '</div>'
        . '<div class="empty__title">' . e($config['title'] ?? 'Nothing here yet') . '</div>'
        . '<p class="empty__text">' . e($config['text'] ?? '') . '</p>'
        . ($config['action'] ?? '')
        . '</div>';
}

/**
 * Pagination bar built from the array Model::paginate() returns.
 */
function pagination(array $page, string $label = 'records'): string
{
    if (($page['total'] ?? 0) === 0) {
        return '';
    }

    $current = (int)$page['page'];
    $pages   = (int)$page['pages'];

    $html = '<div class="pagination"><div>Showing <b>' . number_format((int)$page['from']) . '</b>–<b>'
        . number_format((int)$page['to']) . '</b> of <b>' . number_format((int)$page['total']) . '</b> ' . e($label) . '</div>';

    if ($pages > 1) {
        $html .= '<div class="pagination__pages">';
        $html .= '<a class="pagination__link' . ($current <= 1 ? ' is-disabled' : '') . '" href="'
            . e(query_string(['page' => max(1, $current - 1)])) . '" aria-label="Previous page">‹</a>';

        $start = max(1, $current - 2);
        $end   = min($pages, $start + 4);
        $start = max(1, $end - 4);

        if ($start > 1) {
            $html .= '<a class="pagination__link" href="' . e(query_string(['page' => 1])) . '">1</a>';
            if ($start > 2) {
                $html .= '<span class="pagination__link is-disabled">…</span>';
            }
        }
        for ($i = $start; $i <= $end; $i++) {
            $html .= '<a class="pagination__link' . ($i === $current ? ' is-active' : '') . '" href="'
                . e(query_string(['page' => $i])) . '">' . $i . '</a>';
        }
        if ($end < $pages) {
            if ($end < $pages - 1) {
                $html .= '<span class="pagination__link is-disabled">…</span>';
            }
            $html .= '<a class="pagination__link" href="' . e(query_string(['page' => $pages])) . '">' . $pages . '</a>';
        }

        $html .= '<a class="pagination__link' . ($current >= $pages ? ' is-disabled' : '') . '" href="'
            . e(query_string(['page' => min($pages, $current + 1)])) . '" aria-label="Next page">›</a>';
        $html .= '</div>';
    }

    return $html . '</div>';
}

/* ================================================================= misc */

/** Progress meter with an automatic tone. */
function meter(float $percent, ?string $tone = null): string
{
    $percent = max(0, min(100, $percent));
    $tone = $tone ?? ($percent >= 90 ? 'danger' : ($percent >= 70 ? 'warning' : ''));
    return '<div class="meter"><div class="meter__fill' . ($tone ? ' meter__fill--' . e($tone) : '')
        . '" style="width:' . round($percent, 1) . '%"></div></div>';
}

/** Signal-bar indicator (0-100 health). */
function signal_bars(?int $health): string
{
    if ($health === null) {
        return '<span class="signal" title="Unknown"><i></i><i></i><i></i><i></i></span>';
    }
    $level = match (true) {
        $health >= 80 => 4,
        $health >= 55 => 3,
        $health >= 30 => 2,
        $health > 0   => 1,
        default       => 0,
    };
    return '<span class="signal signal--' . $level . '" title="Health ' . (int)$health . '%"><i></i><i></i><i></i><i></i></span>';
}

/** Chart container - the config is rendered by assets/js/app.js. */
function chart(array $config, string $height = '220px'): string
{
    return '<div class="chart" data-chart="' . e(json_encode($config, JSON_UNESCAPED_SLASHES) ?: '{}') . '" style="min-height:' . e($height) . '"></div>';
}

/** Modal shell. */
function modal(string $id, string $title, string $body, string $footer = '', string $panelClass = ''): string
{
    return '<div class="modal" id="' . e($id) . '" role="dialog" aria-modal="true" aria-label="' . e($title) . '">'
        . '<div class="modal__panel ' . e($panelClass) . '">'
        . '<div class="modal__head"><h3 class="modal__title">' . e($title) . '</h3>'
        . '<button type="button" class="modal__close" data-modal-close aria-label="Close">' . icon('x') . '</button></div>'
        . '<div class="modal__body">' . $body . '</div>'
        . ($footer !== '' ? '<div class="modal__foot">' . $footer . '</div>' : '')
        . '</div></div>';
}

/** Avatar bubble from a name. */
function avatar(?string $name, string $class = ''): string
{
    return '<span class="avatar ' . e($class) . '">' . e(initials($name)) . '</span>';
}

/** Two-line primary table cell. */
function cell_primary(string $title, string $subtitle = '', string $avatarHtml = '', string $href = ''): string
{
    $titleHtml = $href !== ''
        ? '<a class="link" href="' . e($href) . '">' . e($title) . '</a>'
        : e($title);
    return '<div class="cell-primary">' . $avatarHtml
        . '<div class="cell-primary__text"><div class="cell-primary__title">' . $titleHtml . '</div>'
        . ($subtitle !== '' ? '<div class="cell-primary__sub">' . e($subtitle) . '</div>' : '')
        . '</div></div>';
}

/** Monospace chip for codes, MAC and IP addresses. */
function code_chip(?string $value, bool $copyable = false): string
{
    if ($value === null || $value === '') {
        return '<span class="faint">—</span>';
    }
    $chip = '<span class="code-chip">' . e($value) . '</span>';
    if ($copyable) {
        return '<button type="button" class="btn btn--ghost btn--sm" data-copy="' . e($value) . '" title="Copy">'
            . $chip . icon('copy', 'ico--sm') . '</button>';
    }
    return $chip;
}

/**
 * Printable voucher card. Leaves a reserved square for a QR code so the
 * layout does not move when QR generation is added.
 */
/**
 * A voucher, as the card an operator prints and hands over.
 *
 * The branding comes from the current provider scope, which is right for
 * every real voucher. $brand and $ssid override it for the sample cards on
 * the platform's own landing page, which belong to no operator - without
 * that they wore whichever network the visitor last picked in the portal.
 */
function voucher_card(array $voucher, bool $withQr = true, ?string $brand = null, ?string $ssid = null): string
{
    $company = $brand ?? (string)setting('company_name', 'WMS');
    $note    = (string)setting('voucher_print_note', 'Connect to the Wi-Fi network, open any web page and enter this code.');
    $ssid    = $ssid ?? (string)setting('portal_ssid', 'WMS-Hotspot');

    return '<div class="voucher-card">'
        . '<div class="voucher-card__head">'
            . '<span class="voucher-card__brand">' . icon('wifi', 'ico--sm') . e($company) . '</span>'
            . '<span class="voucher-card__price">' . e(money((float)$voucher['price'])) . '</span>'
        . '</div>'
        . '<div class="voucher-card__body">'
            . ($withQr ? '<div class="voucher-card__qr">QR<br>space</div>' : '')
            . '<div class="voucher-card__label">Access code</div>'
            . '<div class="voucher-card__code">' . e($voucher['code']) . '</div>'
            . '<div class="voucher-card__specs">'
                . '<span><b>' . e(format_mb($voucher['data_limit_mb'] === null ? null : (int)$voucher['data_limit_mb'])) . '</b></span>'
                . '<span><b>' . e(format_package_duration((int)$voucher['duration_value'], (string)$voucher['duration_unit'])) . '</b></span>'
                . '<span><b>' . e(format_speed((int)$voucher['download_kbps'])) . '</b></span>'
            . '</div>'
        . '</div>'
        . '<div class="voucher-card__foot"><b>' . e($ssid) . '</b><br>' . e($note) . '</div>'
        . '</div>';
}

/**
 * Provider picker, shown only to a platform administrator in global scope.
 *
 * A provider user never sees it: their tenant comes from their session, and
 * the server ignores any provider_id they might post anyway. This exists so
 * a Super Admin creating a record can say which business it belongs to.
 */
function provider_selector(string $name = 'provider_id', string $value = '', bool $required = true): string
{
    if (!ProviderContext::isGlobalScope()) {
        return '';
    }

    try {
        $providers = (new Provider())->listAll(true);
    } catch (Throwable $e) {
        return '';
    }

    if (!$providers) {
        return alert_box('warning', 'There are no active providers yet. Create one before adding records.', 'No providers');
    }

    return '<fieldset class="fieldset">'
        . '<legend>Provider</legend>'
        . '<div class="form-grid">'
        . field_select([
            'name'     => $name,
            'label'    => 'Belongs to',
            'value'    => $value,
            'required' => $required,
            'placeholder' => 'Choose a provider',
            'options'  => array_column($providers, 'business_name', 'id'),
            'hint'     => 'You are in platform scope, so this record needs an owner. Provider staff never see this field.',
        ])
        . '</div></fieldset>';
}

/* ============================================================== network */

/**
 * Status chip for a router or access point.
 *
 * Never colour alone: every chip carries an icon and a word, so the state is
 * readable without seeing the colour at all.
 */
function network_status(?string $status, ?string $title = null): string
{
    $status = strtolower((string)$status);
    [$iconName, $text] = match ($status) {
        'online'   => ['check',   'Online'],
        'degraded' => ['alert',   'Degraded'],
        'offline'  => ['x',       'Offline'],
        'retired'  => ['history', 'Retired'],
        'disabled' => ['block',   'Disabled'],
        default    => ['info',    'Unknown'],
    };
    return '<span class="badge badge--' . status_tone($status) . '"'
        . ($title !== null ? ' title="' . e($title) . '"' : '') . '>'
        . icon($iconName, 'ico--sm') . e($text) . '</span>';
}

/** Live / Demo marker for a router row. */
function mode_badge(?string $mode): string
{
    return $mode === 'live'
        ? '<span class="badge badge--live" title="WMS contacts this router">' . icon('signal', 'ico--sm') . 'Live</span>'
        : '<span class="badge badge--demo" title="No commands are sent to this router">' . icon('info', 'ico--sm') . 'Demo</span>';
}

/**
 * Renders a network reading, or an honest "Unknown" when it was never
 * retrieved. Passing null must never come out as 0.
 */
function network_value(mixed $value, string $suffix = '', string $unknown = 'Unknown'): string
{
    if ($value === null || $value === '') {
        return '<span class="faint" title="Not reported by the device">' . e($unknown) . '</span>';
    }
    return '<b>' . e((string)$value . $suffix) . '</b>';
}

/**
 * The freshness line under a live reading.
 *
 * Old data is labelled as old rather than shown as if it were current.
 */
function freshness_note(array $router): string
{
    if (($router['mode'] ?? 'demo') !== 'live') {
        return '<span class="tiny muted">Demo mode - not contacted</span>';
    }
    if (empty($router['last_sync_at'])) {
        return '<span class="tiny muted">Never synced</span>';
    }
    $ago = time_ago((string)$router['last_sync_at']);
    return Router::isStale($router)
        ? '<span class="tiny" style="color:var(--wms-warning)">' . icon('alert', 'ico--sm')
            . ' Data may be outdated - last sync ' . e($ago) . '</span>'
        : '<span class="tiny muted">Last synced ' . e($ago) . '</span>';
}

/** Key/value definition list. */
function key_value(array $pairs): string
{
    $html = '<dl class="key-value">';
    foreach ($pairs as $key => $value) {
        $html .= '<dt>' . e((string)$key) . '</dt><dd>' . ($value === '' || $value === null ? '<span class="faint">—</span>' : $value) . '</dd>';
    }
    return $html . '</dl>';
}

/* ======================================================== network shell */

/**
 * The animated network backdrop.
 *
 * A dot field, three drifting waves and a signal bloom, painted in a fixed
 * layer behind everything. It is purely atmosphere - it carries no data, so
 * it is hidden from assistive technology and switched off entirely for
 * anyone who has asked for reduced motion (handled in net.css).
 *
 * $tone: 'light' on white pages, 'ink' on dark ones, 'quiet' in the admin
 * where the interface itself must stay the loudest thing on screen.
 */
function net_backdrop(string $tone = 'light'): string
{
    $tone = in_array($tone, ['light', 'ink', 'quiet'], true) ? $tone : 'light';

    return '<div class="net-bg net-bg--' . $tone . '" aria-hidden="true">'
        . '<div class="net-bg__layer net-bg__bloom"></div>'
        . '<div class="net-bg__layer net-bg__dots"></div>'
        . '<div class="net-bg__layer net-bg__dots--fine"></div>'
        . '<div class="net-bg__scan"></div>'
        . '<div class="net-bg__waves">'
            . '<div class="net-bg__wave net-bg__wave--1"></div>'
            . '<div class="net-bg__wave net-bg__wave--2"></div>'
            . '<div class="net-bg__wave net-bg__wave--3"></div>'
        . '</div>'
        . '</div>';
}

/**
 * The fixed bottom tab bar that turns a phone view into an application.
 *
 * Each item is ['label', 'icon', 'href', 'key', 'badge' => int, 'action' => bool].
 * 'action' raises the item into the centre button. $active matches on 'key'.
 *
 * It is rendered on every screen size and hidden above 860px in CSS, so the
 * markup - and therefore the links - are identical everywhere.
 */
function app_tabbar(array $items, string $active = ''): string
{
    if (!$items) {
        return '';
    }

    $html = '<nav class="app-tabbar" aria-label="Main">';
    foreach ($items as $item) {
        $isActive = ($item['key'] ?? '') === $active;
        $isAction = !empty($item['action']);
        $classes  = 'app-tab' . ($isAction ? ' app-tab--action' : '') . ($isActive ? ' is-active' : '');
        $badge    = (int)($item['badge'] ?? 0);

        $html .= '<a class="' . $classes . '" href="' . e(url((string)$item['href'])) . '"'
            . ($isActive ? ' aria-current="page"' : '') . '>';

        $html .= $isAction
            ? '<span class="app-tab__mark">' . icon((string)$item['icon']) . '</span>'
            : icon((string)$item['icon']);

        if ($badge > 0) {
            $html .= '<span class="app-tab__dot">' . ($badge > 9 ? '9+' : $badge) . '</span>';
        }

        $html .= '<span class="app-tab__label">' . e((string)$item['label']) . '</span></a>';
    }

    return $html . '</nav>';
}

/**
 * The network map that opens the public page.
 *
 * A diagram of what the system actually connects - one router, three access
 * points, the devices behind them - with packets travelling the links. The
 * numbers on it are labelled as an example, never dressed up as live data;
 * the figures that ARE live sit in the ticker underneath it.
 */
function net_map(array $tags = []): string
{
    $tags = $tags ?: [
        ['icon' => 'router',  'label' => 'Gateway',  'value' => 'online'],
        ['icon' => 'signal',  'label' => 'Throughput', 'value' => '84 Mb/s'],
        ['icon' => 'users',   'label' => 'Sessions', 'value' => '128'],
    ];

    $svg = <<<'SVG'
<svg class="netmap__svg" viewBox="0 0 400 352" role="img" aria-label="Diagram: one gateway router feeding three access points, which serve customer devices.">
    <!-- coverage haloes radiating from the gateway -->
    <circle class="netmap__halo" cx="200" cy="96" r="30"/>
    <circle class="netmap__halo netmap__halo--b" cx="200" cy="96" r="30"/>
    <circle class="netmap__halo netmap__halo--c" cx="200" cy="96" r="30"/>

    <!-- cloud to gateway -->
    <path class="netmap__link" d="M200 26 V 66"/>
    <path class="netmap__flow" d="M200 26 V 66"/>

    <!-- gateway to the three access points -->
    <path class="netmap__link" d="M200 126 C 200 170, 80 170, 80 208"/>
    <path class="netmap__link" d="M200 126 V 208"/>
    <path class="netmap__link" d="M200 126 C 200 170, 320 170, 320 208"/>
    <path class="netmap__flow netmap__flow--b" d="M200 126 C 200 170, 80 170, 80 208"/>
    <path class="netmap__flow netmap__flow--c" d="M200 126 V 208"/>
    <path class="netmap__flow netmap__flow--d" d="M200 126 C 200 170, 320 170, 320 208"/>

    <!-- access points to devices -->
    <path class="netmap__link" d="M80 244 V 292"/>
    <path class="netmap__link" d="M200 244 V 292"/>
    <path class="netmap__link" d="M320 244 V 292"/>

    <!-- the internet -->
    <g transform="translate(200 14)">
        <circle class="netmap__node" cx="0" cy="0" r="15"/>
        <g class="netmap__glyph" transform="translate(-7 -7) scale(0.875)">
            <circle cx="8" cy="8" r="6.2"/><path d="M1.8 8h12.4"/>
            <path d="M8 1.8a10 10 0 0 1 0 12.4A10 10 0 0 1 8 1.8"/>
        </g>
    </g>

    <!-- the gateway -->
    <g transform="translate(200 96)">
        <circle class="netmap__hub" cx="0" cy="0" r="30"/>
        <g class="netmap__glyph" transform="translate(-11 -11) scale(1.375)">
            <rect x="1.5" y="8.5" width="13" height="5.5" rx="1.2"/>
            <path d="M4.2 11.2v.01M6.6 11.2v.01"/><path d="M11.5 11.2h1.5"/>
            <path d="M8 8.5V6"/><path d="M5.6 4a3.4 3.4 0 0 1 4.8 0"/>
            <path d="M3.7 2.2a6 6 0 0 1 8.6 0"/>
        </g>
    </g>

    <!-- the access points -->
    <g transform="translate(80 226)">
        <circle class="netmap__node" cx="0" cy="0" r="18"/>
        <g class="netmap__glyph" transform="translate(-8 -8)">
            <path d="M8 14V7"/><circle cx="8" cy="5" r="1.8"/>
            <path d="M4.6 8.4a4.8 4.8 0 0 1 0-6.8"/><path d="M11.4 1.6a4.8 4.8 0 0 1 0 6.8"/>
        </g>
    </g>
    <g transform="translate(200 226)">
        <circle class="netmap__node" cx="0" cy="0" r="18"/>
        <g class="netmap__glyph" transform="translate(-8 -8)">
            <path d="M8 14V7"/><circle cx="8" cy="5" r="1.8"/>
            <path d="M4.6 8.4a4.8 4.8 0 0 1 0-6.8"/><path d="M11.4 1.6a4.8 4.8 0 0 1 0 6.8"/>
        </g>
    </g>
    <g transform="translate(320 226)">
        <circle class="netmap__node" cx="0" cy="0" r="18"/>
        <g class="netmap__glyph netmap__glyph--dim" transform="translate(-8 -8)">
            <path d="M8 14V7"/><circle cx="8" cy="5" r="1.8"/>
            <path d="M4.6 8.4a4.8 4.8 0 0 1 0-6.8"/><path d="M11.4 1.6a4.8 4.8 0 0 1 0 6.8"/>
        </g>
    </g>

    <!-- the customers -->
    <g class="netmap__glyph netmap__glyph--dim" transform="translate(72 296)">
        <rect x="4.5" y="1.5" width="7" height="13" rx="1.5"/><path d="M7 12.5h2"/>
    </g>
    <g class="netmap__glyph netmap__glyph--dim" transform="translate(192 296)">
        <rect x="4.5" y="1.5" width="7" height="13" rx="1.5"/><path d="M7 12.5h2"/>
    </g>
    <g class="netmap__glyph netmap__glyph--dim" transform="translate(312 296)">
        <rect x="4.5" y="1.5" width="7" height="13" rx="1.5"/><path d="M7 12.5h2"/>
    </g>
</svg>
SVG;

    $html = '<div class="netmap">' . $svg;
    $i = 0;
    foreach (array_slice($tags, 0, 3) as $tag) {
        $i++;
        $html .= '<span class="netmap__tag netmap__tag--' . $i . '">'
            . icon((string)$tag['icon'], 'ico--sm')
            . e((string)$tag['label']) . ' <b>' . e((string)$tag['value']) . '</b></span>';
    }

    return $html . '</div>';
}

/**
 * The wave that separates two bands of the page.
 *
 * $fill is the colour of the section the wave flows INTO, so the two always
 * meet without a seam.
 */
function wave_sep(string $fill = 'var(--wms-canvas)', bool $flip = false): string
{
    return '<div class="wave-sep' . ($flip ? ' wave-sep--flip' : '') . '" aria-hidden="true" style="color:' . e($fill) . '">'
        . '<svg viewBox="0 0 1200 58" preserveAspectRatio="none">'
        . '<path d="M0 34c120-30 260-30 380-6s250 34 380 10 310-34 440-14v34H0z" fill="currentColor"/>'
        . '</svg></div>';
}

/**
 * The portal's three-way switch: voucher, buy, sign in.
 *
 * It lives here rather than being repeated on each page because it kept
 * drifting - "Buy" was hard-coded on two of the three screens, so a network
 * with mobile money switched off still offered it. The tab is a courtesy;
 * the real gate is in PaymentService, which refuses the sale server side.
 *
 * $active: voucher | buy | account
 */
function portal_tabs(string $active, bool $canBuy): string
{
    $tabs = [
        ['key' => 'voucher', 'label' => 'Voucher', 'href' => 'customer/index.php',    'show' => true],
        ['key' => 'buy',     'label' => 'Buy',     'href' => 'customer/packages.php', 'show' => $canBuy],
        ['key' => 'account', 'label' => 'Sign in', 'href' => 'customer/login.php',    'show' => true],
    ];
    $tabs = array_values(array_filter($tabs, static fn(array $tab): bool => $tab['show']));

    $html = '<div class="portal-tabs" style="grid-template-columns:repeat(' . count($tabs) . ',1fr)">';
    foreach ($tabs as $tab) {
        $html .= $tab['key'] === $active
            ? '<span class="portal-tab is-active" aria-current="page">' . e($tab['label']) . '</span>'
            : '<a class="portal-tab" href="' . e(url($tab['href'])) . '">' . e($tab['label']) . '</a>';
    }
    return $html . '</div>';
}
