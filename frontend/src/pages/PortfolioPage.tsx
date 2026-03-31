
import {
    makeStyles,
    tokens,
    Spinner,
    Text,
    Badge,
    Toolbar,
    ToolbarButton,
    Dialog,
    DialogSurface,
    DialogBody,
    DialogTitle,
    DialogContent,
    DialogActions,
    Button
} from "@fluentui/react-components";
import type { SelectionItemId, OnSelectionChangeData } from "@fluentui/react-components";
import { ArrowSync24Regular, Delete24Regular, MoneySettings24Regular } from "@fluentui/react-icons";
import { useEffect, useState, useMemo, useCallback } from "react";
import axios from "axios";
import { SmartDataGrid } from "../components/SmartDataGrid";
import { PageLayout, PageContent, PageHeader } from "../components/PageLayout";
import { useTranslation } from "../context/TranslationContext";

const useStyles = makeStyles({
    tableContainer: {
        border: `1px solid ${tokens.colorNeutralStroke1}`,
        borderRadius: '8px',
        overflow: 'auto',
        backgroundColor: tokens.colorNeutralBackground1,
        display: 'flex',
        flexDirection: 'column'
    },
    cellNum: { textAlign: 'right', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' },
    cellDate: { whiteSpace: 'nowrap', width: '100px' },
    buy: { color: tokens.colorPaletteGreenForeground1 },
    sell: { color: tokens.colorPaletteRedForeground1 },
    neutral: { color: tokens.colorNeutralForeground1 }
});

interface TransactionItem {
    trans_id: number;
    date: string;
    ticker: string;
    trans_type: string;
    amount: number;
    price: number;
    currency: string;
    amount_czk: number;
    platform: string;
    product_type: string;
    fees: string;
    ex_rate: number;
    amount_cur: number;
}

export const PortfolioPage = () => {
    const styles = useStyles();
    const { t } = useTranslation();
    const [items, setItems] = useState<TransactionItem[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    // Delete functionality state
    const [filteredItems, setFilteredItems] = useState<TransactionItem[]>([]);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [updatingPrices, setUpdatingPrices] = useState(false);

    // Selection
    const [selectedItems, setSelectedItems] = useState<Set<SelectionItemId>>(new Set());

    const onSelectionChange = useCallback((_: any, data: OnSelectionChangeData) => {
        setSelectedItems(data.selectedItems);
    }, []);

    useEffect(() => {
        loadData();
    }, []);

    const loadData = async () => {
        setLoading(true);
        try {
            const res = await axios.get('/investyx/api-transactions.php');
            if (res.data.success) {
                setItems(res.data.data);
            } else {
                setError(res.data.error || 'Failed to load');
            }
        } catch (e) {
            setError('Chyba komunikace se serverem.');
        } finally {
            setLoading(false);
        }
    };

    const handleDelete = async () => {
        setDeleting(true);
        try {
            // Use selected items if any, otherwise filtered items
            const idsToDelete = selectedItems.size > 0
                ? Array.from(selectedItems)
                : filteredItems.map(i => i.trans_id);

            if (idsToDelete.length === 0) {
                alert('Žádné transakce k odstranění.');
                setDeleteDialogOpen(false);
                setDeleting(false);
                return;
            }

            const res = await axios.post('/investyx/api-delete-transactions.php', {
                ids: idsToDelete
            });

            if (res.data.success) {
                alert(`Úspěšně smazáno ${res.data.deleted} transakcí.`);
                setDeleteDialogOpen(false);
                setSelectedItems(new Set());
                loadData(); // Reload
            } else {
                alert('Chyba: ' + (res.data.error || 'Neznámá chyba'));
            }
        } catch (e: any) {
            alert('Chyba při mazání: ' + e.message);
        } finally {
            setDeleting(false);
        }
    };

    const handleUpdatePrices = async () => {
        setUpdatingPrices(true);
        try {
            // Call backend script to update prices from Yahoo/API
            await axios.post('/investyx/ajax-update-prices.php');
            alert('Tržní data byla aktualizována.');
            loadData();
        } catch (e: any) {
            console.error(e);
            alert('Chyba při aktualizaci cen: ' + (e.response?.data?.error || e.message));
        } finally {
            setUpdatingPrices(false);
        }
    };

    // Columns MUST be defined before conditional returns (React Hooks rules)
    const columns = useMemo(() => [
        {
            columnId: 'date',
            renderHeaderCell: () => t('col_date'),
            renderCell: (item: TransactionItem) => new Date(item.date).toLocaleDateString(t('locale') === 'en' ? 'en-US' : 'cs-CZ'),
            compare: (a: TransactionItem, b: TransactionItem) => new Date(a.date).getTime() - new Date(b.date).getTime(),
        },
        {
            columnId: 'trans_type',
            renderHeaderCell: () => t('col_type'),
            renderCell: (item: TransactionItem) => {
                const isBuy = item.trans_type.toLowerCase() === 'buy';
                const isSell = item.trans_type.toLowerCase() === 'sell';
                return (
                    <Badge appearance="outline" color={isBuy ? 'success' : (isSell ? 'danger' : 'brand')}>
                        {item.trans_type}
                    </Badge>
                );
            },
            compare: (a: TransactionItem, b: TransactionItem) => a.trans_type.localeCompare(b.trans_type),
        },
        {
            columnId: 'ticker',
            renderHeaderCell: () => t('col_ticker'),
            renderCell: (item: TransactionItem) => <Text weight="semibold">{item.ticker}</Text>,
            compare: (a: TransactionItem, b: TransactionItem) => a.ticker.localeCompare(b.ticker),
        },
        {
            columnId: 'amount',
            renderHeaderCell: () => t('col_quantity'),
            renderCell: (item: TransactionItem) => item.amount?.toLocaleString(undefined, { maximumFractionDigits: 6 }),
            compare: (a: TransactionItem, b: TransactionItem) => a.amount - b.amount,
        },
        {
            columnId: 'price',
            renderHeaderCell: () => t('col_prices_unit'),
            renderCell: (item: TransactionItem) => item.price?.toFixed(2),
            compare: (a: TransactionItem, b: TransactionItem) => a.price - b.price,
        },
        {
            columnId: 'currency',
            renderHeaderCell: () => t('col_currency'),
            renderCell: (item: TransactionItem) => <Badge size="small" appearance="tint">{item.currency}</Badge>,
            compare: (a: TransactionItem, b: TransactionItem) => a.currency.localeCompare(b.currency),
        },
        {
            columnId: 'amount_cur',
            renderHeaderCell: () => t('col_total_orig'),
            renderCell: (item: TransactionItem) => item.amount_cur?.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
            compare: (a: TransactionItem, b: TransactionItem) => a.amount_cur - b.amount_cur,
        },
        {
            columnId: 'ex_rate',
            renderHeaderCell: () => t('col_rate'),
            renderCell: (item: TransactionItem) => item.ex_rate?.toFixed(4),
            compare: (a: TransactionItem, b: TransactionItem) => a.ex_rate - b.ex_rate,
        },
        {
            columnId: 'amount_czk',
            renderHeaderCell: () => t('col_total_czk'),
            renderCell: (item: TransactionItem) => {
                const isBuy = item.trans_type.toLowerCase() === 'buy';
                const isSell = item.trans_type.toLowerCase() === 'sell';
                const colorClass = isBuy ? styles.buy : (isSell ? styles.sell : styles.neutral);
                return (
                    <Text weight="semibold" className={colorClass}>
                        {item.amount_czk?.toLocaleString(undefined, { maximumFractionDigits: 0 })}
                    </Text>
                );
            },
            compare: (a: TransactionItem, b: TransactionItem) => a.amount_czk - b.amount_czk,
        },
        {
            columnId: 'platform',
            renderHeaderCell: () => t('col_platform'),
            renderCell: (item: TransactionItem) => item.platform,
            compare: (a: TransactionItem, b: TransactionItem) => a.platform.localeCompare(b.platform),
        },
    ], [t, styles.buy, styles.sell, styles.neutral]);

    const getRowId = useCallback((item: TransactionItem) => item.trans_id, []);

    if (loading) return <Spinner label={t('loading_transactions')} />;
    if (error) return <Text>{error}</Text>;

    return (
        <PageLayout>
            <PageHeader>
                <Toolbar>
                    <ToolbarButton appearance="subtle" icon={<ArrowSync24Regular />} onClick={loadData}>
                        {t('refresh') || 'Obnovit'}
                    </ToolbarButton>

                    <ToolbarButton
                        appearance="subtle"
                        icon={<MoneySettings24Regular />}
                        onClick={handleUpdatePrices}
                        disabled={updatingPrices}
                    >
                        {updatingPrices ? 'Aktualizuji...' : 'Aktualizovat ceny'}
                    </ToolbarButton>

                    <ToolbarButton
                        appearance="subtle"
                        icon={<Delete24Regular />}
                        onClick={() => setDeleteDialogOpen(true)}
                        disabled={selectedItems.size === 0 && filteredItems.length === 0}
                    >
                        {selectedItems.size > 0
                            ? `Smazat vybrané (${selectedItems.size})`
                            : `Smazat zobrazené (${filteredItems.length})`}
                    </ToolbarButton>
                </Toolbar>
            </PageHeader>
            <PageContent noScroll>
                <div className={styles.tableContainer} style={{ flex: 1, minHeight: 0 }}>
                    <div style={{ minWidth: '800px', height: '100%' }}>
                        <SmartDataGrid
                            items={items}
                            columns={columns}
                            getRowId={getRowId}
                            onFilteredDataChange={setFilteredItems}
                            selectedItems={selectedItems}
                            onSelectionChange={onSelectionChange}
                        />
                    </div>
                </div>
            </PageContent>

            {/* Delete Confirmation Dialog */}
            <Dialog open={deleteDialogOpen} onOpenChange={(_, d) => setDeleteDialogOpen(d.open)}>
                <DialogSurface>
                    <DialogBody>
                        <DialogTitle>
                            Smazat {selectedItems.size > 0 ? `${selectedItems.size} vybraných` : `${filteredItems.length} zobrazených`} transakcí?
                        </DialogTitle>
                        <DialogContent>
                            <Text>
                                Opravdu chcete smazat {selectedItems.size > 0 ? selectedItems.size : filteredItems.length} transakcí? Tato akce je nevratná.
                            </Text>
                        </DialogContent>
                        <DialogActions>
                            <Button appearance="primary" onClick={handleDelete} disabled={deleting}>
                                {deleting ? 'Mažu...' : 'Smazat'}
                            </Button>
                            <Button appearance="secondary" onClick={() => setDeleteDialogOpen(false)}>
                                Zrušit
                            </Button>
                        </DialogActions>
                    </DialogBody>
                </DialogSurface>
            </Dialog>
        </PageLayout>
    );
};
