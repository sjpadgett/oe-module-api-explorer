<?php

/**
 * fhir_stress_card.php — concurrent load panel for the FHIR write endpoints.
 *
 * Sits below the write workbench and shares its catalog and resolved context: pick the
 * resources, say how many writes and how many at once, and the panel reports latency,
 * status distribution, duplicate ids and grouped failures.
 *
 * All traffic goes through fhir_stress.php, which holds the session's bearer token and
 * does the fan-out server-side with curl_multi -- see the header there for why the
 * browser cannot do it (PHP's session lock would serialise every request).
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

?>
<!-- FHIR write stress — concurrent load; shares this Explorer's session token -->
<?php explorer_panel_open('fhirStress', 'FHIR write stress', ['subtitle' => 'concurrent POST / PUT load']); ?>

        <p class="small text-muted mb-3">
            The write workbench above sends one request at a time. This one sends many at once,
            which is what the id allocators and transaction boundaries are actually sensitive to:
            <code>CareTeamService::saveCareTeam()</code> (transaction),
            <code>CarePlanService</code> (<code>QueryUtils::generateId()</code> form ids),
            <code>ContactRelationService</code> (address allocation) and
            <code>uuid_registry</code>, which every create touches.
            <strong>It writes real rows and nothing here can delete them</strong> — there is no
            DELETE route on the FHIR API. Every row is tagged so you can find them afterwards.
        </p>

        <div class="form-row align-items-end">
            <div class="form-group col-md-2">
                <label class="small mb-1" for="fsIterations">Iterations</label>
                <input id="fsIterations" type="number" min="1" max="200" value="10"
                       class="form-control form-control-sm">
                <span class="small text-muted">per resource</span>
            </div>
            <div class="form-group col-md-2">
                <label class="small mb-1" for="fsConcurrency">Concurrency</label>
                <input id="fsConcurrency" type="number" min="1" max="32" value="8"
                       class="form-control form-control-sm">
                <span class="small text-muted">in flight</span>
            </div>
            <div class="form-group col-md-3">
                <label class="small mb-1" for="fsMode">Mode</label>
                <select id="fsMode" class="form-control form-control-sm">
                    <option value="post">POST only (create contention)</option>
                    <option value="postput">POST then PUT (update path too)</option>
                </select>
            </div>
            <div class="form-group col-md-5">
                <button type="button" id="fsLoadBtn" class="btn btn-outline-secondary btn-sm">Load resources</button>
                <button type="button" id="fsPlanBtn" class="btn btn-outline-primary btn-sm" disabled>Dry run</button>
                <button type="button" id="fsRunBtn" class="btn btn-primary btn-sm" disabled>Run load</button>
                <span id="fsSpinner" class="ml-2 small text-muted" style="display:none;">running…</span>
            </div>
        </div>

        <div id="fsPickerWrap" class="mb-2" style="display:none;">
            <label class="small mb-1 d-block">
                Resources
                <button type="button" id="fsPickRisk" class="btn btn-link btn-sm py-0">concurrency-sensitive</button>
                <button type="button" id="fsPickAll" class="btn btn-link btn-sm py-0">all</button>
                <button type="button" id="fsPickNone" class="btn btn-link btn-sm py-0">none</button>
            </label>
            <div id="fsPicker" class="form-row small"></div>
        </div>

        <div id="fsResult" class="mt-3" style="display:none;"></div>
        <pre id="fsOut" class="explorer-log mt-3 mb-0"></pre>
<?php explorer_panel_close(); ?>
<script>
(function () {
    const iterationsIn = document.getElementById('fsIterations');
    const concurrencyIn = document.getElementById('fsConcurrency');
    const modeSel = document.getElementById('fsMode');
    const loadBtn = document.getElementById('fsLoadBtn');
    const planBtn = document.getElementById('fsPlanBtn');
    const runBtn = document.getElementById('fsRunBtn');
    const spin = document.getElementById('fsSpinner');
    const pickerWrap = document.getElementById('fsPickerWrap');
    const picker = document.getElementById('fsPicker');
    const result = document.getElementById('fsResult');
    const out = document.getElementById('fsOut');

    // The write paths this PR changed the concurrency behaviour of. Everything else is
    // still selectable -- this is just the default that answers the question being asked.
    const RISK_RESOURCES = ['CarePlan', 'CareTeam', 'RelatedPerson', 'Person', 'PractitionerRole'];

    let catalog = {};
    let tokenScopes = [];
    let context = {};

    function log (msg, reset) {
        out.style.display = 'block';
        out.textContent = (reset ? '' : out.textContent + '\n') + msg;
        out.scrollTop = out.scrollHeight;
    }

    function esc (value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    async function api (file, params, init) {
        const res = await fetch(file + '?' + new URLSearchParams(params), Object.assign({
            credentials: 'same-origin'
        }, init || {}));
        const data = await res.json().catch(() => ({ error: 'non-JSON response (is your session still alive?)' }));
        return { ok: res.ok, data };
    }

    function selected () {
        return Array.prototype.slice
            .call(picker.querySelectorAll('input[type=checkbox]:checked'))
            .map(cb => cb.value);
    }

    function setSelection (predicate) {
        picker.querySelectorAll('input[type=checkbox]').forEach(cb => {
            cb.checked = !cb.disabled && predicate(cb.value);
        });
        syncButtons();
    }

    // Null-safe: a name in RISK_RESOURCES that the catalog no longer carries must not
    // take the whole panel down with a TypeError on a missing checkbox.
    function isRunnable (name) {
        const cb = picker.querySelector('#fsPick_' + name);
        return cb !== null && !cb.disabled;
    }

    function syncButtons () {
        const any = selected().length > 0;
        planBtn.disabled = !any;
        runBtn.disabled = !any;
    }

    function renderPicker () {
        const names = Object.keys(catalog).sort();
        picker.innerHTML = names.map(name => {
            const entry = catalog[name];
            const notImplemented = entry.status === 'not-implemented';
            const hasScope = !!entry.hasScope;
            const unresolved = (entry.needs || []).filter(n => !context[n]);
            const blocked = notImplemented || !hasScope || unresolved.length > 0;
            const why = notImplemented
                ? '405, not implemented'
                : (!hasScope
                    ? 'no ' + entry.scope + ' in token'
                    : (unresolved.length ? 'needs ' + unresolved.join(', ') : ''));
            return '<div class="col-md-3 col-sm-4 mb-1">'
                + '<div class="custom-control custom-checkbox">'
                + '<input type="checkbox" class="custom-control-input" id="fsPick_' + esc(name) + '"'
                + ' value="' + esc(name) + '"' + (blocked ? ' disabled' : '') + '>'
                + '<label class="custom-control-label" for="fsPick_' + esc(name) + '">'
                + esc(name)
                + (entry.verbs.indexOf('PUT') === -1 ? ' <span class="text-muted">(create only)</span>' : '')
                + (blocked ? ' <span class="text-danger">— ' + esc(why) + '</span>' : '')
                + '</label></div></div>';
        }).join('');
        picker.querySelectorAll('input[type=checkbox]').forEach(cb => {
            cb.addEventListener('change', syncButtons);
        });
        pickerWrap.style.display = 'block';
    }

    function planBody () {
        return {
            resources: selected(),
            iterations: parseInt(iterationsIn.value, 10) || 1,
            concurrency: parseInt(concurrencyIn.value, 10) || 1,
            mode: modeSel.value,
            context: context
        };
    }

    async function call (action) {
        return api('fhir_stress.php', { action }, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(planBody())
        });
    }

    loadBtn.addEventListener('click', async () => {
        spin.style.display = 'inline';
        log('Loading the write catalog and resolving context…', true);
        // Both come from the write workbench's endpoint: one catalog, one definition of
        // what "resolved" means, so the two panels can never disagree about it.
        const cat = await api('fhir_write.php', { action: 'catalog' });
        if (!cat.ok) {
            spin.style.display = 'none';
            log('Error: ' + (cat.data.error || ''));
            return;
        }
        catalog = cat.data.catalog || {};
        tokenScopes = cat.data.tokenScopes || [];

        const ctx = await api('fhir_write.php', { action: 'context' });
        context = (ctx.ok && ctx.data.context) ? ctx.data.context : {};
        spin.style.display = 'none';

        renderPicker();
        setSelection(name => RISK_RESOURCES.indexOf(name) !== -1);

        const runnable = Object.keys(catalog).filter(isRunnable);
        log(runnable.length + ' of ' + Object.keys(catalog).length + ' resource(s) runnable against '
            + cat.data.fhirBase + '.');
        if (!runnable.length) {
            log('Nothing is runnable. Use the write workbench above to create the missing fixtures first.');
        }
    });

    document.getElementById('fsPickRisk').addEventListener('click', () => {
        setSelection(name => RISK_RESOURCES.indexOf(name) !== -1);
    });
    document.getElementById('fsPickAll').addEventListener('click', () => {
        setSelection(() => true);
    });
    document.getElementById('fsPickNone').addEventListener('click', () => setSelection(() => false));

    planBtn.addEventListener('click', async () => {
        spin.style.display = 'inline';
        const { ok, data } = await call('plan');
        spin.style.display = 'none';
        if (!ok) { log('Error: ' + (data.error || ''), true); return; }
        log('Dry run — nothing was sent.', true);
        log('  tag          ' + data.tag);
        log('  resources    ' + (data.resources.join(', ') || '(none)'));
        log('  writes       ' + data.writes + '  (' + data.iterations + ' iteration(s) × '
            + data.resources.length + ' resource(s)' + (data.mode === 'postput' ? ' × POST+PUT' : '') + ')');
        log('  concurrency  ' + data.concurrency + ' in flight');
        Object.keys(data.skipped || {}).forEach(r => {
            log('  — ' + r + ' skipped: ' + data.skipped[r]);
        });
        log('\nThese rows cannot be deleted through the API. Run only if you are happy to keep them.');
    });

    function renderResult (d) {
        const rows = (d.summary || []).map(s => {
            const codes = Object.keys(s.codes).map(c => c + '×' + s.codes[c]).join(' ');
            const bad = s.ok !== s.sent;
            return '<tr class="' + (bad ? 'text-danger' : '') + '">'
                + '<td>' + esc(s.resource) + '</td>'
                + '<td>' + esc(s.phase) + '</td>'
                + '<td class="text-right">' + s.ok + ' / ' + s.sent + '</td>'
                + '<td>' + esc(codes) + '</td>'
                + '<td class="text-right">' + s.latency.p50 + '</td>'
                + '<td class="text-right">' + s.latency.p95 + '</td>'
                + '<td class="text-right">' + s.latency.max + '</td>'
                + '<td class="text-right">' + (s.noId ? '<strong>' + s.noId + '</strong>' : '—') + '</td>'
                + '</tr>';
        }).join('');

        const dupKeys = Object.keys(d.duplicateIds || {});
        const dupBanner = dupKeys.length
            ? '<div class="alert alert-danger py-2 small mb-2"><strong>Duplicate ids returned.</strong> '
                + dupKeys.map(r => esc(r) + ': ' + Object.keys(d.duplicateIds[r]).length
                    + ' id(s) handed out more than once').join('; ')
                + ' — two concurrent creates were given the same identity.</div>'
            : '<div class="small mb-2">Every create returned a distinct id.</div>';

        const failKeys = Object.keys(d.failureCounts || {});
        const failList = failKeys.length
            ? '<div class="small mt-2"><strong>Failures by cause</strong><br>'
                + failKeys.sort((a, b) => d.failureCounts[b] - d.failureCounts[a])
                    .map(k => '<code>' + d.failureCounts[k] + '×</code> ' + esc(k)).join('<br>')
                + '</div>'
            : '';

        result.style.display = 'block';
        result.innerHTML = dupBanner
            + '<div class="small mb-2">'
            + '<code>' + esc(d.sent) + '</code> request(s) in <code>' + esc(d.wallSeconds)
            + 's</code> at concurrency <code>' + esc(d.concurrency) + '</code> — '
            + '<code>' + esc(d.throughput) + '</code> req/s · tag <code>' + esc(d.tag) + '</code>'
            + '</div>'
            + '<div class="table-responsive"><table class="table table-sm small mb-0">'
            + '<thead><tr><th>Resource</th><th>Phase</th><th class="text-right">OK</th><th>Status</th>'
            + '<th class="text-right">p50 ms</th><th class="text-right">p95 ms</th>'
            + '<th class="text-right">max ms</th><th class="text-right">201 no id</th></tr></thead>'
            + '<tbody>' + rows + '</tbody></table></div>'
            + failList;
    }

    runBtn.addEventListener('click', async () => {
        const body = planBody();
        const writes = body.iterations * body.resources.length * (body.mode === 'postput' ? 2 : 1);
        if (!window.confirm('This will write about ' + writes + ' record(s) that cannot be deleted '
                + 'through the API. Continue?')) {
            return;
        }
        runBtn.disabled = true;
        planBtn.disabled = true;
        spin.style.display = 'inline';
        result.style.display = 'none';
        log('Sending ' + writes + ' write(s) at concurrency ' + body.concurrency + '…', true);

        const { ok, data } = await call('run');
        spin.style.display = 'none';
        runBtn.disabled = false;
        planBtn.disabled = false;

        if (!ok || data.error) {
            log('Error: ' + (data.error || 'run failed') + (data.detail ? '\n  ' + data.detail : ''));
            return;
        }

        renderResult(data);
        log('Run ' + data.tag + ' complete — ' + data.sent + ' request(s) in ' + data.wallSeconds + 's.');
        Object.keys(data.skipped || {}).forEach(r => log('  — ' + r + ' skipped: ' + data.skipped[r]));

        (data.failureSamples || []).forEach(f => {
            log('\n✗ ' + f.resource + ' ' + f.phase + '  HTTP ' + f.code + '  (' + f.ms + ' ms)');
            log('  ' + f.url);
            log('  ' + JSON.stringify(f.body, null, 2).split('\n').join('\n  '));
        });

        if (data.cleanup) {
            log('\nRows from this run are tagged "' + data.cleanup.tag + '".');
            log('  ' + data.cleanup.note);
            (data.cleanup.sql || []).forEach(s => log('  ' + s));
        }
    });
})();
</script>
