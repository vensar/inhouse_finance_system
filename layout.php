<?php

function ecHeader($title, $active)
{
    $name = ecEscape($_SESSION['full_name'] ?? '');
    $role = $_SESSION['role'] ?? '';
    $catalog = [
        'Payments' => [
            ['index', 'Overview', 'bi-grid-1x2', 'dashboard.view'],
            ['payments', 'Payments', 'bi-cash-stack', 'payments.view'],
            ['failed_payments', 'Failed payments', 'bi-exclamation-circle', 'failed_payments.view'],
            ['lots', 'Payment lots', 'bi-collection', 'lots.manage'],
            ['telecom', 'Telecom verification', 'bi-phone', 'telecom.verify'],
            ['bulk_search', 'Bulk search', 'bi-search', 'bulk_search.use'],
        ],
        'Communication' => [
            ['chat', 'Messages', 'bi-chat-dots', 'chat.use'],
        ],
        'Accountability' => [
            ['accountability', 'District accountability', 'bi-receipt-cutoff', 'accountability.district'],
            ['quarterly_office_accountability', 'Quarterly office Imprest', 'bi-calendar3', 'accountability.quarterly_office'],
            ['travel_advances', 'Travel allowance', 'bi-airplane', 'accountability.travel'],
            ['individual_advances', 'Individual allowance', 'bi-person-vcard', 'accountability.individual'],
            ['accountability_reports', 'Accountability reports', 'bi-bar-chart-line', 'accountability.reports'],
            ['offices', 'Office registry', 'bi-buildings', 'offices.view'],
        ],
        'Administration' => [
            ['telecom_access', 'API access', 'bi-key', 'telecom_access.manage'],
            ['upload', 'Bulk upload', 'bi-cloud-arrow-up', 'payments.upload'],
            ['users', 'Users & permissions', 'bi-people', 'users.manage'],
        ],
    ];
    $navigation = [];
    foreach ($catalog as $group => $items) {
        foreach ($items as $item) {
            if (ecHasPermission($item[3])) {
                $navigation[$group][] = $item;
            }
        }
    }
    $firstItem = null;
    foreach ($navigation as $items) {
        if ($items) {
            $firstItem = $items[0];
            break;
        }
    }
    $home = $firstItem[0] ?? 'profile';
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?= ecEscape($title) ?> | In House Finance Management</title>
        <link rel="icon" href="logo.jpg">
        <link rel="stylesheet" href="assets/bootstrap.min.css">
        <link rel="stylesheet" href="app.css?v=20261008b">
        <link rel="stylesheet" href="assets-theme.css?v=20261008a">
        <link rel="stylesheet" href="assets/bootstrap-icons/bootstrap-icons.min.css">
    </head>
    <body>
        <header class="app-header">
            <div class="header-inner">
                <button class="nav-toggle" type="button" aria-label="Open navigation" aria-controls="app-sidebar" aria-expanded="false"><i class="bi bi-list"></i></button>
                <a class="brand" href="<?= $home ?>"><img src="logo.jpg" width="42" height="42" alt="Electoral Commission"><span>In House Finance Management<small>Electoral Commission</small></span></a>
                <div class="header-user">
                    <span><?= $name ?></span>
                    <a class="header-icon profile-icon" href="profile" title="Open my profile" aria-label="Open my profile"><i class="bi bi-person-circle"></i></a>
                    <a class="header-icon logout-icon" href="logout" title="Sign out" aria-label="Sign out"><i class="bi bi-box-arrow-right"></i></a>
                </div>
            </div>
        </header>
        <div class="app-shell">
            <aside class="app-sidebar" id="app-sidebar">
                <div class="sidebar-user"><span class="sidebar-avatar"><?= ecEscape(strtoupper(substr($name, 0, 1))) ?></span><div><strong><?= $name ?></strong><small><?= ecEscape(ucwords(str_replace('_', ' ', $role))) ?></small></div></div>
                <nav class="app-nav" aria-label="Main navigation">
                    <?php foreach ($navigation as $group => $items): ?>
                        <div class="nav-group">
                            <div class="nav-group-label"><?= ecEscape($group) ?></div>
                            <?php foreach ($items as [$path, $label, $icon]): ?>
                                <a href="<?= $path ?>" <?= $active === $path ? 'aria-current="page"' : '' ?>><i class="bi <?= $icon ?>"></i><span><?= $label ?></span></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$navigation): ?><div class="sidebar-empty">No modules have been granted. Contact an administrator.</div><?php endif; ?>
                </nav>
            </aside>
            <button class="nav-scrim" type="button" aria-label="Close navigation"></button>
            <main class="app-main">
    <?php
}

function ecFooter()
{
    ?>
            </main>
        </div>
        <script>
            (() => {
                const body = document.body;
                const toggle = document.querySelector('.nav-toggle');
                const scrim = document.querySelector('.nav-scrim');
                if (!toggle) return;
                const close = () => {
                    body.classList.remove('nav-open');
                    toggle.setAttribute('aria-expanded', 'false');
                };
                toggle.addEventListener('click', () => {
                    const open = body.classList.toggle('nav-open');
                    toggle.setAttribute('aria-expanded', String(open));
                });
                scrim?.addEventListener('click', close);
                document.querySelectorAll('.app-nav a').forEach((link) => link.addEventListener('click', close));
                window.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') close();
                });
            })();
        </script>
    </body>
    </html>
    <?php
}
