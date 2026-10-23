@extends('layouts.policyflow')
@section('title', 'Dashboard')
@section('page', 'index')
@section('heading', 'Policy requests')

@section('content')
<div class="grid gap-4 sm:grid-cols-3">
    <div class="card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Matching requests</p><p id="total-count" class="mt-3 text-3xl font-semibold tracking-tight">—</p><p class="muted mt-1">Across all matching pages</p></div>
    <div class="card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Current view</p><p id="visible-count" class="mt-3 text-3xl font-semibold tracking-tight">—</p><p class="muted mt-1">Requests on this page</p></div>
    <div class="card flex flex-col justify-between bg-slate-900 p-5 text-white"><div><p class="text-xs font-semibold uppercase tracking-wider text-indigo-200">Reliability lab</p><p class="mt-2 text-lg font-semibold">{{ \App\Support\DemoMode::available() ? 'Run the complete lifecycle' : 'Public read-only overview' }}</p></div>@if (\App\Support\DemoMode::available())<a href="{{ route('policies.create') }}" class="mt-4 text-sm font-semibold text-indigo-200 hover:text-white">Create a request →</a>@endif</div>
</div>

<section class="card overflow-hidden" aria-label="Policy requests">
    <div class="flex flex-col gap-4 border-b border-slate-200 p-5 sm:flex-row sm:items-end sm:justify-between">
        <div><h2 class="section-title">All requests</h2><p class="muted mt-1">Latest requests first. Filter by current state.</p></div>
        <div class="flex flex-wrap items-end gap-2"><label class="block"><span class="field-label">Status</span><select id="status-filter" class="field min-w-48"><option value="">All statuses</option><option>RECEIVED</option><option>VALIDATED</option><option>SUBMITTED</option><option>PENDING_CONFIRMATION</option><option>ISSUED</option><option>FAILED</option><option>NEEDS_RECONCILIATION</option></select></label><button id="refresh-list" type="button" class="btn-secondary" aria-label="Refresh policy list">↻ Refresh</button></div>
    </div>
    <div class="overflow-x-auto"><table class="w-full min-w-[850px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3 font-semibold">Request</th><th class="px-5 py-3 font-semibold">Customer / trip</th><th class="px-5 py-3 font-semibold">Premium</th><th class="px-5 py-3 font-semibold">Attempts</th><th class="px-5 py-3 font-semibold">Policy number</th><th class="px-5 py-3 font-semibold">Status</th><th class="px-5 py-3"></th></tr></thead><tbody id="policy-rows" class="divide-y divide-slate-100"><tr><td colspan="7" class="px-5 py-12 text-center text-slate-500">Loading requests…</td></tr></tbody></table></div>
    <div class="flex items-center justify-between gap-3 border-t border-slate-200 px-5 py-4"><p id="page-summary" class="text-sm text-slate-500">—</p><div class="flex gap-2"><button id="previous-page" class="btn-secondary" type="button" disabled>Previous</button><button id="next-page" class="btn-secondary" type="button" disabled>Next</button></div></div>
</section>

<details class="card p-5"><summary class="cursor-pointer text-sm font-semibold text-slate-700">View list API response and HTTP status</summary><p id="list-http-status" class="mt-4 text-xs font-semibold text-slate-500">No request yet</p><pre id="list-json" class="mt-2 max-h-80 overflow-auto rounded-xl bg-slate-950 p-4 text-xs leading-5 text-slate-100">—</pre></details>
@endsection
