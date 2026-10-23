import './bootstrap';

const $ = (selector) => document.querySelector(selector);
const page = document.body.dataset.page;
const money = (value) => new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(Number(value ?? 0));
const safe = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);
const pretty = (value) => JSON.stringify(value, null, 2);
const date = (value) => value ? String(value).slice(0, 10) : '—';
const time = (value) => value ? new Date(value).toLocaleString() : '—';
const statusTone = {
    VALIDATED: 'bg-sky-50 text-sky-700', ISSUED: 'bg-emerald-50 text-emerald-700', FAILED: 'bg-rose-50 text-rose-700',
    PENDING_CONFIRMATION: 'bg-amber-50 text-amber-700', NEEDS_RECONCILIATION: 'bg-violet-50 text-violet-700',
    SUBMITTED: 'bg-indigo-50 text-indigo-700', RECEIVED: 'bg-slate-100 text-slate-700',
};
const pill = (status) => `<span class="status-pill ${statusTone[status] ?? statusTone.RECEIVED}">${safe(status)}</span>`;

async function api(path, options = {}) {
    try {
        const response = await fetch(`/api${path}`, {
            ...options,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...options.headers },
        });
        const body = await response.json();
        return { status: response.status, ok: response.ok, body };
    } catch (error) {
        return { status: 0, ok: false, body: { message: `Network error: ${error.message}` } };
    }
}

function notice(message, ok = true) {
    const element = $('#notice');
    element.textContent = message;
    element.className = `rounded-xl border px-4 py-3 text-sm ${ok ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800'}`;
}

function setBusy(button, busy, label) {
    button.disabled = busy;
    button.textContent = busy ? 'Working…' : label;
}

if (page === 'index') {
    const params = new URLSearchParams(location.search);
    const statusFilter = $('#status-filter');
    statusFilter.value = params.get('status') ?? '';
    let currentPage = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
    let loading = false;

    async function loadList() {
        if (loading) return;
        loading = true;
        $('#refresh-list').disabled = true;
        $('#previous-page').disabled = true;
        $('#next-page').disabled = true;
        $('#policy-rows').innerHTML = '<tr><td colspan="7" class="px-5 py-12 text-center text-slate-500">Loading requests…</td></tr>';
        const query = new URLSearchParams({ page: String(currentPage), per_page: '15' });
        if (statusFilter.value) query.set('status', statusFilter.value);
        const result = await api(`/policy-requests?${query}`);
        $('#list-http-status').textContent = `HTTP ${result.status || 'network error'}`;
        $('#list-json').textContent = pretty(result.body);
        if (!result.ok) {
            $('#policy-rows').innerHTML = '<tr><td colspan="7" class="px-5 py-12 text-center text-rose-600">Could not load requests. Use Refresh to try again.</td></tr>';
            notice(result.body.message ?? 'Could not load policy requests.', false);
        } else {
            const { data, pagination } = result.body;
            $('#total-count').textContent = pagination.total;
            $('#visible-count').textContent = data.length;
            $('#page-summary').textContent = `Page ${pagination.current_page} of ${pagination.last_page} · ${pagination.total} matching requests`;
            $('#previous-page').disabled = pagination.current_page <= 1;
            $('#next-page').disabled = pagination.current_page >= pagination.last_page;
            $('#policy-rows').innerHTML = data.length ? data.map((policy) => `<tr class="hover:bg-slate-50">
                <td class="px-5 py-4"><a href="/policies/${policy.id}" class="font-semibold text-indigo-700 hover:underline">#${policy.id}</a><div class="mt-1 text-xs text-slate-500">${safe(policy.client_request_id)} · ${safe(policy.agent_id)}</div></td>
                <td class="px-5 py-4"><div class="font-medium">${safe(policy.customer_name)}</div><div class="mt-1 text-xs text-slate-500">${safe(policy.destination)} · ${date(policy.trip_start)} to ${date(policy.trip_end)}</div></td>
                <td class="px-5 py-4 font-semibold tabular-nums">${money(policy.premium_amount)}</td><td class="px-5 py-4 tabular-nums">${policy.submission_attempt_count ?? 0}</td>
                <td class="px-5 py-4 font-mono text-xs">${safe(policy.policy_number ?? '—')}</td><td class="px-5 py-4">${pill(policy.status)}</td>
                <td class="px-5 py-4 text-right"><a href="/policies/${policy.id}" class="text-sm font-semibold text-indigo-600 hover:underline">View →</a></td></tr>`).join('') : '<tr><td colspan="7" class="px-5 py-12 text-center text-slate-500">No requests match this filter.</td></tr>';
            const nextUrl = new URL(location.href);
            statusFilter.value ? nextUrl.searchParams.set('status', statusFilter.value) : nextUrl.searchParams.delete('status');
            currentPage > 1 ? nextUrl.searchParams.set('page', String(currentPage)) : nextUrl.searchParams.delete('page');
            history.replaceState({}, '', nextUrl);
        }
        loading = false;
        $('#refresh-list').disabled = false;
    }

    statusFilter.addEventListener('change', () => { currentPage = 1; loadList(); });
    $('#refresh-list').addEventListener('click', loadList);
    $('#previous-page').addEventListener('click', () => { if (currentPage > 1) { currentPage--; loadList(); } });
    $('#next-page').addEventListener('click', () => { currentPage++; loadList(); });
    loadList();
}

if (page === 'create') {
    const form = $('#create-form');
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        for (const element of form.querySelectorAll('[data-error-for]')) element.textContent = '';
        const button = $('#create-submit');
        setBusy(button, true, 'Create request');
        const payload = Object.fromEntries(new FormData(form));
        payload.sum_insured = payload.sum_insured ? Number(payload.sum_insured) : '';
        const result = await api('/policy-requests', { method: 'POST', body: JSON.stringify(payload) });
        $('#create-http-status').textContent = `HTTP ${result.status || 'network error'}`;
        $('#create-json').textContent = pretty(result.body);
        if (result.ok) {
            const message = result.status === 201 ? 'A new request was saved.' : 'The existing request was returned for this agent and client request ID.';
            notice(`HTTP ${result.status}: ${message}`);
            $('#create-success-message').textContent = `${message} Review the response below, then continue to its details.`;
            $('#create-detail-link').href = `/policies/${result.body.data.id}`;
            $('#create-success').classList.remove('hidden');
        } else {
            $('#create-success').classList.add('hidden');
            for (const [field, messages] of Object.entries(result.body.errors ?? {})) {
                const target = form.querySelector(`[data-error-for="${field}"]`);
                if (target) target.textContent = messages.join(' ');
            }
            notice(result.body.message ?? 'Could not create request.', false);
        }
        setBusy(button, false, 'Create request');
    });
}

if (page === 'show') {
    const id = Number(document.body.dataset.policyId);
    let policy = null;
    let busy = false;
    const retryKey = $('#retry-key');
    const retryStorageKey = `policyflow-retry-key-${id}`;
    const newKey = () => { retryKey.value = crypto.randomUUID(); sessionStorage.setItem(retryStorageKey, retryKey.value); };
    retryKey.value = sessionStorage.getItem(retryStorageKey) ?? '';
    if (!retryKey.value) newKey();
    retryKey.addEventListener('input', () => sessionStorage.setItem(retryStorageKey, retryKey.value));
    $('#new-retry-key').addEventListener('click', newKey);

    const renderItems = (selector, items, render) => {
        $(selector).innerHTML = items?.length ? items.map(render).join('') : '<p class="muted">No records yet.</p>';
    };

    function render(policyData) {
        policy = policyData;
        $('#detail-loading').classList.add('hidden');
        $('#detail-content').classList.remove('hidden');
        $('#detail-status').outerHTML = pill(policy.status).replace('<span ', '<span id="detail-status" ');
        $('#detail-customer').textContent = policy.customer_name;
        $('#detail-request-key').textContent = `${policy.client_request_id} · ${policy.agent_id} · born ${date(policy.date_of_birth)}`;
        $('#detail-premium').textContent = money(policy.premium_amount);
        $('#detail-plan').textContent = `${policy.plan_code} · ${money(policy.sum_insured)} cover`;
        $('#detail-trip').textContent = `${policy.destination} · ${date(policy.trip_start)} to ${date(policy.trip_end)}`;
        $('#detail-policy-number').textContent = policy.policy_number ?? 'Not issued';
        $('#detail-provider').textContent = `${policy.provider_reference ?? 'No reference'} · ${policy.submission_attempt_count ?? 0} attempt(s)`;
        renderItems('#state-history', policy.state_history, (entry) => `<div class="rounded-xl border border-slate-100 bg-slate-50 p-3"><div class="flex items-center justify-between gap-2"><strong class="text-sm">${safe(entry.new_state)}</strong><span class="text-xs text-slate-500">${safe(time(entry.occurred_at))}</span></div><p class="mt-1 text-xs text-slate-500">From ${safe(entry.previous_state ?? 'start')} · ${safe(entry.context?.source ?? entry.context?.result ?? 'state change')}</p></div>`);
        renderItems('#issuance-attempts', policy.issuance_attempts, (attempt) => `<div class="rounded-xl border border-slate-100 bg-slate-50 p-3"><strong class="text-sm">Attempt #${attempt.attempt_number}</strong><p class="mt-1 text-xs text-slate-600">${safe(attempt.scenario)} · ${safe(attempt.result)}</p><p class="mt-1 break-all text-xs text-slate-500">${safe(attempt.provider_reference ?? 'No provider reference')}</p></div>`);
        renderItems('#provider-events', policy.provider_events, (event) => `<div class="rounded-xl border border-slate-100 bg-slate-50 p-3"><strong class="text-sm">${safe(event.event_type)}</strong><p class="mt-1 break-all text-xs text-slate-600">${safe(event.event_id)}</p><p class="mt-1 text-xs text-slate-500">${event.applied ? 'Applied' : 'Duplicate / ignored'} · ${safe(time(event.created_at))}</p></div>`);
        const lastScenario = policy.issuance_attempts?.at(-1)?.scenario;
        const canDispatch = ['DELAYED_CALLBACK', 'DUPLICATE_CALLBACK'].includes(lastScenario) && ['PENDING_CONFIRMATION', 'ISSUED'].includes(policy.status);
        $('#action-submit').disabled = busy || policy.status !== 'VALIDATED';
        $('#action-retry').disabled = busy || policy.status !== 'FAILED';
        $('#action-reconcile').disabled = busy || !['NEEDS_RECONCILIATION', 'PENDING_CONFIRMATION'].includes(policy.status);
        $('#action-dispatch').disabled = busy || !canDispatch;
        $('#action-hint').textContent = canDispatch && policy.status === 'ISSUED'
            ? 'Dispatch again to replay the same callback event; issuance stays unchanged.'
            : policy.status === 'ISSUED' ? 'Issued is terminal. No new submission is allowed.'
                : policy.status === 'FAILED' ? 'Retry with a stable retry key. Select SUCCESS to recover.'
                    : policy.status === 'NEEDS_RECONCILIATION' ? 'The provider outcome is uncertain. Reconcile before taking another action.'
                        : policy.status === 'PENDING_CONFIRMATION' ? 'Dispatch a queued callback or reconcile the pending provider outcome.'
                            : 'Choose a mock scenario, then submit this validated request.';
    }

    async function loadDetail() {
        if (busy) return;
        $('#refresh-detail').disabled = true;
        const result = await api(`/policy-requests/${id}`);
        $('#detail-http-status').textContent = `HTTP ${result.status || 'network error'}`;
        $('#detail-json').textContent = pretty(result.body);
        if (result.ok) render(result.body.data);
        else { $('#detail-loading').textContent = 'Could not load this request.'; notice(result.body.message ?? 'Could not load details.', false); }
        $('#refresh-detail').disabled = false;
    }

    async function runAction(action) {
        if (busy || !policy) return;
        const button = $(`#action-${action}`);
        if (button.disabled) return;
        busy = true;
        const label = button.textContent;
        for (const control of document.querySelectorAll('[data-action]')) control.disabled = true;
        setBusy(button, true, label);
        let path = `/policy-requests/${id}/${action}`;
        let payload = {};
        if (action === 'dispatch') path = `/mock-insurer/policy-requests/${id}/dispatch-callbacks`;
        if (['submit', 'retry'].includes(action)) payload.scenario = $('#scenario').value;
        if (action === 'retry') payload.retry_key = retryKey.value;
        const result = await api(path, { method: 'POST', body: JSON.stringify(payload) });
        $('#action-http-status').textContent = `HTTP ${result.status || 'network error'} · ${action}`;
        $('#action-json').textContent = pretty(result.body);
        if (result.ok) {
            notice(`${action.charAt(0).toUpperCase() + action.slice(1)} completed (HTTP ${result.status}).`);
            await loadDetailAfterAction();
        } else notice(`HTTP ${result.status || 'network error'}: ${result.body.message ?? 'Action failed.'}`, false);
        busy = false;
        button.textContent = label;
        if (policy) render(policy);
    }

    async function loadDetailAfterAction() {
        const result = await api(`/policy-requests/${id}`);
        $('#detail-http-status').textContent = `HTTP ${result.status || 'network error'}`;
        $('#detail-json').textContent = pretty(result.body);
        if (result.ok) render(result.body.data);
    }

    document.querySelectorAll('[data-action]').forEach((button) => button.addEventListener('click', () => runAction(button.dataset.action)));
    $('#refresh-detail').addEventListener('click', loadDetail);
    loadDetail();
}
