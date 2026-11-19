export const PAGE_SIZES = [10, 20, 50, 100, 200];

export function normalize(value) {
    return String(value ?? '').replace(/\s+/gu, ' ').trim().toLocaleLowerCase();
}

export function matchesFilters(cells, filters) {
    return Object.entries(filters).every(([index, value]) => normalize(cells[index]).includes(normalize(value)));
}

export function pageRange(total, size, requestedPage = 1) {
    const lastPage = Math.max(1, Math.ceil(total / size));
    const page = Math.max(1, Math.min(lastPage, requestedPage));
    return { page, lastPage, first: total ? (page - 1) * size + 1 : 0, last: Math.min(total, page * size) };
}

function element(tag, className, text) {
    const node = document.createElement(tag);
    node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
}

function button(text, action) {
    const node = element('button', 'records-button', text);
    node.type = 'button';
    node.addEventListener('click', action);
    return node;
}

export function compareValues(left, right, direction = 'asc') {
    function value(raw) {
        const text = String(raw ?? '').replace(/\s+/gu, ' ').trim();
        if (!text || /^(?:[\u2014\u2013-]|Not recorded|Not provided|Unconfigured|Unconfirmed.*|Missing)$/iu.test(text)) return null;
        const amount = text.match(/^(?:\u20b1|PHP\s*)?(-?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?)(?:\s*\/.*)?$/iu);
        if (amount) return Number(amount[1].replaceAll(',', ''));
        const isoDate = text.match(/^(\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?)(?:\s|$)/u);
        if (isoDate) {
            const stamp = isoDate[1].replace(' ', 'T');
            const utc = stamp.length === 10 ? `${stamp}T00:00:00Z` : /(?:Z|[+-]\d{2}:?\d{2})$/u.test(stamp) ? stamp : `${stamp}Z`;
            if (Number.isFinite(Date.parse(utc))) return Date.parse(utc);
        }
        const date = text.match(/^[A-Z][a-z]{2} \d{1,2}, \d{4}(?: \d{1,2}:\d{2} [AP]M)?/u);
        if (date && Number.isFinite(Date.parse(`${date[0]} UTC`))) return Date.parse(`${date[0]} UTC`);
        return normalize(text);
    }
    const a = value(left);
    const b = value(right);
    if (a === null || b === null) return a === b ? 0 : a === null ? 1 : -1;
    const result = typeof a === 'number' && typeof b === 'number'
        ? Math.sign(a - b)
        : String(a).localeCompare(String(b), 'en', { numeric: true, sensitivity: 'base' });
    return direction === 'desc' ? -result : result;
}

function sortIcon() {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    for (const [direction, drawing] of [['asc', 'M7 9l5-5 5 5'], ['desc', 'M7 15l5 5 5-5']]) {
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', drawing);
        path.classList.add(`data-table-chevron-${direction}`);
        svg.append(path);
    }
    return svg;
}

function enhanceTable(table, index) {
    if (table.dataset.tableReady || !table.tHead || !table.tBodies.length) return;
    table.dataset.tableReady = 'true';
    let wrap = table.closest('.records-table-wrap, .table-wrap');
    if (!wrap) {
        wrap = element('div', 'records-table-wrap');
        table.before(wrap);
        wrap.append(table);
    }
    wrap.classList.add('data-table-scroll');
    if (!wrap.hasAttribute('tabindex')) wrap.tabIndex = 0;
    if (!wrap.hasAttribute('role')) wrap.setAttribute('role', 'region');
    if (!wrap.hasAttribute('aria-label') && !wrap.hasAttribute('aria-labelledby')) {
        const heading = wrap.closest('section, article')?.querySelector('h2, h3');
        const title = table.caption?.textContent.trim() || heading?.textContent.trim() || 'Data table';
        wrap.setAttribute('aria-label', `${title}; scroll vertically or horizontally for more records`);
    }
    const marker = wrap.querySelector('.data-table-state');
    const server = marker ? JSON.parse(marker.textContent) : null;
    const id = server?.id ?? `local_${index}`;
    const prefix = `tables[${id}]`;
    const currentUrl = new URL(window.location.href);
    const requestedSize = Number(currentUrl.searchParams.get(`${prefix}[size]`));
    let size = server?.size ?? (PAGE_SIZES.includes(requestedSize) ? requestedSize : 10);
    let page = Number(currentUrl.searchParams.get(`${prefix}[page]`)) || 1;
    const filters = {};
    const inputs = [];
    const sortButtons = new Map();
    const requestedColumn = currentUrl.searchParams.get(`${prefix}[sort][column]`);
    const requestedDirection = currentUrl.searchParams.get(`${prefix}[sort][direction]`);
    let sorting = server ? (server.sort ?? null) : (requestedColumn !== null && /^\d+$/u.test(requestedColumn) && ['asc', 'desc'].includes(requestedDirection)
        ? { column: Number(requestedColumn), direction: requestedDirection } : null);

    function pageSizeControl() {
        const label = element('label', 'data-table-size', 'Rows per page');
        const select = element('select', 'record-input');
        select.name = `${prefix}[size]`;
        select.setAttribute('aria-label', 'Rows per page');
        for (const value of PAGE_SIZES) {
            const option = element('option', '', String(value));
            option.value = String(value);
            option.selected = value === size;
            select.append(option);
        }
        select.addEventListener('change', (event) => {
            size = Number(event.currentTarget.value);
            page = 1;
            if (server) submitServer();
            else renderLocal();
        });
        label.append(select);
        return label;
    }

    function readFilters() {
        for (const [column, input] of inputs.entries()) {
            if (input) filters[column] = input.value.trim();
        }
        return Object.fromEntries(Object.entries(filters).filter(([, value]) => value));
    }

    function writeState(url, activeFilters) {
        for (const key of [...url.searchParams.keys()]) {
            if (key.startsWith(`${prefix}[filters]`)) url.searchParams.delete(key);
        }
        url.searchParams.set(`${prefix}[size]`, String(size));
        url.searchParams.delete(`${prefix}[sort][column]`);
        url.searchParams.delete(`${prefix}[sort][direction]`);
        if (sorting) {
            url.searchParams.set(`${prefix}[sort][column]`, String(sorting.column));
            url.searchParams.set(`${prefix}[sort][direction]`, sorting.direction);
        }
        for (const [column, value] of Object.entries(activeFilters)) {
            url.searchParams.set(`${prefix}[filters][${column}]`, value);
        }
    }

    function submitServer() {
        const url = new URL(window.location.href);
        writeState(url, readFilters());
        url.searchParams.delete(server.pageName);
        window.location.assign(url.href);
    }

    const headers = [...table.tHead.rows[0].cells];
    headers.forEach((header, column) => {
        const text = header.textContent.trim();
        header.scope = 'col';
        if (header.hasAttribute('data-table-no-sort') || /^(actions?|view|source record|recorded source|review)$/iu.test(text)) return;
        const input = { value: server?.filters?.[column] ?? currentUrl.searchParams.get(`${prefix}[filters][${column}]`) ?? '' };
        const trigger = button('', (event) => {
            event.preventDefault();
            sorting = { column, direction: sorting?.column === column && sorting.direction === 'asc' ? 'desc' : 'asc' };
            updateSortIndicators();
            page = 1;
            if (server) submitServer();
            else renderLocal();
        });
        trigger.className = 'data-table-sort-button';
        trigger.append(sortIcon());
        sortButtons.set(column, { trigger, header, label: text });
        inputs[column] = input;
        const heading = element('span', 'data-table-heading');
        heading.append(...header.childNodes, trigger);
        header.append(heading);
    });

    if (sorting && !sortButtons.has(sorting.column)) sorting = null;
    function updateSortIndicators() {
        for (const [column, { trigger, header, label }] of sortButtons) {
            const direction = sorting?.column === column ? sorting.direction : '';
            header.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : direction === 'desc' ? 'descending' : 'none');
            trigger.dataset.direction = direction;
            trigger.classList.toggle('is-active', Boolean(direction));
            const next = direction === 'asc' ? 'descending' : 'ascending';
            trigger.setAttribute('aria-label', `Sort ${label} ${next}`);
            trigger.title = `Sort ${label} ${next}`;
        }
    }
    updateSortIndicators();

    const existingPagination = wrap.nextElementSibling;
    const toolbar = element('div', 'data-table-toolbar');
    toolbar.append(pageSizeControl());
    wrap.before(toolbar);
    const footer = element('div', 'data-table-footer');
    const resultCount = element('p', 'data-table-status data-table-result-count');
    resultCount.setAttribute('role', 'status');
    footer.append(resultCount);
    wrap.after(footer);

    if (server) {
        resultCount.textContent = `Showing ${server.first ?? 0} to ${server.last ?? 0} of ${server.total} results`;
        if (existingPagination?.matches('.records-pagination')) {
            // Keep Laravel's links and query strings; expose one count on all screen sizes.
            existingPagination.querySelector('nav[role="navigation"] p')?.classList.add('data-table-replaced-count');
            footer.append(existingPagination);
        }
        return;
    }

    const body = table.tBodies[0];
    const originalRows = [...body.rows];
    const placeholders = originalRows.filter((row) => row.cells.length === 1 && row.cells[0].colSpan > 1);
    const rows = originalRows.filter((row) => !placeholders.includes(row));
    // Expand colspan cells so mixed payment/history rows keep their column positions.
    const cells = rows.map((row) => [...row.cells].flatMap((cell) => Array(cell.colSpan).fill([...cell.childNodes].map((node) => node.textContent).join(' '))));
    const empty = element('tr', 'data-table-empty');
    const emptyCell = element('td', '', 'No records match these column filters.');
    emptyCell.colSpan = headers.reduce((count, header) => count + header.colSpan, 0);
    empty.append(emptyCell);
    empty.hidden = true;
    body.append(empty);

    const pagination = element('div', 'data-table-pagination');
    const previous = button('Previous', () => { page--; renderLocal(); });
    const next = button('Next', () => { page++; renderLocal(); });
    const pageLabel = element('span', 'data-table-status');
    pagination.append(previous, pageLabel, next);
    footer.append(pagination);
    let matching = [];

    function renderLocal() {
        const activeFilters = readFilters();
        const records = rows.map((row, rowIndex) => ({ row, cells: cells[rowIndex], position: rowIndex }))
            .filter((record) => matchesFilters(record.cells, activeFilters));
        if (sorting) records.sort((a, b) => compareValues(a.cells[sorting.column], b.cells[sorting.column], sorting.direction) || a.position - b.position);
        matching = records.map((record) => record.row);
        body.append(...matching);
        const range = pageRange(matching.length, size, page);
        page = range.page;
        const visible = new Set(matching.slice((page - 1) * size, page * size));
        rows.forEach((row) => { row.hidden = !visible.has(row); });
        placeholders.forEach((row) => { row.hidden = rows.length > 0; });
        empty.hidden = matching.length > 0 || rows.length === 0;
        pageLabel.textContent = `Page ${page} of ${range.lastPage}`;
        resultCount.textContent = `Showing ${range.first} to ${range.last} of ${matching.length} results`;
        previous.disabled = page <= 1;
        next.disabled = page >= range.lastPage;
        const url = new URL(window.location.href);
        writeState(url, activeFilters);
        url.searchParams.set(`${prefix}[page]`, String(page));
        window.history.replaceState(window.history.state, '', url);
    }

    renderLocal();
    window.addEventListener('beforeprint', () => {
        const visible = new Set(matching);
        rows.forEach((row) => { row.hidden = !visible.has(row); });
    });
    window.addEventListener('afterprint', renderLocal);
}

export default function initializeDataTables() {
    document.querySelectorAll('table.records-table, table.recent-validations-table').forEach(enhanceTable);
}
