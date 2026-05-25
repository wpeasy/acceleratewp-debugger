<script lang="ts">
    import { onMount } from 'svelte';

    interface PreloadRow {
        id: number;
        url: string;
        status: string;
        modified: string;
        last_accessed: string;
    }

    interface Summary {
        completed: number;
        pending: number;
        'in-progress': number;
        failed: number;
        total: number;
        percent_complete: number;
    }

    interface Cron {
        hook: string;
        hook_registered: boolean;
        scheduled: boolean;
        next_run: number | null;
        server_time: number;
        is_processing: boolean;
        source: 'action_scheduler' | 'wp_cron' | 'none';
        pending_actions: number;
    }

    interface PreloadResponse {
        success: boolean;
        items: PreloadRow[];
        total: number;
        page: number;
        per_page: number;
        total_pages: number;
        summary: Summary;
        cron: Cron;
        error?: string;
    }

    interface ActionResponse {
        success: boolean;
        message?: string;
        error?: string;
    }

    type SortField = 'url' | 'modified' | 'last_accessed';
    type SortOrder = 'asc' | 'desc';

    const PER_PAGE = 50;
    const POLL_INTERVAL_MS = 5000;

    let search = $state('');
    let statusFilter = $state('');
    let sortField = $state<SortField>('url');
    let sortOrder = $state<SortOrder>('asc');
    let page = $state(1);

    let items = $state<PreloadRow[]>([]);
    let summary = $state<Summary>({
        completed: 0,
        pending: 0,
        'in-progress': 0,
        failed: 0,
        total: 0,
        percent_complete: 0,
    });
    let cron = $state<Cron>({
        hook: '',
        hook_registered: false,
        scheduled: false,
        next_run: null,
        server_time: 0,
        is_processing: false,
        source: 'none',
        pending_actions: 0,
    });
    let total = $state(0);
    let totalPages = $state(0);
    let loading = $state(false);
    let errorMessage = $state('');
    let notice = $state<{ type: 'success' | 'error'; message: string } | null>(null);
    let runningCron = $state(false);
    let rebuildingUrl = $state<string | null>(null);

    let nowSec = $state(Math.floor(Date.now() / 1000));
    let localFetchTime = $state(Math.floor(Date.now() / 1000));

    const cronSecondsUntil = $derived(
        cron.next_run !== null
            ? cron.next_run - cron.server_time + localFetchTime - nowSec
            : null
    );

    function formatCountdown(secs: number): string {
        if (secs <= 0) {
            return 'due now';
        }
        if (secs < 60) {
            return `in ${secs}s`;
        }
        if (secs < 3600) {
            const m = Math.floor(secs / 60);
            const s = secs % 60;
            return s > 0 ? `in ${m}m ${s}s` : `in ${m}m`;
        }
        const h = Math.floor(secs / 3600);
        const m = Math.floor((secs % 3600) / 60);
        return m > 0 ? `in ${h}h ${m}m` : `in ${h}h`;
    }

    let debounceTimer: ReturnType<typeof setTimeout> | null = null;
    let pollTimer: ReturnType<typeof setTimeout> | null = null;
    let tickTimer: ReturnType<typeof setInterval> | null = null;

    function clearPoll(): void {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function schedulePoll(): void {
        clearPoll();
        const activeWork = summary.pending + summary['in-progress'];
        const cronImminent =
            cron.scheduled && cronSecondsUntil !== null && cronSecondsUntil < 60;
        if (activeWork > 0 || cronImminent) {
            pollTimer = setTimeout(() => {
                pollTimer = null;
                void load();
            }, POLL_INTERVAL_MS);
        }
    }

    async function load(): Promise<void> {
        loading = true;
        errorMessage = '';
        try {
            const res = await window.AWPD.api.get<PreloadResponse>('/preload', {
                s: search || undefined,
                status: statusFilter || undefined,
                sort: sortField,
                order: sortOrder,
                page,
                per_page: PER_PAGE,
            });
            if (!res.success) {
                errorMessage = res.error ?? 'Failed to load preload data.';
                items = [];
                total = 0;
                totalPages = 0;
            } else {
                items = res.items;
                total = res.total;
                totalPages = res.total_pages;
                summary = res.summary;
                if (res.cron) {
                    cron = res.cron;
                    localFetchTime = Math.floor(Date.now() / 1000);
                }
            }
        } catch (err) {
            errorMessage = err instanceof Error ? err.message : 'Request failed.';
            items = [];
            total = 0;
            totalPages = 0;
        } finally {
            loading = false;
        }
        schedulePoll();
    }

    function scheduleSearch(): void {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }
        debounceTimer = setTimeout(() => {
            page = 1;
            void load();
        }, 300);
    }

    function onStatusChange(): void {
        page = 1;
        void load();
    }

    function toggleSort(field: SortField): void {
        if (sortField === field) {
            sortOrder = sortOrder === 'asc' ? 'desc' : 'asc';
        } else {
            sortField = field;
            sortOrder = 'asc';
        }
        void load();
    }

    function goToPage(p: number): void {
        if (p < 1 || p > totalPages) {
            return;
        }
        page = p;
        void load();
    }

    async function runCron(): Promise<void> {
        runningCron = true;
        notice = null;
        try {
            const res = await window.AWPD.api.post<ActionResponse>('/preload/run-cron');
            notice = res.success
                ? { type: 'success', message: res.message ?? 'Preload cron triggered.' }
                : { type: 'error', message: res.error ?? 'Failed to trigger cron.' };
            if (res.success) {
                void load();
            }
        } catch (err) {
            notice = {
                type: 'error',
                message: err instanceof Error ? err.message : 'Request failed.',
            };
        } finally {
            runningCron = false;
        }
    }

    async function rebuild(url: string): Promise<void> {
        rebuildingUrl = url;
        notice = null;
        try {
            const res = await window.AWPD.api.post<ActionResponse>('/preload/rebuild', { url });
            notice = res.success
                ? { type: 'success', message: res.message ?? 'Cache rebuild triggered.' }
                : { type: 'error', message: res.error ?? 'Failed to rebuild.' };
            if (res.success) {
                void load();
            }
        } catch (err) {
            notice = {
                type: 'error',
                message: err instanceof Error ? err.message : 'Request failed.',
            };
        } finally {
            rebuildingUrl = null;
        }
    }

    function sortArrow(field: SortField): string {
        if (sortField !== field) {
            return '';
        }
        return sortOrder === 'asc' ? '↑' : '↓';
    }

    onMount(() => {
        void load();
        tickTimer = setInterval(() => {
            nowSec = Math.floor(Date.now() / 1000);
        }, 1000);
        return () => {
            clearPoll();
            if (tickTimer) {
                clearInterval(tickTimer);
                tickTimer = null;
            }
        };
    });
</script>

<div class="awpd-preload">
    <header class="awpd-preload__header">
        <div class="awpd-preload__summary">
            <strong class="awpd-preload__percent">{summary.percent_complete}%</strong>
            <span class="awpd-preload__summary-label">complete</span>
            <span class="awpd-preload__counts">
                <span class="awpd-pill awpd-pill--completed">{summary.completed} Completed</span>
                <span class="awpd-pill awpd-pill--pending">{summary.pending} Pending</span>
                <span class="awpd-pill awpd-pill--in-progress">{summary['in-progress']} In-Progress</span>
                <span class="awpd-pill awpd-pill--failed">{summary.failed} Failed</span>
                <span class="awpd-pill awpd-pill--total">{summary.total} Total</span>
            </span>
        </div>
        <div class="awpd-cron">
            {#if cron.is_processing}
                <span class="awpd-cron__pill awpd-cron__pill--running" title="At least one URL has status in-progress in the cache table">
                    <span class="awpd-cron__dot"></span> Processing
                    {#if cron.pending_actions > 0}<span class="awpd-cron__count">· {cron.pending_actions} queued</span>{/if}
                </span>
            {:else if cron.scheduled && cronSecondsUntil !== null}
                <span class="awpd-cron__pill awpd-cron__pill--scheduled" title="Action Scheduler group: rocket-preload · hook: {cron.hook}">
                    {cron.pending_actions} queued · next {formatCountdown(cronSecondsUntil)}
                </span>
            {:else if !cron.hook_registered}
                <span class="awpd-cron__pill awpd-cron__pill--warn" title="No PHP callback registered for {cron.hook} — AccelerateWP / WP Rocket not active?">
                    Hook not registered
                </span>
            {:else}
                <span class="awpd-cron__pill awpd-cron__pill--idle" title="Hook registered but no Action Scheduler actions are pending in the rocket-preload group">
                    Idle
                </span>
            {/if}
            <button
                type="button"
                class="button button-primary"
                onclick={runCron}
                disabled={runningCron}
            >
                {runningCron ? 'Running…' : 'Run Cron'}
            </button>
        </div>
    </header>

    {#if notice}
        <div class="awpd-notice awpd-notice--{notice.type}">{notice.message}</div>
    {/if}

    <div class="awpd-preload__controls">
        <label class="awpd-control">
            <span class="awpd-control__label">Search URL</span>
            <input
                type="search"
                placeholder="URL fragment or status"
                bind:value={search}
                oninput={scheduleSearch}
            />
        </label>
        <label class="awpd-control">
            <span class="awpd-control__label">Status</span>
            <select bind:value={statusFilter} onchange={onStatusChange}>
                <option value="">All</option>
                <option value="completed">Completed</option>
                <option value="pending">Pending</option>
                <option value="in-progress">In-Progress</option>
                <option value="failed">Failed</option>
            </select>
        </label>
    </div>

    {#if errorMessage}
        <div class="awpd-notice awpd-notice--error">{errorMessage}</div>
    {/if}

    <table class="awpd-table">
        <thead>
            <tr>
                <th
                    class="awpd-table__sortable"
                    onclick={() => toggleSort('url')}
                >URL <span class="awpd-table__arrow">{sortArrow('url')}</span></th>
                <th>Status</th>
                <th
                    class="awpd-table__sortable"
                    onclick={() => toggleSort('modified')}
                >Modified <span class="awpd-table__arrow">{sortArrow('modified')}</span></th>
                <th
                    class="awpd-table__sortable"
                    onclick={() => toggleSort('last_accessed')}
                >Last accessed <span class="awpd-table__arrow">{sortArrow('last_accessed')}</span></th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            {#if loading && items.length === 0}
                <tr><td colspan="5" class="awpd-table__empty">Loading…</td></tr>
            {:else if items.length === 0}
                <tr><td colspan="5" class="awpd-table__empty">No rows.</td></tr>
            {:else}
                {#each items as row (row.id)}
                    <tr class="awpd-row awpd-row--{row.status}">
                        <td class="awpd-row__url">
                            <a href={row.url} target="_blank" rel="noopener noreferrer">{row.url}</a>
                        </td>
                        <td>
                            <span class="awpd-pill awpd-pill--{row.status}">{row.status}</span>
                        </td>
                        <td class="awpd-row__date">{row.modified}</td>
                        <td class="awpd-row__date">{row.last_accessed}</td>
                        <td>
                            <button
                                type="button"
                                class="button button-small"
                                disabled={rebuildingUrl === row.url}
                                onclick={() => rebuild(row.url)}
                            >
                                {rebuildingUrl === row.url ? 'Rebuilding…' : 'Rebuild Cache'}
                            </button>
                        </td>
                    </tr>
                {/each}
            {/if}
        </tbody>
    </table>

    {#if totalPages > 1}
        <nav class="awpd-pagination" aria-label="Pagination">
            <button
                type="button"
                class="button"
                disabled={page <= 1}
                onclick={() => goToPage(page - 1)}
            >‹ Prev</button>
            <span class="awpd-pagination__status">Page {page} of {totalPages} — {total} rows</span>
            <button
                type="button"
                class="button"
                disabled={page >= totalPages}
                onclick={() => goToPage(page + 1)}
            >Next ›</button>
        </nav>
    {/if}
</div>

<style>
    .awpd-preload {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .awpd-preload__header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .awpd-preload__summary {
        display: flex;
        gap: 0.5rem;
        align-items: center;
        flex-wrap: wrap;
    }

    .awpd-preload__percent {
        font-size: 1.25rem;
    }

    .awpd-preload__summary-label {
        color: #50575e;
    }

    .awpd-preload__counts {
        display: flex;
        gap: 0.4rem;
        flex-wrap: wrap;
        margin-left: 0.5rem;
    }

    .awpd-preload__controls {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        align-items: flex-end;
    }

    .awpd-control {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        font-size: 0.85rem;
    }

    .awpd-control__label {
        color: #50575e;
    }

    .awpd-control input,
    .awpd-control select {
        min-width: 16rem;
        padding: 0.4rem 0.5rem;
    }

    .awpd-table {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
        border: 1px solid #c3c4c7;
    }

    .awpd-table th,
    .awpd-table td {
        text-align: left;
        padding: 0.5rem 0.75rem;
        border-bottom: 1px solid #e5e5e5;
        vertical-align: middle;
    }

    .awpd-table th {
        background: #f6f7f7;
        font-weight: 600;
    }

    .awpd-table__sortable {
        cursor: pointer;
        user-select: none;
    }

    .awpd-table__sortable:hover {
        color: #2271b1;
    }

    .awpd-table__arrow {
        font-size: 0.85em;
        opacity: 0.7;
    }

    .awpd-table__empty {
        text-align: center;
        padding: 1.5rem;
        color: #646970;
    }

    .awpd-row__url a {
        word-break: break-all;
    }

    .awpd-row__date {
        white-space: nowrap;
        color: #50575e;
        font-variant-numeric: tabular-nums;
    }

    .awpd-pill {
        display: inline-block;
        padding: 0.15rem 0.55rem;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 600;
        background: #e5e5e5;
        color: #1d2327;
        white-space: nowrap;
    }

    .awpd-pill--completed { background: #d1e7dd; color: #0f5132; }
    .awpd-pill--pending { background: #fff3cd; color: #664d03; }
    .awpd-pill--in-progress { background: #cfe2ff; color: #084298; }
    .awpd-pill--failed { background: #f8d7da; color: #842029; }
    .awpd-pill--total { background: #e9ecef; color: #495057; }

    .awpd-notice {
        padding: 0.6rem 0.9rem;
        border-radius: 4px;
        border-left: 4px solid;
    }

    .awpd-notice--success {
        background: #d1e7dd;
        border-color: #0f5132;
        color: #0f5132;
    }

    .awpd-notice--error {
        background: #f8d7da;
        border-color: #842029;
        color: #842029;
    }

    .awpd-pagination {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .awpd-pagination__status {
        color: #646970;
    }

    .awpd-cron {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .awpd-cron__pill {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.25rem 0.65rem;
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 600;
        background: #e9ecef;
        color: #495057;
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
    }

    .awpd-cron__pill--running {
        background: #cfe2ff;
        color: #084298;
    }

    .awpd-cron__pill--scheduled {
        background: #d1e7dd;
        color: #0f5132;
    }

    .awpd-cron__pill--idle {
        background: #e9ecef;
        color: #495057;
    }

    .awpd-cron__pill--warn {
        background: #f8d7da;
        color: #842029;
    }

    .awpd-cron__count {
        opacity: 0.85;
        font-weight: 500;
    }

    .awpd-cron__dot {
        display: inline-block;
        width: 0.55rem;
        height: 0.55rem;
        border-radius: 50%;
        background: #0a7d2e;
        animation: awpd-pulse 1.4s ease-in-out infinite;
    }

    @keyframes awpd-pulse {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.35; transform: scale(0.8); }
    }
</style>
