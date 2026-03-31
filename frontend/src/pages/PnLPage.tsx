
import { useEffect, useState, useMemo, useCallback } from 'react';
import {
    makeStyles,
    tokens,
    Card,
    Text,
    Spinner,
    Badge,
    Toolbar,
    ToolbarButton
} from '@fluentui/react-components';
import { ArrowSync24Regular } from "@fluentui/react-icons";
import axios from 'axios';
import { SmartDataGrid } from '../components/SmartDataGrid';
import { PageLayout, PageContent, PageHeader } from '../components/PageLayout';
import { useTranslation } from '../context/TranslationContext';

const useStyles = makeStyles({
    statsContainer: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '16px' },
    statCard: { padding: '16px', display: 'flex', flexDirection: 'column', gap: '8px' },
    statLabel: { color: tokens.colorNeutralForeground2, fontSize: '12px', textTransform: 'uppercase', fontWeight: 600 },
    statValue: { fontSize: '24px', fontWeight: 700, color: tokens.colorNeutralForeground1 },
    tableContainer: { overflow: 'auto', backgroundColor: tokens.colorNeutralBackground1, borderRadius: '8px', boxShadow: tokens.shadow2, padding: '16px', display: 'flex', flexDirection: 'column' },
    positive: { color: tokens.colorPaletteGreenForeground1 },
    negative: { color: tokens.colorPaletteRedForeground1 }
});

interface PnLItem {
    id: number;
    date: string;
    ticker: string;
    qty: number;
    profit_czk: number;
    net_profit_czk: number;
    tax_test: boolean;
    holding_days: number;
    platform: string;
    currency: string;
}

interface PnLStats {
    net_profit: number;
    realized_profit: number;
    realized_loss: number;
    tax_free_profit: number;
    taxable_profit: number;
    winning: number;
    losing: number;
    total_count: number;
}

export const PnLPage = () => {
    const styles = useStyles();
    const { t } = useTranslation();
    const [loading, setLoading] = useState(true);
    const [items, setItems] = useState<PnLItem[]>([]);
    const [stats, setStats] = useState<PnLStats | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        loadData();
    }, []);

    const loadData = async () => {
        setLoading(true);
        try {
            const res = await axios.get('/investyx/api-pnl.php');
            if (res.data.success) {
                setItems(res.data.data);
                setStats(res.data.stats);
            } else {
                setError(res.data.error || 'Failed to load');
            }
        } catch (err) {
            console.error(err);
            setError('Connection error');
        } finally {
            setLoading(false);
        }
    };

    // Recalculate stats when grid is filtered
    const handleFilteredDataChange = useCallback((filteredData: PnLItem[]) => {
        const net_profit = filteredData.reduce((sum, i) => sum + (i.net_profit_czk || 0), 0);
        const realized_profit = filteredData.filter(i => i.profit_czk >= 0).reduce((sum, i) => sum + i.profit_czk, 0);
        const realized_loss = filteredData.filter(i => i.profit_czk < 0).reduce((sum, i) => sum + Math.abs(i.profit_czk), 0);
        const tax_free_profit = filteredData.filter(i => i.tax_test).reduce((sum, i) => sum + i.net_profit_czk, 0);
        const winning = filteredData.filter(i => i.profit_czk >= 0).length;
        const losing = filteredData.filter(i => i.profit_czk < 0).length;

        setStats(prev => {
            const current = prev || { net_profit: 0, realized_profit: 0, realized_loss: 0, tax_free_profit: 0, taxable_profit: 0, winning: 0, losing: 0, total_count: 0 };
            // Check for equality to prevent infinite loop
            if (
                Math.abs(current.net_profit - net_profit) < 0.01 &&
                Math.abs(current.realized_profit - realized_profit) < 0.01 &&
                current.winning === winning &&
                current.losing === losing
            ) {
                return prev;
            }
            return {
                ...current,
                net_profit,
                realized_profit,
                realized_loss,
                tax_free_profit,
                winning,
                losing,
                total_count: filteredData.length
            };
        });
    }, []);

    // Columns MUST be defined before any conditional returns (React Hooks rules)
    const columns = useMemo(() => [
        {
            columnId: 'date', renderHeaderCell: () => t('col_date'), renderCell: (item: PnLItem) => new Date(item.date).toLocaleDateString(t('locale') === 'en' ? 'en-US' : 'cs-CZ'),
            compare: (a: PnLItem, b: PnLItem) => new Date(a.date).getTime() - new Date(b.date).getTime()
        },
        {
            columnId: 'ticker', renderHeaderCell: () => t('col_ticker'), renderCell: (item: PnLItem) => <span style={{ fontWeight: 600 }}>{item.ticker}</span>,
            compare: (a: PnLItem, b: PnLItem) => a.ticker.localeCompare(b.ticker)
        },
        {
            columnId: 'qty', renderHeaderCell: () => t('col_qty'), renderCell: (item: PnLItem) => item.qty.toLocaleString(),
            compare: (a: PnLItem, b: PnLItem) => a.qty - b.qty
        },
        {
            columnId: 'profit_czk', renderHeaderCell: () => t('col_gross_profit'), renderCell: (item: PnLItem) => (
                <Text className={item.profit_czk >= 0 ? styles.positive : styles.negative}>
                    {item.profit_czk.toLocaleString(undefined, { maximumFractionDigits: 2 })}
                </Text>
            ),
            compare: (a: PnLItem, b: PnLItem) => a.profit_czk - b.profit_czk
        },
        {
            columnId: 'net_profit_czk', renderHeaderCell: () => t('col_net_profit'), renderCell: (item: PnLItem) => (
                <Text weight="bold" className={item.net_profit_czk >= 0 ? styles.positive : styles.negative}>
                    {item.net_profit_czk.toLocaleString(undefined, { maximumFractionDigits: 2 })}
                </Text>
            ),
            compare: (a: PnLItem, b: PnLItem) => a.net_profit_czk - b.net_profit_czk
        },
        {
            columnId: 'tax_test', renderHeaderCell: () => t('col_tax_test'), renderCell: (item: PnLItem) => (
                item.tax_test ? <Badge appearance="outline" color="success">{t('test_passed')}</Badge> : <Badge appearance="outline" color="danger">{t('test_failed')}</Badge>
            ),
            compare: (a: PnLItem, b: PnLItem) => (a.tax_test === b.tax_test ? 0 : a.tax_test ? 1 : -1)
        },
        {
            columnId: 'holding_days', renderHeaderCell: () => t('col_days'), renderCell: (item: PnLItem) => item.holding_days,
            compare: (a: PnLItem, b: PnLItem) => a.holding_days - b.holding_days
        }
    ], [t, styles.positive, styles.negative]);

    const getRowId = useCallback((item: PnLItem) => item.id, []);

    if (loading) return <Spinner label={t('loading_pnl')} />;
    if (error) return <PageLayout><PageContent><Text>{error}</Text></PageContent></PageLayout>;

    return (
        <PageLayout>
            <PageHeader>
                <Toolbar>
                    <ToolbarButton appearance="subtle" icon={<ArrowSync24Regular />} onClick={loadData}>
                        {t('refresh') || 'Obnovit'}
                    </ToolbarButton>
                </Toolbar>
            </PageHeader>
            <PageContent noScroll>
                {stats && (
                    <div className={styles.statsContainer}>
                        <Card className={styles.statCard}>
                            <div className={styles.statLabel}>{t('pnl_net_profit')}</div>
                            <div className={`${styles.statValue} ${stats.net_profit >= 0 ? styles.positive : styles.negative}`}>
                                {stats.net_profit?.toLocaleString(undefined, { maximumFractionDigits: 0 })} Kč
                            </div>
                        </Card>
                        <Card className={styles.statCard}>
                            <div className={styles.statLabel}>{t('pnl_winning')}</div>
                            <div className={`${styles.statValue} ${styles.positive}`}>
                                +{stats.realized_profit?.toLocaleString(undefined, { maximumFractionDigits: 0 })} Kč
                            </div>
                            <Text size={200}>{stats.winning} {t('trades_count')}</Text>
                        </Card>
                        <Card className={styles.statCard}>
                            <div className={styles.statLabel}>{t('pnl_losing')}</div>
                            <div className={`${styles.statValue} ${styles.negative}`}>
                                -{stats.realized_loss?.toLocaleString(undefined, { maximumFractionDigits: 0 })} Kč
                            </div>
                            <Text size={200}>{stats.losing} {t('trades_count')}</Text>
                        </Card>
                        <Card className={styles.statCard}>
                            <div className={styles.statLabel}>{t('pnl_tax_free')}</div>
                            <div className={styles.statValue}>
                                {stats.tax_free_profit?.toLocaleString(undefined, { maximumFractionDigits: 0 })} Kč
                            </div>
                        </Card>
                    </div>
                )}

                <div className={styles.tableContainer} style={{ flex: 1, minHeight: 0 }}>
                    {items.length === 0 ? (
                        <Text>{t('no_sales')}</Text>
                    ) : (
                        <div style={{ minWidth: '800px', height: '100%' }}>
                            <SmartDataGrid
                                items={items}
                                columns={columns}
                                getRowId={getRowId}
                                onFilteredDataChange={handleFilteredDataChange}
                            />
                        </div>
                    )}
                </div>
            </PageContent>
        </PageLayout>
    );
};
