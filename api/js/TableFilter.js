
/**
 * TableFilter.js
 * D365 FO Inspired Column Header Filtering & Sorting
 */
class TableFilter {
    constructor(tableId, options = {}) {
        this.table = document.getElementById(tableId);
        if (!this.table) this.table = document.querySelector(tableId);

        this.options = Object.assign({
            triggerBtnId: null,      // Toggle button ID (deprecated)
            quickFilterId: null,     // Global search input ID
            excludeCols: [],         // Columns to ignore
            onFilter: null
        }, options);

        this.rows = [];
        this.colData = [];
        this.activeFilters = {}; // Map: colIndex -> { operator, value }

        // Popover DOM Element
        this.popover = null;
        this.activeTh = null; // Currently open header

        // Bind esc key to close popover
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') this.closePopover();
        });
        // Click outside
        document.addEventListener('click', (e) => {
            if (this.popover && this.popover.classList.contains('visible')) {
                // If click is NOT in popover AND NOT in a header icon
                if (!this.popover.contains(e.target) && !e.target.closest('.tf-header-icon')) {
                    this.closePopover();
                }
            }
        });
    }

    init() {
        if (!this.table) return;

        // 1. Scan Data
        this.scanTableData();

        // 2. Add Header Icons (Always visible via CSS)
        this.renderHeaderIcons();

        // 3. Create Popover Element (once)
        this.createPopoverElement();

        // 4. Force Enable (if CSS dependent, though we removed CSS dependency)
        this.table.classList.add('tf-enabled');

        // 5. Bind Quick Filter (Global Search)
        if (this.options.quickFilterId) {
            const qf = document.getElementById(this.options.quickFilterId);
            if (qf) {
                // Debounce slightly for better performance
                let timeout = null;
                const handler = () => {
                    this.applyTableFilter();
                };

                qf.addEventListener('keyup', handler);
                qf.addEventListener('change', handler);
                qf.addEventListener('input', handler);
            }
        }
    }

    toggle() {
        this.table.classList.toggle('tf-enabled');
        if (!this.table.classList.contains('tf-enabled')) {
            this.closePopover();
        }
    }

    cleanText(text) {
        if (!text) return '';
        return text.replace(/[\n\r]+/g, ' ').replace(/\s+/g, ' ').replace('★', '').trim();
    }

    scanTableData() {
        const tbody = this.table.querySelector('tbody');
        if (!tbody) return;
        const allTrs = Array.from(tbody.querySelectorAll('tr'));
        this.rows = []; // Re-scan

        allTrs.forEach(tr => {
            if (tr.cells.length > 1) {
                this.rows.push({
                    element: tr,
                    cells: Array.from(tr.cells).map(td => this.cleanText(td.textContent)),
                    originalIndex: tr.rowIndex
                });
            }
        });

        // Column analysis
        if (this.rows.length === 0) return;
        this.colData = [];
        const colCount = this.rows[0].cells.length;

        for (let i = 0; i < colCount; i++) {
            if (this.options.excludeCols.includes(i)) {
                this.colData.push(null); continue;
            }
            const values = new Set();
            let isNumeric = true;
            this.rows.forEach(row => {
                const txt = row.cells[i];
                if (txt && txt !== '—') values.add(txt);
                // Numeric Check
                const nStr = txt.replace(/[^0-9.-]/g, '');
                if (txt !== '' && txt !== '—' && (nStr === '' || isNaN(parseFloat(nStr)))) isNumeric = false;
            });

            this.colData.push({
                uniqueValues: Array.from(values).sort((a, b) => a.localeCompare(b, undefined, { numeric: true })),
                isNumeric: isNumeric
            });
        }
    }

    renderHeaderIcons() {
        const thead = this.table.querySelector('thead');
        if (!thead) return;
        const headers = thead.querySelectorAll('th');

        headers.forEach((th, index) => {
            // Remove existing icon if any
            const existing = th.querySelector('.tf-header-icon');
            if (existing) existing.remove();

            if (this.options.excludeCols.includes(index)) return;

            // Create Icon
            const iconSpan = document.createElement('span');
            iconSpan.className = 'tf-header-icon';
            iconSpan.innerHTML = '<i class="fas fa-filter"></i>';

            iconSpan.addEventListener('click', (e) => {
                e.stopPropagation();
                this.openPopover(index, th);
            });

            th.appendChild(iconSpan);
        });
    }

    createPopoverElement() {
        if (this.popover) return;
        this.popover = document.createElement('div');
        this.popover.className = 'tf-popover';
        document.body.appendChild(this.popover);
    }

    openPopover(colIndex, th) {
        if (this.activeTh === th && this.popover.classList.contains('visible')) {
            this.closePopover();
            return;
        }

        this.activeTh = th;
        this.buildPopoverContent(colIndex, th);

        const rect = th.getBoundingClientRect();
        this.popover.style.top = (rect.bottom + window.scrollY + 5) + 'px';

        if (rect.left + 300 > window.innerWidth) {
            this.popover.style.left = 'auto';
            this.popover.style.right = (window.innerWidth - rect.right) + 'px';
        } else {
            this.popover.style.left = rect.left + 'px';
            this.popover.style.right = 'auto';
        }

        this.popover.classList.add('visible');
    }

    closePopover() {
        if (this.popover) this.popover.classList.remove('visible');
        this.activeTh = null;
    }

    buildPopoverContent(colIndex, th) {
        const colTitle = th.firstChild ? th.firstChild.textContent.trim() : 'Column';
        const currentFilter = this.activeFilters[colIndex] || { operator: 'contains', value: '' };

        let html = `
            <div class="tf-popover-item" id="tf-sort-asc">
                <i class="fas fa-arrow-down-a-z"></i> Seřadit A až Z
            </div>
            <div class="tf-popover-item" id="tf-sort-desc">
                <i class="fas fa-arrow-up-a-z"></i> Seřadit Z až A
            </div>
            <div class="tf-popover-separator"></div>
            
            <div class="tf-filter-section">
                <div class="tf-label">${colTitle}</div>
                <select class="tf-operator-select" id="tf-operator">
                    <option value="contains">Obsahuje</option>
                    <option value="is_exactly">Je přesně</option>
                    <option value="starts_with">Začíná na</option>
                    <option value="not_contains">Neobsahuje</option> 
                    <option value="is_empty">Je prázdné</option>
                    <option value="is_not_empty">Není prázdné</option>
                    <option value="is_one_of">Je jeden z...</option>
                </select>
                
                <!-- Input Container -->
                <div id="tf-input-container"></div>
            </div>

            <div class="tf-popover-footer">
                <button class="tf-btn tf-btn-secondary" id="tf-clear">Vymazat</button>
                <button class="tf-btn tf-btn-primary" id="tf-apply">Aplikovat</button>
            </div>
        `;

        this.popover.innerHTML = html;

        const opSelect = this.popover.querySelector('#tf-operator');
        opSelect.value = currentFilter.operator;

        const inputContainer = this.popover.querySelector('#tf-input-container');

        const renderInputType = () => {
            const op = opSelect.value;
            inputContainer.innerHTML = '';

            if (op === 'is_one_of') {
                const list = document.createElement('div');
                list.className = 'tf-checkbox-list';
                const data = this.colData[colIndex];
                const checkedValues = Array.isArray(currentFilter.value) ? new Set(currentFilter.value) : new Set();

                data.uniqueValues.forEach(val => {
                    const item = document.createElement('label');
                    item.className = 'tf-checkbox-item';
                    item.innerHTML = `<input type="checkbox" value="${val}" ${checkedValues.has(val) ? 'checked' : ''}> ${val || '(Prázdné)'}`;
                    list.appendChild(item);
                });
                inputContainer.appendChild(list);
            } else if (op === 'is_empty' || op === 'is_not_empty') {
                inputContainer.style.display = 'none';
            } else {
                inputContainer.style.display = 'block';
                const inp = document.createElement('input');
                inp.type = 'text';
                inp.className = 'tf-filter-input';
                inp.id = 'tf-value';
                inp.placeholder = 'Hledat...';
                inp.value = (typeof currentFilter.value === 'string') ? currentFilter.value : '';
                inp.addEventListener('keyup', (e) => {
                    if (e.key === 'Enter') document.getElementById('tf-apply').click();
                });
                inp.focus();
                inputContainer.appendChild(inp);
            }
        };

        opSelect.addEventListener('change', renderInputType);
        renderInputType();

        this.popover.querySelector('#tf-sort-asc').onclick = () => { this.sortColumn(colIndex, 'asc'); this.closePopover(); };
        this.popover.querySelector('#tf-sort-desc').onclick = () => { this.sortColumn(colIndex, 'desc'); this.closePopover(); };

        this.popover.querySelector('#tf-apply').onclick = () => {
            const op = opSelect.value;
            let val = null;

            if (op === 'is_one_of') {
                const checked = Array.from(inputContainer.querySelectorAll('input:checked')).map(cb => cb.value);
                if (checked.length > 0) val = checked;
            } else if (op !== 'is_empty' && op !== 'is_not_empty') {
                const inp = inputContainer.querySelector('input');
                if (inp) val = inp.value.trim();
            } else {
                val = true;
            }

            if (val === '' || (Array.isArray(val) && val.length === 0)) {
                delete this.activeFilters[colIndex];
            } else {
                this.activeFilters[colIndex] = { operator: op, value: val };
            }

            this.applyTableFilter();
            this.closePopover();
            this.updateHeaderState(colIndex);
        };

        this.popover.querySelector('#tf-clear').onclick = () => {
            delete this.activeFilters[colIndex];
            this.applyTableFilter();
            this.closePopover();
            this.updateHeaderState(colIndex);
        };
    }

    updateHeaderState(colIndex) {
        const th = this.table.querySelector('thead').querySelectorAll('th')[colIndex];
        const icon = th.querySelector('.tf-header-icon');

        if (this.activeFilters[colIndex]) {
            th.classList.add('tf-filtered');
            icon.classList.add('active');
        } else {
            th.classList.remove('tf-filtered');
            icon.classList.remove('active');
        }
    }

    sortColumn(colIndex, dir) {
        const tbody = this.table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const isNumeric = this.colData[colIndex].isNumeric;

        rows.sort((a, b) => {
            let valA = this.cleanText(a.cells[colIndex].textContent);
            let valB = this.cleanText(b.cells[colIndex].textContent);

            if (isNumeric) {
                valA = parseFloat(valA.replace(/[^0-9.-]/g, '')) || 0;
                valB = parseFloat(valB.replace(/[^0-9.-]/g, '')) || 0;
                return dir === 'asc' ? valA - valB : valB - valA;
            } else {
                return dir === 'asc' ? valA.localeCompare(valB) : valB.localeCompare(valA);
            }
        });
        rows.forEach(tr => tbody.appendChild(tr));
        this.rows = rows.map(tr => ({
            element: tr,
            cells: Array.from(tr.cells).map(td => this.cleanText(td.textContent))
        }));
    }

    applyTableFilter() {
        // 1. Get Quick Filter Value
        const qf = this.options.quickFilterId ? document.getElementById(this.options.quickFilterId) : null;
        const qVal = qf ? qf.value.toLowerCase().trim() : '';
        const hasQuickFilter = qVal.length > 0;

        if (Object.keys(this.activeFilters).length === 0 && !hasQuickFilter) {
            this.rows.forEach(r => r.element.style.display = '');
            return;
        }

        let visibleCount = 0;

        this.rows.forEach(row => {
            let show = true;

            // 2. Global Quick Filter: OR across all text cells
            if (hasQuickFilter) {
                let match = false;
                for (const cellTxt of row.cells) {
                    if (cellTxt.toLowerCase().includes(qVal)) { match = true; break; }
                }
                if (!match) show = false;
            }

            // 3. Specific Column Filters: AND logic
            if (show) {
                for (const [idxStr, filter] of Object.entries(this.activeFilters)) {
                    const idx = parseInt(idxStr);
                    const cellText = row.cells[idx];
                    const cellLower = cellText.toLowerCase();
                    const op = filter.operator;
                    const filterVal = filter.value;

                    if (op === 'is_one_of') {
                        if (!filterVal.includes(cellText)) { show = false; break; }
                    }
                    else if (op === 'is_empty') {
                        if (cellText !== '' && cellText !== '—') { show = false; break; }
                    }
                    else if (op === 'is_not_empty') {
                        if (cellText === '' || cellText === '—') { show = false; break; }
                    }
                    else {
                        const fValLower = (typeof filterVal === 'string') ? filterVal.toLowerCase() : filterVal;
                        if (op === 'contains') {
                            const parts = fValLower.split(',').map(s => s.trim()).filter(s => s !== '');
                            let match = false;
                            if (parts.length === 0) match = true;
                            for (const p of parts) { if (cellLower.includes(p)) { match = true; break; } }
                            if (!match) { show = false; break; }
                        }
                        else if (op === 'not_contains') {
                            if (cellLower.includes(fValLower)) { show = false; break; }
                        }
                        else if (op === 'is_exactly') {
                            if (cellLower !== fValLower) { show = false; break; }
                        }
                        else if (op === 'starts_with') {
                            if (!cellLower.startsWith(fValLower)) { show = false; break; }
                        }
                    }
                }
            }

            row.element.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });

        if (this.options.onFilter) this.options.onFilter(visibleCount);
    }
}
