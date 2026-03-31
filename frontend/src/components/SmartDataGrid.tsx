
import { useState, useMemo, useEffect, useRef, type SyntheticEvent } from 'react';
import type {
    TableColumnDefinition,
    TableColumnId,
    SelectionItemId,
    OnSelectionChangeData,
} from '@fluentui/react-components';
import {
    DataGrid,
    DataGridBody,
    DataGridRow,
    DataGridHeader,
    DataGridHeaderCell,
    DataGridCell,
    Input,
    Button,
    Menu,
    MenuTrigger,
    MenuList,
    MenuItem,
    MenuPopover,
    MenuDivider,
    Popover,
    PopoverSurface,
    PopoverTrigger,
    tokens,
    makeStyles,
} from '@fluentui/react-components';
import {
    ArrowSortUp24Regular,
    ArrowSortDown24Regular,
    Filter24Regular,
    QuestionCircle24Regular,
    ChevronDown24Regular
} from '@fluentui/react-icons';

const useStyles = makeStyles({
    headerCellContent: {
        display: 'flex',
        alignItems: 'stretch',
        width: '100%',
        height: '100%',
        justifyContent: 'space-between',
        ':hover': {
            backgroundColor: tokens.colorNeutralBackground1Hover
        }
    },
    headerLabelArea: {
        flexGrow: 1,
        display: 'flex',
        alignItems: 'center',
        padding: '0 8px',
        cursor: 'pointer',
        gap: '4px',
        overflow: 'hidden',
        whiteSpace: 'nowrap',
        textOverflow: 'ellipsis'
    },
    headerSortArea: {
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '0 8px',
        cursor: 'pointer',
        borderLeft: `1px solid ${tokens.colorNeutralStroke2}`,
        ':hover': {
            backgroundColor: tokens.colorNeutralBackground1Pressed
        }
    },
    filterActive: {
        color: tokens.colorBrandForeground1
    },
    cell: {
        whiteSpace: 'nowrap',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        display: 'block', // Required for textOverflow to work in some flex contexts
        '@media (max-width: 768px)': {
            paddingLeft: '4px',
            paddingRight: '4px',
            fontSize: '12px'
        }
    },
    helpPopover: {
        padding: '12px',
        maxWidth: '300px'
    }
});

export type ExtendedTableColumnDefinition<T> = TableColumnDefinition<T> & {
    align?: 'left' | 'right' | 'center';
    minWidth?: number;
};

interface SmartDataGridProps<T> {
    items: T[];
    columns: ExtendedTableColumnDefinition<T>[];
    getRowId: (item: T) => string | number;
    withFilterRow?: boolean;
    onFilteredDataChange?: (filteredItems: T[]) => void;
    onRowClick?: (item: T) => void;
    selectedItems?: Set<SelectionItemId>;
    onSelectionChange?: (e: SyntheticEvent, data: OnSelectionChangeData) => void;
}

// Smart Date Parser Helper
const parseSmartDate = (input: string): Date | null => {
    if (!input) return null;
    const now = new Date();
    const currentYear = now.getFullYear();

    const trimmed = input.trim();
    if (!trimmed) return null;

    // 1. Case: d or dd (e.g. "1", "31") -> Day in current month
    if (/^\d{1,2}$/.test(trimmed)) {
        const d = parseInt(trimmed, 10);
        if (d >= 1 && d <= 31) {
            return new Date(currentYear, now.getMonth(), d);
        }
        return null;
    }

    // 2. Case: ddmm (e.g. "1707") -> Day + Month in current year
    if (/^\d{3,4}$/.test(trimmed)) {
        const padded = trimmed.padStart(4, '0');
        const d = parseInt(padded.substring(0, 2), 10);
        const m = parseInt(padded.substring(2, 4), 10) - 1;
        if (m >= 0 && m <= 11 && d >= 1 && d <= 31) {
            return new Date(currentYear, m, d);
        }
        return null;
    }

    // 3. Case: dd.mm or dd.mm. (e.g. "1.12")
    const dotMatch = trimmed.match(/^(\d{1,2})\.(\d{1,2})\.?$/);
    if (dotMatch) {
        return new Date(currentYear, parseInt(dotMatch[2], 10) - 1, parseInt(dotMatch[1], 10));
    }

    // 4. Case: Standard Date parse (ISO or locale)
    const fullDateMatch = trimmed.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
    if (fullDateMatch) {
        return new Date(parseInt(fullDateMatch[3], 10), parseInt(fullDateMatch[2], 10) - 1, parseInt(fullDateMatch[1], 10));
    }

    const d = new Date(trimmed);
    return isNaN(d.getTime()) ? null : d;
};

// Sub-component to manage filter state per column menu
const ColumnHeaderMenu = <T,>({
    column,
    sortState,
    currentFilter,
    onSort,
    onApplyFilter,
}: {
    column: ExtendedTableColumnDefinition<T>;
    sortState: { sortDirection: 'ascending' | 'descending'; sortColumn: TableColumnId | undefined; };
    currentFilter: string;
    onSort: (colId: TableColumnId, direction: 'ascending' | 'descending') => void;
    onApplyFilter: (val: string) => void;
}) => {
    const styles = useStyles();
    const [open, setOpen] = useState(false);
    const [tempFilter, setTempFilter] = useState(currentFilter);

    // Reset temporary filter value when menu opens
    useEffect(() => {
        if (open) {
            setTempFilter(currentFilter);
        }
    }, [open, currentFilter]);

    const handleApply = () => {
        onApplyFilter(tempFilter);
        setOpen(false);
    };

    const handleClear = () => {
        onApplyFilter('');
        setOpen(false);
        setTempFilter('');
    };

    const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter') {
            handleApply();
        }
    };

    const handleSortToggle = (e: React.MouseEvent) => {
        e.stopPropagation();
        const isCurrent = sortState.sortColumn === column.columnId;
        const nextDir = isCurrent && sortState.sortDirection === 'ascending' ? 'descending' : 'ascending';
        onSort(column.columnId, nextDir);
    };

    const colId = String(column.columnId);
    const isSorted = sortState.sortColumn === column.columnId;
    const isFiltered = !!currentFilter;
    const align = column.align || 'left';
    const headerContent = column.renderHeaderCell?.() || colId;

    return (
        <div className={styles.headerCellContent}>
            {/* Label Area -> Open Filter Menu */}
            <Menu open={open} onOpenChange={(_, d) => setOpen(d.open)}>
                <MenuTrigger disableButtonEnhancement>
                    <div
                        className={styles.headerLabelArea}
                        style={{ flexDirection: align === 'right' ? 'row-reverse' : 'row' }}
                    >
                        {headerContent}
                        {isFiltered && <Filter24Regular className={styles.filterActive} fontSize={16} />}
                    </div>
                </MenuTrigger>
                <MenuPopover>
                    <MenuList>
                        {/* Sort options in menu too, just in case */}
                        <MenuItem
                            icon={<ArrowSortUp24Regular />}
                            onClick={() => { onSort(column.columnId, 'ascending'); setOpen(false); }}
                        >
                            Seřadit vzestupně
                        </MenuItem>
                        <MenuItem
                            icon={<ArrowSortDown24Regular />}
                            onClick={() => { onSort(column.columnId, 'descending'); setOpen(false); }}
                        >
                            Seřadit sestupně
                        </MenuItem>
                        <MenuDivider />
                        <div style={{ padding: '10px', display: 'flex', flexDirection: 'column', gap: '8px', minWidth: '240px' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: '4px', width: '100%' }}>
                                <Input
                                    placeholder={`Filtrovat...`}
                                    value={tempFilter}
                                    onChange={(_, d) => setTempFilter(d.value)}
                                    onKeyDown={handleKeyDown}
                                    onClick={(e) => e.stopPropagation()}
                                    autoFocus
                                    style={{ flexGrow: 1 }}
                                />
                                <Popover withArrow trapFocus>
                                    <PopoverTrigger disableButtonEnhancement>
                                        <Button icon={<QuestionCircle24Regular />} appearance="transparent" size="small" />
                                    </PopoverTrigger>
                                    <PopoverSurface className={styles.helpPopover}>
                                        <div><strong>Filtrovací operátory:</strong></div>
                                        <ul style={{ margin: '8px 0', paddingLeft: '20px', fontSize: '12px' }}>
                                            <li><code>text</code> : Přesná shoda</li>
                                            <li><code>*text*</code> : Obsahuje (Wildcard)</li>
                                            <li><code>text*</code> : Začíná na</li>
                                            <li><code>A, B</code> : A nebo B (OR)</li>
                                            <li><code>!text</code> : Nerovná se / Neobsahuje</li>
                                            <li><code>15.12</code> : Tento den (v roce {new Date().getFullYear()})</li>
                                            <li><code>15.12.2023</code> : Konkrétní datum</li>
                                            <li><code>15.12..</code> : Od data (včetně)</li>
                                            <li><code>..15.12</code> : Do data (včetně)</li>
                                            <li><code>01..31</code> : Rozsah (od..do)</li>
                                            <li><code>&gt; 100</code> : Větší než</li>
                                            <li><code>&lt; 100</code> : Menší než</li>
                                        </ul>
                                    </PopoverSurface>
                                </Popover>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', gap: '8px' }}>
                                <Button size="small" appearance="primary" onClick={handleApply} style={{ flex: 1 }}>
                                    Použít
                                </Button>
                                <Button size="small" onClick={handleClear} disabled={!currentFilter && !tempFilter}>
                                    Vymazat
                                </Button>
                            </div>
                        </div>
                    </MenuList>
                </MenuPopover>
            </Menu>

            {/* Sort Area -> Toggle Sort */}
            <div className={styles.headerSortArea} onClick={handleSortToggle}>
                {isSorted ? (
                    sortState.sortDirection === 'ascending'
                        ? <ArrowSortUp24Regular fontSize={16} />
                        : <ArrowSortDown24Regular fontSize={16} />
                ) : (
                    <ChevronDown24Regular fontSize={16} />
                )}
            </div>
        </div>
    );
};

export const SmartDataGrid = <T,>({ items, columns, getRowId,
    onFilteredDataChange,
    onRowClick,
    selectedItems,
    onSelectionChange
}: SmartDataGridProps<T>) => {
    const styles = useStyles();
    const [filters, setFilters] = useState<Record<string, string>>({});

    const handleFilterChange = (colId: string, val: string) => {
        setFilters(prev => {
            const next = { ...prev, [colId]: val };
            if (!val) delete next[colId];
            return next;
        });
    };

    const [sortState, setSortState] = useState<{
        sortDirection: 'ascending' | 'descending';
        sortColumn: TableColumnId | undefined;
    }>({
        sortDirection: 'ascending',
        sortColumn: undefined
    });

    const handleSort = (colId: TableColumnId, direction: 'ascending' | 'descending') => {
        setSortState({
            sortColumn: colId,
            sortDirection: direction
        });
    };

    const processedItems = useMemo(() => {
        let result = [...items];

        if (Object.keys(filters).length > 0) {
            // OPTIMIZATION: Pre-parse filters to avoid repeated expensive operations (like Date parsing) per row
            const parsedFilters = Object.entries(filters).map(([colId, val]) => {
                const trimmedVal = val.trim();

                // Smart Operators (>, <)
                let numOp: { type: '>' | '<', limit: number } | null = null;
                if (trimmedVal.startsWith('>')) {
                    const limit = parseFloat(trimmedVal.substring(1).trim());
                    if (!isNaN(limit)) numOp = { type: '>', limit };
                } else if (trimmedVal.startsWith('<')) {
                    const limit = parseFloat(trimmedVal.substring(1).trim());
                    if (!isNaN(limit)) numOp = { type: '<', limit };
                }

                // Smart Date Logic
                const isRange = trimmedVal.includes('..');
                const isPotentialSmartDate = /^\d{1,4}$/.test(trimmedVal) || /^\d{1,2}\.\d{1,2}(\.\d{2,4})?\.?$/.test(trimmedVal);
                let dateOp: { type: 'range' | 'exact', start?: Date | null, end?: Date | null, target?: Date | null, isoTarget?: string } | null = null;

                if (isRange || isPotentialSmartDate) {
                    if (isRange) {
                        const parts = trimmedVal.split('..');
                        const start = parts[0] ? parseSmartDate(parts[0]) : null;
                        const end = parts[1] ? parseSmartDate(parts[1]) : null;
                        if (start) start.setHours(0, 0, 0, 0);
                        if (end) end.setHours(23, 59, 59, 999);
                        dateOp = { type: 'range', start, end };
                    } else {
                        const target = parseSmartDate(trimmedVal);
                        if (target) {
                            target.setHours(0, 0, 0, 0);
                            const isoTarget = `${target.getFullYear()}-${String(target.getMonth() + 1).padStart(2, '0')}-${String(target.getDate()).padStart(2, '0')}`;
                            dateOp = { type: 'exact', target, isoTarget };
                        }
                    }
                }

                // Text Conditions
                const conditions = trimmedVal.split(',').map(s => {
                    let target = s.trim();
                    let isNegation = false;
                    if (target.startsWith('!')) {
                        isNegation = true;
                        target = target.substring(1);
                    }

                    let regex: RegExp | null = null;
                    if (target.includes('*')) {
                        try {
                            const escaped = target.replace(/[.+^${}()|[\]\\]/g, '\\$&');
                            const pattern = escaped.replace(/\*/g, '.*');
                            regex = new RegExp(`^${pattern}$`, 'i');
                        } catch (e) { /* ignore invalid regex */ }
                    }

                    return { text: target, isNegation, regex };
                });

                return { colId, val: trimmedVal, numOp, dateOp, conditions };
            });

            result = result.filter(item => {
                for (const filter of parsedFilters) {
                    const itemValRaw = (item as any)[filter.colId];
                    const itemValStr = (itemValRaw === undefined || itemValRaw === null) ? '' : String(itemValRaw);

                    // 1. Numeric Operators
                    if (filter.numOp) {
                        const itemValNum = parseFloat(itemValStr);
                        if (!isNaN(itemValNum)) {
                            if (filter.numOp.type === '>' && itemValNum > filter.numOp.limit) continue;
                            if (filter.numOp.type === '<' && itemValNum < filter.numOp.limit) continue;
                        } else {
                            // If item is not number but filter is numeric op, it's a mismatch
                            return false;
                        }
                    }

                    // 2. Date Logic
                    if (filter.dateOp) {
                        // Fast path for exact match on ISO dates (YYYY-MM-DD)
                        if (filter.dateOp.type === 'exact' && filter.dateOp.isoTarget && /^\d{4}-\d{2}-\d{2}$/.test(itemValStr)) {
                            if (itemValStr === filter.dateOp.isoTarget) continue;
                            // If ISO match fails, fall through to object match (just in case) or text search?
                            // Let's rely on date validation. If it doesn't match date, return false.
                            return false;
                        }

                        let itemDate: Date | null = null;
                        if (/^\d{4}-\d{2}-\d{2}$/.test(itemValStr)) {
                            const [y, m, d] = itemValStr.split('-').map(n => parseInt(n, 10));
                            itemDate = new Date(y, m - 1, d);
                        } else if (!isNaN(Date.parse(itemValStr)) && itemValStr.includes('-')) {
                            itemDate = new Date(itemValStr);
                        }

                        if (itemDate) {
                            itemDate.setHours(0, 0, 0, 0);
                            const t = itemDate.getTime();
                            if (filter.dateOp.type === 'range') {
                                if (filter.dateOp.start && t < filter.dateOp.start.getTime()) return false;
                                if (filter.dateOp.end && t > filter.dateOp.end.getTime()) return false;
                                continue;
                            } else if (filter.dateOp.type === 'exact') {
                                if (filter.dateOp.target && t !== filter.dateOp.target.getTime()) return false;
                                continue;
                            }
                        }
                    }

                    // 3. Text/Wildcard Logic
                    if (filter.conditions.length === 0) continue;

                    const match = filter.conditions.some(cond => {
                        let isMatch = false;
                        if (cond.text === '""' || cond.text === "''") {
                            isMatch = (itemValStr === '');
                        } else if (cond.text === '') {
                            isMatch = true;
                        } else if (cond.regex) {
                            isMatch = cond.regex.test(itemValStr);
                        } else {
                            isMatch = itemValStr.toLowerCase() === cond.text.toLowerCase();
                        }
                        return cond.isNegation ? !isMatch : isMatch;
                    });

                    if (!match) return false;
                }
                return true;
            });
        }

        if (sortState.sortColumn) {
            const colDef = columns.find(c => c.columnId === sortState.sortColumn);
            if (colDef && colDef.compare) {
                result.sort((a, b) => {
                    const cmp = colDef.compare(a, b);
                    return sortState.sortDirection === 'ascending' ? cmp : -cmp;
                });
            }
        }

        return result;
    }, [items, filters, sortState, columns]);

    // Notify parent about filtered data change - FIX: checking for actual content change to prevent loop
    const lastNotifiedRef = useRef<T[] | null>(null);

    useEffect(() => {
        if (onFilteredDataChange) {
            const current = processedItems;
            const last = lastNotifiedRef.current;

            let changed = true;
            if (last && last.length === current.length) {
                // Check if contents are same reference
                // Since filtered array from useMemo preserves item references if source items are strictly same
                changed = false;
                for (let i = 0; i < last.length; i++) {
                    if (last[i] !== current[i]) {
                        changed = true;
                        break;
                    }
                }
            }

            if (changed) {
                lastNotifiedRef.current = current;
                onFilteredDataChange(current);
            }
        }
    }, [processedItems, onFilteredDataChange]);

    const ROW_HEIGHT = 40;
    const OVERSCAN = 10;
    const isVirtualized = processedItems.length > 100;

    const scrollContainerRef = useRef<HTMLDivElement>(null);
    const [scrollTop, setScrollTop] = useState(0);

    const handleScroll = (e: React.UIEvent<HTMLDivElement>) => {
        setScrollTop(e.currentTarget.scrollTop);
    };

    const totalHeight = processedItems.length * ROW_HEIGHT;
    const containerHeight = scrollContainerRef.current?.clientHeight || 600;

    const startIndex = Math.max(0, Math.floor(scrollTop / ROW_HEIGHT) - OVERSCAN);
    const endIndex = Math.min(processedItems.length, Math.ceil((scrollTop + containerHeight) / ROW_HEIGHT) + OVERSCAN);

    const visibleItems = isVirtualized ? processedItems.slice(startIndex, endIndex) : processedItems;
    const offsetY = startIndex * ROW_HEIGHT;

    // Sticky Header Rendering Helper
    const renderHeader = () => (
        <DataGrid
            items={processedItems}
            columns={columns}
            sortable={false}
            selectionMode="multiselect"
            getRowId={getRowId}
            selectedItems={selectedItems}
            onSelectionChange={onSelectionChange}
        >
            <DataGridHeader style={{ position: 'sticky', top: 0, zIndex: 2, background: tokens.colorNeutralBackground1 }}>
                <DataGridRow>
                    {({ item, columnId }: any) => {
                        const col = item || columns.find(c => c.columnId === columnId);
                        if (!col) return null;
                        const extCol = col as ExtendedTableColumnDefinition<T>;
                        return (
                            <DataGridHeaderCell style={{ padding: 0, minWidth: extCol.minWidth ? `${extCol.minWidth}px` : undefined }}>
                                <ColumnHeaderMenu
                                    column={extCol}
                                    sortState={sortState}
                                    currentFilter={filters[String(extCol.columnId)] || ''}
                                    onSort={handleSort}
                                    onApplyFilter={(val) => handleFilterChange(String(extCol.columnId), val)}
                                />
                            </DataGridHeaderCell>
                        );
                    }}
                </DataGridRow>
            </DataGridHeader>
        </DataGrid>
    );

    const renderBody = (itemsToRender: T[]) => (
        <DataGrid
            items={itemsToRender}
            columns={columns}
            sortable={false}
            selectionMode="multiselect"
            getRowId={getRowId}
            selectedItems={selectedItems}
            onSelectionChange={onSelectionChange}
        >
            <DataGridBody<T>>
                {({ item, rowId }) => (
                    <DataGridRow<T>
                        key={rowId}
                        style={{
                            ...(isVirtualized ? { height: `${ROW_HEIGHT}px` } : {}),
                            cursor: onRowClick ? 'pointer' : undefined
                        }}
                        onClick={() => onRowClick?.(item)}
                    >
                        {({ item: col, columnId, renderCell }: any) => {
                            const colDef = col || columns.find(c => c.columnId === columnId);
                            const extCol = colDef as ExtendedTableColumnDefinition<T>;
                            return (
                                <DataGridCell
                                    style={{
                                        textAlign: extCol?.align || 'left',
                                        justifyContent: extCol?.align === 'right' ? 'flex-end' : (extCol?.align === 'center' ? 'center' : 'flex-start'),
                                        minWidth: extCol?.minWidth ? `${extCol.minWidth}px` : undefined
                                    }}
                                    className={styles.cell}
                                >
                                    {renderCell(item)}
                                </DataGridCell>
                            );
                        }}
                    </DataGridRow>
                )}
            </DataGridBody>
        </DataGrid>
    );

    if (!isVirtualized) {
        return (
            <div style={{ display: 'flex', flexDirection: 'column', height: '100%', overflow: 'hidden' }}>
                <div style={{ flex: 1, overflow: 'auto' }}>
                    <DataGrid
                        items={processedItems}
                        columns={columns}
                        sortable={false}
                        selectionMode="multiselect"
                        getRowId={getRowId}
                        selectedItems={selectedItems}
                        onSelectionChange={onSelectionChange}
                    >
                        <DataGridHeader style={{ position: 'sticky', top: 0, zIndex: 2, background: tokens.colorNeutralBackground1 }}>
                            <DataGridRow>
                                {({ item, columnId }: any) => {
                                    const col = item || columns.find(c => c.columnId === columnId);
                                    if (!col) return null;
                                    const extCol = col as ExtendedTableColumnDefinition<T>;
                                    return (
                                        <DataGridHeaderCell style={{ padding: 0, minWidth: extCol.minWidth ? `${extCol.minWidth}px` : undefined }}>
                                            <ColumnHeaderMenu
                                                column={extCol}
                                                sortState={sortState}
                                                currentFilter={filters[String(extCol.columnId)] || ''}
                                                onSort={handleSort}
                                                onApplyFilter={(val) => handleFilterChange(String(extCol.columnId), val)}
                                            />
                                        </DataGridHeaderCell>
                                    );
                                }}
                            </DataGridRow>
                        </DataGridHeader>
                        <DataGridBody<T>>
                            {({ item, rowId }) => (
                                <DataGridRow<T>
                                    key={rowId}
                                    style={{ cursor: onRowClick ? 'pointer' : undefined }}
                                    onClick={() => onRowClick?.(item)}
                                >
                                    {({ item: col, columnId, renderCell }: any) => {
                                        const colDef = col || columns.find(c => c.columnId === columnId);
                                        const extCol = colDef as ExtendedTableColumnDefinition<T>;
                                        return (
                                            <DataGridCell
                                                style={{
                                                    textAlign: extCol?.align || 'left',
                                                    justifyContent: extCol?.align === 'right' ? 'flex-end' : (extCol?.align === 'center' ? 'center' : 'flex-start'),
                                                    minWidth: extCol?.minWidth ? `${extCol.minWidth}px` : undefined
                                                }}
                                                className={styles.cell}
                                            >
                                                {renderCell(item)}
                                            </DataGridCell>
                                        );
                                    }}
                                </DataGridRow>
                            )}
                        </DataGridBody>
                    </DataGrid>
                </div>
            </div>
        );
    }

    // Virtualized Render
    return (
        <div style={{ display: 'flex', flexDirection: 'column', height: '100%', overflow: 'hidden' }}>
            {/* Fixed Header */}
            <div style={{ flexShrink: 0, borderBottom: `1px solid ${tokens.colorNeutralStroke1}` }}>
                {renderHeader()}
            </div>

            {/* Scrollable Body */}
            <div
                ref={scrollContainerRef}
                display-name="ScrollContainer"
                style={{ flex: 1, overflow: 'auto' }}
                onScroll={handleScroll}
            >
                <div style={{ height: `${totalHeight}px`, position: 'relative' }}>
                    <div style={{ position: 'absolute', top: 0, left: 0, right: 0, transform: `translateY(${offsetY}px)` }}>
                        {renderBody(visibleItems)}
                    </div>
                </div>
            </div>
        </div>
    );
};
