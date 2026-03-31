
import { useEffect, useState, useCallback, useMemo } from 'react';
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
    statsContainer: {
        display: 'grid',
        gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))',
        gap: '16px'
    },
    statCard: {
        padding: '16px',
        display: 'flex',
        flexDirection: 'column',
        gap: '8px'
    },
    statLabel: {
        color: tokens.colorNeutralForeground2,
        fontSize: '12px',
        textTransform: 'uppercase',
        fontWeight: 600
    },
    statValue: {
        fontSize: '24px',
        fontWeight: 700,
        color: tokens.colorNeutralForeground1
    },
    tableContainer: {
        overflow: 'auto',
        backgroundColor: tokens.colorNeutralBackground1,
        borderRadius: '8px',
        boxShadow: tokens.shadow2,
        padding: '16px',
        display: 'flex',
        flexDirection: 'column'
    },
    positive: { color: tokens.colorPaletteGreenForeground1 },
    negative: { color: tokens.colorPaletteRedForeground1 }
});

interface DividendItem {
    id: number;
    date: string;
    ticker: string;
    type: 'Dividend' | 'Withholding';
    amount: number;
    currency: string;
    amount_czk: number;
    platform: string;
    notes: string;
}

interface DividendStats {
    total_div_czk: number;
    total_tax_czk: number;
    total_net_czk: number;
    count: number;
    by_currency: Record<string, { div: number, tax: number }>;
}

export const DividendsPage = () => {
    const styles = useStyles();
    const { t } = useTranslation();
    const [loading, setLoading] = useState(true);
    const [items, setItems] = useState<DividendItem[]>([]);
    const [stats, setStats] = useState<DividendStats | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        loadData();
    }, []);

    const loadData = async () => {
        setLoading(true);
        try {
            const res = await axios.get('/investyx/api-dividends.php');
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

    const handleFilteredDataChange = useCallback((filteredItems: DividendItem[]) => {
        let total_div_czk = 0;
        let total_tax_czk = 0;
        const count = filteredItems.length;

        filteredItems.forEach(item => {
            const val = Math.abs(item.amount_czk || 0);
            if (item.type === 'Dividend') {
                total_div_czk += val;
            } else if (item.type === 'Withholding') {
                total_tax_czk += val;
            }
        });

        const total_net_czk = total_div_czk - total_tax_czk;

        setStats(prev => {
            const current = prev || { total_div_czk: 0, total_tax_czk: 0, total_net_czk: 0, count: -1 } as DividendStats;
            // Check for equality to prevent infinite loop
            if (
                Math.abs(current.total_div_czk - total_div_czk) < 0.01 &&
                Math.abs(current.total_tax_czk - total_tax_czk) < 0.01 &&
                Math.abs(current.total_net_czk - total_net_czk) < 0.01 &&
                current.count === count
            ) {
                return prev;
            }

            const base = prev || { by_currency: {} } as DividendStats;
            return {
                ...base,
                total_div_czk,
                total_tax_czk,
                total_net_czk,
                count
            };
        });
    }, []);

    // Columns and getRowId MUST be defined before any conditional returns (React Hooks rules)
    const columns = useMemo(() => [
        {
            columnId: 'date', renderHeaderCell: () => t('col_date'), renderCell: (item: DividendItem) => new Date(item.date).toLocaleDateString(t('locale') === 'en' ? 'en-US' : 'cs-CZ'),
            compare: (a: DividendItem, b: DividendItem) => new Date(a.date).getTime() - new Date(b.date).getTime()
        },
        {
            columnId: 'ticker', renderHeaderCell: () => t('col_ticker'), renderCell: (item: DividendItem) => <span style={{ fontWeight: 600 }}>{item.ticker}</span>,
            compare: (a: DividendItem, b: DividendItem) => a.ticker.localeCompare(b.ticker)
        },
        {
            columnId: 'type', renderHeaderCell: () => t('col_type'), renderCell: (item: DividendItem) => (
                item.type === 'Dividend' ?
                    <Badge color="success" shape="rounded">{t('type_dividend')}</Badge> :
                    <Badge color="danger" shape="rounded">{t('type_tax')}</Badge>
            ),
            compare: (a: DividendItem, b: DividendItem) => a.type.localeCompare(b.type)
        },
        {
            columnId: 'amount', renderHeaderCell: () => t('col_amount'), renderCell: (item: DividendItem) => (
                `${item.type === 'Withholding' ? '-' : ''}${Math.abs(item.amount).toFixed(2)}`
            ),
            compare: (a: DividendItem, b: DividendItem) => a.amount - b.amount
        },
        {
            columnId: 'currency', renderHeaderCell: () => t('col_currency'), renderCell: (item: DividendItem) => item.currency,
            compare: (a: DividendItem, b: DividendItem) => a.currency.localeCompare(b.currency)
        },
        {
            columnId: 'amount_czk', renderHeaderCell: () => t('col_czk_gross_tax'), renderCell: (item: DividendItem) => (
                <Text className={item.type === 'Dividend' ? styles.positive : styles.negative}>
                    {item.type === 'Withholding' ? '-' : ''}{Math.abs(item.amount_czk).toFixed(2)}
                </Text>
            ),
            compare: (a: DividendItem, b: DividendItem) => a.amount_czk - b.amount_czk
        },
        {
            columnId: 'platform', renderHeaderCell: () => t('col_platform'), renderCell: (item: DividendItem) => item.platform,
            compare: (a: DividendItem, b: DividendItem) => a.platform.localeCompare(b.platform)
        }
    ], [t, styles.positive, styles.negative]);

    const getRowId = useCallback((item: DividendItem) => item.id, []);

    if (loading) return <Spinner label={t('loading_dividends')} />;
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
                            <div className={styles.statLabel}>{t('div_gross')}</div>
                            <div className={`${styles.statValue} ${styles.positive}`}>
                                {stats.total_div_czk?.toLocaleString(undefined, { maximumFractionDigits: 0 })} Kč
                            </div>
                        </Card>
                        <Card className={styles.statCard}>
                            <div className={styles.statLabel}>{t('div_tax')}</div>
                            <div className={`${styles.statValue} ${styles.negative}`}>
                                -{stats.total_tax_czk?.toLocaleString(undefined, { maximumFractionDigits: 0 })} Kč
                            </div>
                        </Card>
                        <Card className={styles.statCard}>
                            <div className={styles.statLabel}>{t('div_net')}</div>
                            <div className={styles.statValue}>
                                {stats.total_net_czk?.toLocaleString(undefined, { maximumFractionDigits: 0 })} Kč
                            </div>
                        </Card>
                        <Card className={styles.statCard}>
                            <div className={styles.statLabel}>{t('div_count')}</div>
                            <div className={styles.statValue}>{stats.count}</div>
                        </Card>
                    </div>
                )}

                <div className={styles.tableContainer} style={{ flex: 1, minHeight: 0 }}>
                    {items.length === 0 ? (
                        <Text>{t('no_dividends')}</Text>
                    ) : (
                        <div style={{ minWidth: '800px', height: '100%' }}>
                            <SmartDataGrid
                                items={items}
                                columns={columns}
                                getRowId={getRowId}
                                withFilterRow
                                onFilteredDataChange={handleFilteredDataChange}
                            />
                        </div>
                    )}
                </div>
            </PageContent>
        </PageLayout>
    );
};
