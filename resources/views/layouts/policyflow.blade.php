<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>@yield('title', 'Dashboard') · PolicyFlow</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-page="@yield('page')" data-public-demo="{{ \App\Support\DemoMode::public() ? 'true' : 'false' }}" @hasSection('policy-id') data-policy-id="@yield('policy-id')" @endif>
    <div class="min-h-screen lg:flex">
        <aside class="border-b border-slate-200 bg-white lg:fixed lg:inset-y-0 lg:w-64 lg:border-b-0 lg:border-r">
            <div class="flex h-18 items-center justify-between px-5 lg:px-7">
                <a href="{{ route('policies.index') }}" class="flex items-center gap-3" aria-label="PolicyFlow dashboard">
                    <span class="grid h-9 w-9 place-items-center rounded-xl bg-indigo-600 text-lg font-bold text-white">P</span>
                    <span><strong class="block text-lg leading-5 tracking-tight">PolicyFlow</strong><small class="text-xs font-medium text-slate-500">Insurance operations</small></span>
                </a>
                <span class="rounded-full bg-amber-50 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-amber-700">Mock demo</span>
            </div>
            <nav aria-label="Main navigation" class="flex gap-2 px-4 pb-4 lg:mt-8 lg:flex-col lg:pb-0">
                <a href="{{ route('policies.index') }}" class="rounded-lg px-4 py-2.5 text-sm font-medium {{ request()->routeIs('policies.index') ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-50' }}">Overview</a>
                <a href="{{ route('policies.create') }}" class="rounded-lg px-4 py-2.5 text-sm font-medium {{ request()->routeIs('policies.create') ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-50' }}">New policy request</a>
            </nav>
            <div class="hidden border-t border-slate-100 px-7 py-5 text-xs leading-5 text-slate-500 lg:absolute lg:bottom-0 lg:block">
                Mock insurer demonstration.<br>Use synthetic data only; records persist in the demo database.
            </div>
        </aside>

        <div class="min-w-0 flex-1 lg:ml-64">
            <header class="flex min-h-18 items-center justify-between gap-4 border-b border-slate-200 bg-white px-5 py-4 sm:px-8">
                <div><p class="text-xs font-semibold uppercase tracking-[0.16em] text-indigo-600">Issuance & reconciliation</p><h1 class="mt-1 text-xl font-semibold tracking-tight sm:text-2xl">@yield('heading')</h1></div>
                <a href="{{ route('policies.create') }}" class="btn-primary hidden sm:inline-flex">+ New request</a>
            </header>
            <main class="mx-auto max-w-7xl space-y-6 px-5 py-7 sm:px-8 sm:py-9">
                <div id="notice" role="status" aria-live="polite" class="hidden rounded-xl border px-4 py-3 text-sm"></div>
                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>
