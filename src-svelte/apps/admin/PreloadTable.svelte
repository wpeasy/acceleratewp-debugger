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

    interface PreloadResponse {
        success: boolean;
        items: PreloadRow[];
        total: number;
        page: number;
        per_page: number;
        total_pages: number;
        summary: Summary;
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
    let total = $state(0);
    let totalPages = $state(0);
    let loading = $state(false);
    let errorMessage = $state('');
    let notice = $state<{ type: 'success' | 'error'; message: string } | null>(null);
    let runningCron = $state(false);
    let rebuildingUrl = $state<string | null>(null);

    let debounceTimer: ReturnType<typeof setTimeout> | null = null;

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
            }
        } catch (err) {
            errorMessage = err instanceof Error ? err.message : 'Request failed.';
            items = [];
            total = 0;
            totalPages = 0;
        } finally {
            loading = false;
        }
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
        <button
            type="button"
            class="button button-primary"
            onclick={runCron}
            disabled={runningCron}
        >
            {runningCron ? 'Running…' : 'Run Cron'}
        </button>
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
</style>
