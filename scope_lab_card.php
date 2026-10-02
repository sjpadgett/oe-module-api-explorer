<?php

/**
 * scope_lab_card.php — Scope Lab panel: are the server's scopes reliable across grant types
 * and across SMART v1, v2 and mixed scope sets?
 *
 * For every profile in scope_lab_profiles.php it registers a dedicated client, gets a token,
 * compares requested / granted / JWT / introspection as effective c-r-u-d-s permissions, and
 * probes the API to check each permission is actually enforced. All server work happens in
 * scope_lab.php; the browser never holds a token.
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

?>
<style>
    .sl-badge { display: inline-block; min-width: 2.6rem; padding: .05rem .4rem; border-radius: .2rem; font-size: .72rem; text-align: center; border: 1px solid var(--xp-border); }
    .sl-pass { background: rgba(40, 167, 69, .22); border-color: rgba(40, 167, 69, .6); }
    .sl-fail { background: rgba(220, 53, 69, .25); border-color: rgba(220, 53, 69, .7); font-weight: 600; }
    .sl-warn { background: rgba(255, 193, 7, .22); border-color: rgba(255, 193, 7, .7); }
    .sl-info { background: rgba(23, 162, 184, .18); border-color: rgba(23, 162, 184, .6); }
    .sl-skip { opacity: .55; }
    .sl-v1 { background: rgba(108, 117, 125, .25); }
    .sl-v2 { background: rgba(13, 110, 253, .22); }
    .sl-mixed { background: rgba(111, 66, 193, .25); }
    .sl-scopes { font-family: var(--font-monospace, monospace); font-size: .72rem; word-break: break-all; }
    .sl-table { font-size: .8rem; }
    .sl-table td, .sl-table th { padding: .25rem .4rem; vertical-align: top; }
    .sl-table tr.sl-na { opacity: .45; }
    .sl-run-row { cursor: pointer; }
    .sl-run-row:hover { background: var(--xp-surface-hover); }
    .sl-detail { background: var(--xp-surface-raised); border-radius: .25rem; padding: .75rem; }
    .sl-grid td { text-align: center; min-width: 2rem; }
    .sl-grid td.sl-res { text-align: left; font-family: var(--font-monospace, monospace); }
    .sl-profile-wrap { max-height: 28rem; overflow: auto; }
    .sl-note { color: var(--xp-muted); font-size: .72rem; }
</style>

<?php explorer_panel_open('scopeLab', 'Scope Lab', ['subtitle' => 'v1 / v2 / mixed scopes × grant types — grant and enforcement checks']); ?>

    <p class="small text-muted mb-2">
        Each profile registers its own throwaway client (<code>&lt;site&gt; ScopeLab &lt;profile&gt; &lt;grant&gt;</code>),
        gets a token, and compares <em>requested → granted → JWT → introspection</em> as effective
        <code>c r u d s</code> permissions. It then probes the API to check each permission is enforced —
        reads use <code>_count=1</code> or a fake id, writes send an empty <code>{}</code> body so validation
        refuses them after the scope check. <strong>401/403</strong> = scope refused;
        <strong>400/404/422</strong> = scope accepted. Probes are scored against what the token
        <em>says</em> it carries (enforcement) and against what was <em>asked for</em> (a lost write shows there).
    </p>

    <div class="form-row align-items-end">
        <div class="form-group col-md-3">
            <label class="small mb-1" for="slGrant">Grant</label>
            <select id="slGrant" class="form-control form-control-sm"></select>
        </div>
        <div class="form-group col-md-2">
            <label class="small mb-1" for="slContext">Context</label>
            <select id="slContext" class="form-control form-control-sm"></select>
        </div>
        <div class="form-group col-md-7">
            <button type="button" id="slDiscoverBtn" class="btn btn-outline-secondary btn-sm">Discover scopes</button>
            <label class="small ml-3 mb-0"><input type="checkbox" id="slProbeWrites" checked> write probes</label>
            <label class="small ml-2 mb-0"><input type="checkbox" id="slRefresh" checked> refresh tests</label>
            <span id="slDiscovery" class="small ml-2"></span>
        </div>
    </div>

    <div id="slPasswordRow" class="form-row" style="display:none;">
        <div class="form-group col-md-3">
            <input id="slUser" class="form-control form-control-sm" placeholder="username" autocomplete="off">
        </div>
        <div class="form-group col-md-3">
            <input id="slPass" type="password" class="form-control form-control-sm" placeholder="password" autocomplete="new-password">
        </div>
        <div class="form-group col-md-3">
            <input id="slEmail" class="form-control form-control-sm" placeholder="email (patient role only)" autocomplete="off">
        </div>
        <div class="form-group col-md-3 small text-muted">
            Sent with each run, never stored. Needs the password grant enabled in Globals.
        </div>
    </div>

    <div id="slInteractiveNote" class="alert alert-info small py-2" style="display:none;">
        Auth-code runs go through the login + consent screen, one profile at a time.
        <strong>Leave every scope checked and approve</strong> — anything missing from the grant is then
        the server's or the consent form's doing, which is exactly what this is looking for.
        You come back here and the run is analysed automatically.
    </div>

    <div class="small mb-1">
        Select:
        <button type="button" class="btn btn-link btn-sm py-0 sl-pick" data-pick="applicable">all applicable</button>
        <button type="button" class="btn btn-link btn-sm py-0 sl-pick" data-pick="v1">v1</button>
        <button type="button" class="btn btn-link btn-sm py-0 sl-pick" data-pick="v2">v2</button>
        <button type="button" class="btn btn-link btn-sm py-0 sl-pick" data-pick="mixed">mixed</button>
        <button type="button" class="btn btn-link btn-sm py-0 sl-pick" data-pick="negative">negative</button>
        <button type="button" class="btn btn-link btn-sm py-0 sl-pick" data-pick="edge">edge</button>
        <button type="button" class="btn btn-link btn-sm py-0 sl-pick" data-pick="none">none</button>
    </div>
    <div class="sl-profile-wrap mb-2">
        <table class="table table-sm sl-table mb-0">
            <thead>
                <tr><th></th><th>Profile</th><th>Ver</th><th>Scopes (registered → requested)</th><th>Expect</th></tr>
            </thead>
            <tbody id="slProfiles"></tbody>
        </table>
    </div>

    <div class="form-row">
        <div class="form-group col-md-6 mb-2">
            <label class="small mb-1" for="slCustomRegister">Custom — register <span class="sl-note">({ctx} → context; base scopes added)</span></label>
            <textarea id="slCustomRegister" rows="2" class="form-control form-control-sm sl-scopes" placeholder="{ctx}/Patient.rs {ctx}/Patient.write"></textarea>
        </div>
        <div class="form-group col-md-6 mb-2">
            <label class="small mb-1" for="slCustomRequest">Custom — request <span class="sl-note">(blank = same as register)</span></label>
            <textarea id="slCustomRequest" rows="2" class="form-control form-control-sm sl-scopes"></textarea>
        </div>
    </div>

    <div class="mb-2">
        <button type="button" id="slRunBtn" class="btn btn-primary btn-sm">Run selected</button>
        <button type="button" id="slRunCustomBtn" class="btn btn-outline-primary btn-sm">Run custom</button>
        <span class="ml-3"></span>
        <button type="button" id="slMarkdownBtn" class="btn btn-outline-secondary btn-sm">Copy Markdown</button>
        <a id="slExportBtn" href="scope_lab.php?action=export" class="btn btn-outline-secondary btn-sm">Export JSON</a>
        <button type="button" id="slClearBtn" class="btn btn-outline-warning btn-sm">Clear runs</button>
        <button type="button" id="slPurgeBtn" class="btn btn-outline-danger btn-sm" title="Also deletes this site's ScopeLab clients from oauth_clients">Clear + delete lab clients</button>
        <span id="slSpinner" class="ml-2 small text-muted" style="display:none;">working…</span>
    </div>

    <pre id="slLog" class="explorer-log mb-2"></pre>

    <div id="slRunsWrap" style="display:none;">
        <table class="table table-sm sl-table mb-0">
            <thead>
                <tr>
                    <th>Run</th><th>Profile</th><th>Grant / ctx</th><th>Ver</th>
                    <th title="Registration accepted/refused as predicted">Reg</th>
                    <th title="Token issued/refused as predicted">Token</th>
                    <th title="Granted permissions equal requested ∩ registered">Grant</th>
                    <th title="JWT scopes claim agrees with the token response">JWT</th>
                    <th title="Introspection agrees with the token response">Intro</th>
                    <th title="Enforcement: probes vs the granted scopes">Probes</th>
                    <th title="Refresh: same / superset refused / subset narrowed">Refresh</th>
                    <th>Overall</th>
                </tr>
            </thead>
            <tbody id="slRuns"></tbody>
        </table>
    </div>

<?php explorer_panel_close(); ?>

<script>
(function () {
    const CSRF = <?= json_encode((string) ($_SESSION['smart_qr_csrf'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const STORE = 'oeApiExplorer.scopeLab';
    const $ = (id) => document.getElementById(id);
    const grantSel = $('slGrant');
    const ctxSel = $('slContext');
    const profilesBody = $('slProfiles');
    const runsBody = $('slRuns');
    const out = $('slLog');
    const spin = $('slSpinner');

    let state = null;
    let runs = [];
    let openRun = null;
    const selected = new Set();

    function prefs (update) {
        let current = {};
        try { current = JSON.parse(window.localStorage.getItem(STORE) || '{}') || {}; } catch (e) { current = {}; }
        if (update) {
            current = Object.assign(current, update);
            try { window.localStorage.setItem(STORE, JSON.stringify(current)); } catch (e) { /* best effort */ }
        }
        return current;
    }

    function esc (value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function log (msg, reset) {
        out.style.display = 'block';
        out.textContent = (reset ? '' : out.textContent + '\n') + msg;
        out.scrollTop = out.scrollHeight;
    }

    function busy (on) {
        spin.style.display = on ? 'inline' : 'none';
        ['slRunBtn', 'slRunCustomBtn', 'slDiscoverBtn', 'slClearBtn', 'slPurgeBtn'].forEach(id => { $(id).disabled = on; });
    }

    async function api (action, body, params) {
        const query = new URLSearchParams(Object.assign({ action: action }, params || {}));
        const init = { credentials: 'same-origin' };
        if (body !== undefined) {
            init.method = 'POST';
            init.headers = { 'Content-Type': 'application/json', 'X-Explorer-CSRF': CSRF };
            init.body = JSON.stringify(body);
        }
        const res = await fetch('scope_lab.php?' + query, init);
        const data = await res.json().catch(() => ({ error: 'non-JSON response (session expired?)' }));
        return { ok: res.ok, data: data };
    }

    function badge (verdict, text) {
        const v = verdict || 'skip';
        return '<span class="sl-badge sl-' + esc(v) + '">' + esc(text || v) + '</span>';
    }

    function verBadge (version) {
        return '<span class="sl-badge sl-' + esc(version) + '">' + esc(version) + '</span>';
    }

    // ------------------------------------------------------------------ state

    async function loadState () {
        const { ok, data } = await api('state', undefined, { grant: grantSel.value || prefs().grant || 'client_credentials', context: ctxSel.value || prefs().context || '' });
        if (!ok) { log('State error: ' + (data.error || '')); return; }
        state = data;
        runs = data.runs || [];
        renderGrantControls();
        renderDiscovery();
        renderProfiles();
        renderRuns();
    }

    function renderGrantControls () {
        grantSel.innerHTML = '';
        Object.entries(state.grants).forEach(([key, g]) => {
            const o = document.createElement('option');
            o.value = key; o.textContent = g.label; o.selected = key === state.grant;
            grantSel.appendChild(o);
        });
        ctxSel.innerHTML = '';
        state.grants[state.grant].contexts.forEach(c => {
            const o = document.createElement('option');
            o.value = c; o.textContent = c; o.selected = c === state.context;
            ctxSel.appendChild(o);
        });
        const g = state.grants[state.grant];
        $('slPasswordRow').style.display = state.grant === 'password' ? 'flex' : 'none';
        $('slInteractiveNote').style.display = g.interactive ? 'block' : 'none';
        $('slEmail').style.display = state.context === 'patient' ? 'block' : 'none';
    }

    function renderDiscovery () {
        const d = state.discovery;
        const el = $('slDiscovery');
        if (!d) {
            el.innerHTML = '<span class="sl-note">not discovered — expectations assume every scope is published</span>';
            return;
        }
        const s = d.summary || {};
        const caps = (d.capabilities || []).filter(c => c.indexOf('permission-') === 0);
        el.innerHTML = badge('info', d.scopes_supported.length + ' scopes') + ' ' +
            badge('skip', 'v1 ' + (s.v1 || 0)) + ' ' + badge('skip', 'v2 ' + (s.v2 || 0)) + ' ' +
            badge((s.write || 0) > 0 ? 'info' : 'skip', 'write ' + (s.write || 0)) + ' ' +
            badge('skip', 'granular ' + (s.granular || 0)) + ' ' +
            (caps.length ? caps.map(c => badge('pass', c)).join(' ') : badge('warn', 'no permission-v* capability')) +
            ((s.invalid || []).length ? ' ' + badge('fail', (s.invalid.length) + ' malformed published') : '');
        el.title = 'Discovered ' + d.fetched + ' from ' + d.url;
    }

    function renderProfiles () {
        profilesBody.innerHTML = '';
        state.profiles.forEach(p => {
            const tr = document.createElement('tr');
            if (!p.applicable) tr.className = 'sl-na';
            const same = p.registered.join(' ') === p.requested.join(' ');
            const pf = p.preflight;
            const base = (state.base[state.context] || '').split(/\s+/);
            const own = (list) => list.filter(s => base.indexOf(s) === -1).join(' ');
            const unpub = pf.unpublished.length ? '<div class="sl-note">unpublished: ' + esc(pf.unpublished.join(' ')) + '</div>' : '';
            const invalid = pf.invalid.length ? '<div class="sl-note">malformed: ' + esc(pf.invalid.map(i => i.scope).join(' ')) + '</div>' : '';
            tr.innerHTML =
                '<td><input type="checkbox" data-id="' + esc(p.id) + '"' + (p.applicable ? '' : ' disabled') + (selected.has(p.id) && p.applicable ? ' checked' : '') + '></td>' +
                '<td><strong>' + esc(p.label) + '</strong><div class="sl-note">' + esc(p.id) + ' — ' + esc(p.note) + '</div></td>' +
                '<td>' + verBadge(pf.version) + '</td>' +
                '<td class="sl-scopes">' + esc(own(p.registered)) + (same ? '' : '<div>→ ' + esc(own(p.requested)) + '</div>') + unpub + invalid + '</td>' +
                '<td class="small">reg ' + badge(pf.expect.registration === 'accept' ? 'pass' : 'info', pf.expect.registration) +
                '<br>token ' + badge(pf.expect.token === 'accept' ? 'pass' : 'info', pf.expect.token) +
                '<div class="sl-note">' + esc(pf.basis) + '</div></td>';
            tr.querySelector('input').addEventListener('change', (ev) => {
                if (ev.target.checked) selected.add(p.id); else selected.delete(p.id);
                prefs({ selected: Array.from(selected) });
            });
            profilesBody.appendChild(tr);
        });
    }

    // ------------------------------------------------------------------ runs table

    function renderRuns () {
        $('slRunsWrap').style.display = runs.length ? 'block' : 'none';
        runsBody.innerHTML = '';
        runs.slice().reverse().forEach(run => {
            const v = run.verdicts || {};
            const pc = v.probeCounts || {};
            const tr = document.createElement('tr');
            tr.className = 'sl-run-row';
            tr.innerHTML =
                '<td class="small">' + esc(run.id) + '</td>' +
                '<td>' + esc(run.label) + '</td>' +
                '<td class="small">' + esc(run.grant) + ' / ' + esc(run.context) + '</td>' +
                '<td>' + verBadge(run.preflight.version) + '</td>' +
                '<td>' + badge(v.registration) + '</td>' +
                '<td>' + badge(v.token) + '</td>' +
                '<td>' + badge(v.grant) + '</td>' +
                '<td>' + badge(v.jwt) + '</td>' +
                '<td>' + badge(v.introspect) + '</td>' +
                '<td>' + (v.probes && v.probes !== 'skip'
                    ? badge(v.probes, (pc.pass || 0) + '✓ ' + (pc.fail || 0) + '✗' + (pc.warn ? ' ' + pc.warn + '?' : ''))
                    : badge(run.token && run.token.ok && !run.analyzed ? 'info' : 'skip', run.token && run.token.ok && !run.analyzed ? 'pending' : 'skip')) + '</td>' +
                '<td>' + badge(v.refresh) + '</td>' +
                '<td>' + badge(v.overall) + '</td>';
            tr.addEventListener('click', () => { openRun = openRun === run.id ? null : run.id; renderRuns(); });
            runsBody.appendChild(tr);
            if (openRun === run.id) {
                const detail = document.createElement('tr');
                detail.innerHTML = '<td colspan="12">' + renderDetail(run) + '</td>';
                runsBody.appendChild(detail);
                bindDetail(detail, run);
            }
        });
    }

    function scopeLine (label, list, extra) {
        return '<div class="mb-1"><span class="small font-weight-bold">' + esc(label) + '</span>' + (extra ? ' <span class="sl-note">' + esc(extra) + '</span>' : '') +
            '<div class="sl-scopes">' + (list && list.length ? esc(list.join(' ')) : '<span class="sl-note">—</span>') + '</div></div>';
    }

    function diffLines (diff, title) {
        if (!diff) return '';
        const rows = [];
        Object.entries(diff.lost || {}).forEach(([k, p]) => rows.push(badge('fail', 'lost') + ' <code>' + esc(k) + '</code> ' + esc(p.join(''))));
        Object.entries(diff.gained || {}).forEach(([k, p]) => rows.push(badge('fail', 'gained') + ' <code>' + esc(k) + '</code> ' + esc(p.join(''))));
        (diff.lostGranular || []).forEach(s => rows.push(badge('fail', 'lost') + ' <code>' + esc(s) + '</code>'));
        (diff.gainedGranular || []).forEach(s => rows.push(badge('fail', 'gained') + ' <code>' + esc(s) + '</code>'));
        (diff.lostOther || []).forEach(s => rows.push(badge('warn', 'lost') + ' <code>' + esc(s) + '</code>'));
        (diff.gainedOther || []).forEach(s => rows.push(badge('warn', 'gained') + ' <code>' + esc(s) + '</code>'));
        if (diff.equivalent && ((diff.missingStrings || []).length || (diff.extraStrings || []).length)) {
            rows.push(badge('info', 'respelled') + ' <span class="sl-scopes">' + esc((diff.missingStrings || []).join(' ')) + ' ⇒ ' + esc((diff.extraStrings || []).join(' ')) + '</span>');
        }
        if (!rows.length) rows.push(badge('pass', 'equivalent'));
        return '<div class="mb-2"><div class="small font-weight-bold">' + esc(title) + '</div>' + rows.map(r => '<div class="small">' + r + '</div>').join('') + '</div>';
    }

    function permGrid (run) {
        const exp = (run.effective && run.effective.expected) || { plain: {} };
        const got = (run.effective && run.effective.granted) || null;
        const keys = Array.from(new Set(Object.keys(exp.plain || {}).concat(got ? Object.keys(got.plain || {}) : []))).sort();
        if (!keys.length) return '';
        const perms = ['c', 'r', 'u', 'd', 's'];
        let html = '<table class="table table-sm sl-table sl-grid mb-2"><thead><tr><th class="text-left">expected ⇢ granted</th>' +
            perms.map(p => '<th>' + p + '</th>').join('') + '</tr></thead><tbody>';
        keys.forEach(k => {
            html += '<tr><td class="sl-res">' + esc(k) + '</td>';
            perms.forEach(p => {
                const e = !!((exp.plain[k] || {})[p]);
                const g = got ? !!((got.plain[k] || {})[p]) : null;
                let cls = 'skip'; let txt = '';
                if (g === null) { cls = e ? 'info' : 'skip'; txt = e ? '•' : ''; }
                else if (e && g) { cls = 'pass'; txt = '✓'; }
                else if (e && !g) { cls = 'fail'; txt = 'lost'; }
                else if (!e && g) { cls = 'fail'; txt = 'gained'; }
                html += '<td>' + (txt ? badge(cls, txt) : '') + '</td>';
            });
            html += '</tr>';
        });
        return html + '</tbody></table>';
    }

    function probeTable (run) {
        if (!run.probes || !run.probes.length) return '';
        let html = '<table class="table table-sm sl-table mb-2"><thead><tr><th>API</th><th>Resource</th><th>Perm</th><th>Request</th><th>Status</th>' +
            '<th title="Prediction from the granted scopes">Expect</th><th title="Enforcement vs granted">vs granted</th><th title="vs what was asked for">vs asked</th><th>Detail</th></tr></thead><tbody>';
        run.probes.forEach(p => {
            html += '<tr>' +
                '<td>' + esc(p.api) + '</td>' +
                '<td>' + esc(p.resource) + (p.control ? ' <span class="sl-note">control</span>' : '') + '</td>' +
                '<td>' + esc(p.perm) + '</td>' +
                '<td class="sl-scopes">' + esc(p.method + ' ' + p.path) + '<div class="sl-note">' + esc(p.label) + '</div></td>' +
                '<td>' + esc(p.status) + ' <span class="sl-note">' + esc(p.outcome) + '</span></td>' +
                '<td>' + esc(p.predictGranted) + '</td>' +
                '<td>' + badge(p.vsGranted) + '</td>' +
                '<td>' + badge(p.vsExpected) + '</td>' +
                '<td class="small">' + esc(p.detail || '') + '</td>' +
                '</tr>';
        });
        return html + '</tbody></table>';
    }

    function refreshTable (run) {
        if (!run.refresh || !run.refresh.length) return '';
        let html = '<table class="table table-sm sl-table mb-2"><thead><tr><th>Refresh</th><th>Expected</th><th>Result</th><th>Granted</th><th>Verdict</th></tr></thead><tbody>';
        run.refresh.forEach(r => {
            html += '<tr><td>' + esc(r.name) + '</td><td class="small">' + esc(r.expected) + '</td>' +
                '<td class="small">' + esc(r.token.ok ? 'issued (' + r.token.status + ')' : 'refused (' + r.token.status + ') ' + (r.token.error || '')) + '</td>' +
                '<td class="sl-scopes">' + esc((r.token.granted || []).join(' ')) + '</td><td>' + badge(r.verdict) + '</td></tr>';
        });
        return html + '</tbody></table>';
    }

    function renderDetail (run) {
        const reg = run.registration || {};
        const tok = run.token || {};
        const intro = run.introspect || {};
        let html = '<div class="sl-detail">';
        html += '<div class="small mb-2"><strong>' + esc(run.label) + '</strong> — ' + esc(run.note) + '</div>';
        html += '<div class="row"><div class="col-lg-6">';
        html += scopeLine('Registered', run.registered);
        if (reg.echoed) html += scopeLine('Registration echoed', reg.echoed);
        html += scopeLine('Requested', run.requested);
        html += scopeLine('Expected grant (requested ∩ registered)', run.expectedGranted);
        html += scopeLine('Granted', tok.granted, tok.grantedSource ? 'from ' + tok.grantedSource : '');
        if (tok.claimScopes) html += scopeLine('JWT scopes claim', tok.claimScopes);
        if (intro.scopes) html += scopeLine('Introspection', intro.scopes, intro.active ? 'active' : 'INACTIVE');
        html += '</div><div class="col-lg-6">';
        html += '<div class="small mb-2">Registration: ' + badge(reg.ok ? 'pass' : 'info', reg.ok ? 'accepted' : 'refused') + ' ' + esc(reg.status || '') +
            (reg.error ? ' <span class="sl-note">' + esc(reg.error) + '</span>' : '') + (reg.note ? '<div class="sl-note">' + esc(reg.note) + '</div>' : '') +
            ' · expected ' + esc(run.preflight.expect.registration) + '</div>';
        if (run.token) {
            html += '<div class="small mb-2">Token: ' + badge(tok.ok ? 'pass' : 'info', tok.ok ? 'issued' : 'refused') + ' ' + esc(tok.status || '') +
                (tok.error ? ' <span class="sl-note">' + esc(tok.error) + '</span>' : '') +
                (tok.ok ? ' · refresh ' + (tok.hasRefresh ? 'yes' : 'no') + (tok.patient ? ' · patient ' + esc(tok.patient) : '') : '') +
                ' · expected ' + esc(run.preflight.expect.token) + '</div>';
        }
        if (intro.ran && !intro.ok) html += '<div class="small mb-2">Introspection: ' + badge('warn', 'failed') + ' ' + esc(intro.status || '') + ' <span class="sl-note">' + esc(intro.error || '') + '</span></div>';
        html += diffLines(run.grantDiff, 'Grant vs expected');
        if (run.jwtDiff) html += diffLines(run.jwtDiff, 'JWT claim vs token response');
        if (intro.diff) html += diffLines(intro.diff, 'Introspection vs token response');
        html += permGrid(run);
        html += '</div></div>';
        html += probeTable(run);
        html += refreshTable(run);
        html += '<div>';
        if (run.token && run.token.ok) {
            html += '<button type="button" class="btn btn-outline-primary btn-sm sl-reanalyze">' + (run.analyzed ? 'Re-probe' : 'Analyse now') + '</button> ';
            html += '<button type="button" class="btn btn-outline-secondary btn-sm sl-adopt" title="Writes this token into the Explorer session for the write workbench, stress panel and Auth-Code requests">Use token in Explorer</button>';
        }
        html += '</div></div>';
        return html;
    }

    function bindDetail (row, run) {
        const re = row.querySelector('.sl-reanalyze');
        if (re) re.addEventListener('click', (ev) => { ev.stopPropagation(); analyze(run.id, options()); });
        const ad = row.querySelector('.sl-adopt');
        if (ad) ad.addEventListener('click', async (ev) => {
            ev.stopPropagation();
            const { ok, data } = await api('adopt', { id: run.id });
            log(ok ? 'Explorer session now uses run ' + run.id + ' (' + data.scope + ')' : 'Adopt failed: ' + (data.error || ''));
        });
    }

    function upsert (run) {
        const i = runs.findIndex(r => r.id === run.id);
        if (i >= 0) runs[i] = run; else runs.push(run);
    }

    // ------------------------------------------------------------------ actions

    function options () {
        return { probeWrites: $('slProbeWrites').checked, refresh: $('slRefresh').checked };
    }

    function summary (run) {
        const v = run.verdicts || {};
        const pc = v.probeCounts || {};
        return '  ' + (v.overall || '?').toUpperCase().padEnd(5) + ' ' + run.label +
            ' | reg ' + v.registration + ' | token ' + v.token + ' | grant ' + v.grant +
            (v.probes && v.probes !== 'skip' ? ' | probes ' + (pc.pass || 0) + '✓ ' + (pc.fail || 0) + '✗' : '') +
            (v.refresh && v.refresh !== 'skip' ? ' | refresh ' + v.refresh : '') +
            (run.registration && run.registration.error ? '\n        reg: ' + run.registration.error : '') +
            (run.token && run.token.error ? '\n        token: ' + run.token.error : '');
    }

    async function runProfiles (ids, custom) {
        const grant = state.grant;
        const g = state.grants[grant];
        if (!ids.length) { log('Select at least one profile.', true); return; }
        if (g.interactive && ids.length > 1) {
            log('Auth-code runs go through the consent screen — select one profile at a time.', true);
            return;
        }
        const body = { grant: grant, context: state.context, options: options() };
        if (grant === 'password') {
            body.password = { username: $('slUser').value, password: $('slPass').value, email: $('slEmail').value };
        }
        busy(true);
        log('Running ' + ids.length + ' profile(s) — ' + g.label + ' / ' + state.context, true);
        for (const id of ids) {
            log('→ ' + id + ' …');
            const { ok, data } = await api('run', Object.assign({ profile: id, custom: custom || null }, body));
            if (!ok) { log('  error: ' + (data.error || '')); continue; }
            upsert(data.run);
            if (data.authorize_url) {
                log('  registered ' + data.run.registration.client_id + ' — opening the authorize screen…');
                window.location.href = data.authorize_url;
                return;
            }
            log(summary(data.run));
            renderRuns();
        }
        busy(false);
        renderRuns();
    }

    async function analyze (id, opts) {
        busy(true);
        log('Analysing run ' + id + ' …');
        const { ok, data } = await api('analyze', { id: id, options: opts });
        busy(false);
        if (!ok) { log('  error: ' + (data.error || '')); return; }
        upsert(data.run);
        openRun = id;
        log(summary(data.run));
        renderRuns();
    }

    function markdown () {
        const lines = [];
        lines.push('### Scope Lab — ' + state.site + ' — ' + new Date().toISOString());
        if (state.discovery) {
            const s = state.discovery.summary || {};
            lines.push('', 'Discovery: ' + state.discovery.scopes_supported.length + ' scopes (v1 ' + (s.v1 || 0) + ', v2 ' + (s.v2 || 0) +
                ', write ' + (s.write || 0) + ', granular ' + (s.granular || 0) + '); capabilities: ' + (state.discovery.capabilities || []).filter(c => c.indexOf('permission-') === 0).join(', '));
        }
        lines.push('', '| Profile | Grant / ctx | Ver | Reg | Token | Grant | JWT | Intro | Probes ✓/✗ | Refresh | Overall |', '|---|---|---|---|---|---|---|---|---|---|---|');
        runs.forEach(r => {
            const v = r.verdicts || {};
            const pc = v.probeCounts || {};
            lines.push('| ' + [r.label, r.grant + ' / ' + r.context, r.preflight.version, v.registration, v.token, v.grant, v.jwt, v.introspect,
                v.probes === 'skip' ? '—' : (pc.pass || 0) + '/' + (pc.fail || 0), v.refresh, '**' + v.overall + '**'].join(' | ') + ' |');
        });
        const failures = [];
        runs.forEach(r => {
            const d = r.grantDiff;
            if (d) {
                Object.entries(d.lost || {}).forEach(([k, p]) => failures.push('- ' + r.label + ' (' + r.grant + '): lost `' + k + '` ' + p.join('')));
                Object.entries(d.gained || {}).forEach(([k, p]) => failures.push('- ' + r.label + ' (' + r.grant + '): **gained** `' + k + '` ' + p.join('')));
            }
            (r.probes || []).filter(p => p.vsGranted === 'fail').forEach(p =>
                failures.push('- ' + r.label + ' (' + r.grant + '): `' + p.method + ' ' + p.path + '` → ' + p.status + ', expected ' + p.predictGranted));
            (r.refresh || []).filter(x => x.verdict === 'fail').forEach(x => failures.push('- ' + r.label + ' (' + r.grant + '): refresh ' + x.name + ' failed'));
            if (r.verdicts && r.verdicts.registration === 'fail') failures.push('- ' + r.label + ' (' + r.grant + '): registration ' + (r.registration.ok ? 'accepted' : 'refused') + ', expected ' + r.preflight.expect.registration);
        });
        if (failures.length) lines.push('', '**Findings**', ...failures);
        return lines.join('\n');
    }

    // ------------------------------------------------------------------ wiring

    grantSel.addEventListener('change', () => { prefs({ grant: grantSel.value, context: '' }); ctxSel.innerHTML = ''; loadState(); });
    ctxSel.addEventListener('change', () => { prefs({ context: ctxSel.value }); loadState(); });

    document.querySelectorAll('.sl-pick').forEach(btn => btn.addEventListener('click', () => {
        const pick = btn.dataset.pick;
        selected.clear();
        state.profiles.forEach(p => {
            if (!p.applicable) return;
            if (pick === 'applicable' || p.group === pick) selected.add(p.id);
        });
        prefs({ selected: Array.from(selected) });
        renderProfiles();
    }));

    $('slDiscoverBtn').addEventListener('click', async () => {
        busy(true);
        const { ok, data } = await api('discover', {});
        busy(false);
        log(ok ? 'Discovered ' + data.discovery.scopes_supported.length + ' published scopes.' : 'Discovery failed: ' + (data.error || ''), true);
        loadState();
    });

    $('slRunBtn').addEventListener('click', () => runProfiles(state.profiles.filter(p => p.applicable && selected.has(p.id)).map(p => p.id)));
    $('slRunCustomBtn').addEventListener('click', () => {
        const register = $('slCustomRegister').value.trim();
        if (!register) { log('Enter custom register scopes first.', true); return; }
        prefs({ customRegister: register, customRequest: $('slCustomRequest').value });
        runProfiles(['custom'], { register: register, request: $('slCustomRequest').value.trim() });
    });

    $('slMarkdownBtn').addEventListener('click', async () => {
        const md = markdown();
        try { await navigator.clipboard.writeText(md); log('Markdown summary copied (' + runs.length + ' runs).', true); } catch (e) { log(md, true); }
    });

    async function clear (deleteClients) {
        if (deleteClients && !window.confirm('Delete every "' + state.site + ' ScopeLab …" client from oauth_clients?')) return;
        const { ok, data } = await api('clear', { deleteClients: deleteClients });
        if (!ok) { log('Clear failed: ' + (data.error || '')); return; }
        runs = []; openRun = null;
        log('Runs cleared' + (data.deleted ? '; deleted ' + (data.deleted.clients == null ? '(remote — none)' : data.deleted.clients) + ' client(s), ' + data.deleted.files + ' credential file(s)' : '') + '.', true);
        renderRuns();
    }
    $('slClearBtn').addEventListener('click', () => clear(false));
    $('slPurgeBtn').addEventListener('click', () => clear(true));

    document.addEventListener('DOMContentLoaded', async () => {
        const p = prefs();
        (p.selected || []).forEach(id => selected.add(id));
        if (p.customRegister) $('slCustomRegister').value = p.customRegister;
        if (p.customRequest) $('slCustomRequest').value = p.customRequest;
        grantSel.innerHTML = '<option value="' + esc(p.grant || 'client_credentials') + '" selected></option>';
        ctxSel.innerHTML = p.context ? '<option value="' + esc(p.context) + '" selected></option>' : '';
        await loadState();

        // Back from an auth-code consent screen: open the panel and analyse the run.
        const params = new URLSearchParams(window.location.search);
        const returned = params.get('scope_lab_run');
        if (returned) {
            params.delete('scope_lab_run');
            const clean = window.location.pathname + (params.toString() ? '?' + params : '') + '#scopeLabPanel';
            window.history.replaceState(null, '', clean);
            if (window.jQuery) window.jQuery('#scopeLabPanelBody').collapse('show');
            document.getElementById('scopeLabPanel').scrollIntoView();
            const run = runs.find(r => r.id === returned);
            if (!run) { log('Returned run ' + returned + ' not found.', true); return; }
            openRun = run.id;
            renderRuns();
            log('Back from authorize for ' + run.label + '.', true);
            if (run.token && run.token.ok && !run.analyzed) {
                await analyze(run.id, run.pendingOptions || options());
            } else {
                log(summary(run));
            }
        }
    });
})();
</script>
