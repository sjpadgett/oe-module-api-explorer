<?php

/**
 * ui_panel.php — the collapsible panel used by every feature group in the Explorer.
 *
 * One header treatment for all of them, so the page reads as a stack of peers rather than a
 * pile of differently-shaped cards. Each panel is a Bootstrap 4 collapse; the open/closed state
 * is remembered per panel in localStorage by explorerPanels.init() (see oeApiExplorer.php),
 * which matters here because every Fetch is a full page load -- without persistence the layout
 * would reset on every request and collapsing would make the tool worse, not better.
 *
 * Usage:
 *     explorer_panel_open('fhirWrite', 'FHIR writes', ['subtitle' => 'POST / PUT', 'open' => false]);
 *     ... panel body ...
 *     explorer_panel_close();
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

/**
 * Opens a collapsible panel. Must be paired with explorer_panel_close().
 *
 * @param string $id     Stable id -- also the localStorage key, so don't churn it
 * @param string $title  Panel heading
 * @param array{
 *     subtitle?: string,
 *     badge?: string,
 *     badgeClass?: string,
 *     open?: bool,
 *     bodyClass?: string
 * } $opts
 */
function explorer_panel_open(string $id, string $title, array $opts = []): void
{
    $bodyId = $id . 'PanelBody';
    // The server-rendered state is the default; explorerPanels.init() overrides it from
    // localStorage on load when the user has expressed a preference for this panel.
    $open = (bool) ($opts['open'] ?? false);
    $subtitle = (string) ($opts['subtitle'] ?? '');
    $badge = (string) ($opts['badge'] ?? '');
    // Neutral by default: Bootstrap's contextual badges assume a light page, and this one
    // follows whichever OpenEMR theme is active.
    $badgeClass = (string) ($opts['badgeClass'] ?? 'explorer-chip');
    $bodyClass = (string) ($opts['bodyClass'] ?? '');
    ?>
    <section class="card mb-3 explorer-panel" id="<?= attr($id) ?>Panel">
        <div class="card-header explorer-panel-header p-0">
            <button
                type="button"
                class="btn btn-link btn-block text-left text-decoration-none explorer-panel-toggle"
                data-toggle="collapse"
                data-target="#<?= attr($bodyId) ?>"
                data-panel-id="<?= attr($id) ?>"
                aria-expanded="<?= $open ? 'true' : 'false' ?>"
                aria-controls="<?= attr($bodyId) ?>"
            >
                <span class="explorer-panel-chevron" aria-hidden="true">&#9656;</span>
                <span class="explorer-panel-title"><?= text($title) ?></span>
                <?php if ($subtitle !== '') : ?>
                    <span class="explorer-panel-subtitle"><?= text($subtitle) ?></span>
                <?php endif; ?>
                <?php if ($badge !== '') : ?>
                    <span class="<?= attr($badgeClass) ?> ml-2"><?= text($badge) ?></span>
                <?php endif; ?>
            </button>
        </div>
        <div id="<?= attr($bodyId) ?>" class="collapse<?= $open ? ' show' : '' ?>">
            <div class="card-body <?= attr($bodyClass) ?>">
    <?php
}

/**
 * Closes the panel opened by explorer_panel_open().
 */
function explorer_panel_close(): void
{
    ?>
            </div>
        </div>
    </section>
    <?php
}
