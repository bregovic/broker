
import {
    makeStyles,
    tokens,
    Text,
    Spinner,
    Badge,
    Card,
    Toolbar,
    ToolbarButton,
    Dropdown,
    Option
} from "@fluentui/react-components";
import { ArrowClockwise24Regular, GroupList24Regular } from "@fluentui/react-icons";
import { useEffect, useState, useMemo, useCallback } from "react";
import axios from "axios";
import { SmartDataGrid } from "../components/SmartDataGrid";
import { PageLayout, PageContent, PageHeader } from "../components/PageLayout";
import { useTranslation } from "../context/TranslationContext";

const useStyles = makeStyles({
    summaryContainer: {
        display: 'grid',
        gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
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
        border: `1px solid ${tokens.colorNeutralStroke1}`,
        borderRadius: '8px',
        overflow: 'hidden',
        overflowX: 'auto',
        backgroundColor: tokens.colorNeutralBackground1
    },
    cellNum: { textAlign: 'right', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' },
    positive: { color: tokens.colorPaletteGreenForeground1 },
    negative: { color: tokens.colorPaletteRedForeground1 },
    neutral: { color: tokens.colorNeutralForeground1 }
});

interface PortfolioItem {
    ticker: string;
    currency: string;
    platform: string;
    net_qty: number;
    avg_cost_czk: number;
    avg_cost_orig: number;
    current_price: number;
    current_value_czk: number;
    total_cost_czk: number;
    unrealized_czk: number;
    unrealized_pct: number;
    unrealized_orig: number;
    unrealized_pct_orig: number;
    fx_pnl_czk: number;
}

interface PortfolioSummary {
    total_value_czk: number;
    total_cost_czk: number;
    total_unrealized_czk: number;
    count: number;
}

export const BalancePage = () => {
    const styles = useStyles();
    const { t } = useTranslation();
    const [items, setItems] = useState<PortfolioItem[]>([]);
    const [summary, setSummary] = useState<PortfolioSummary | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [groupBy, setGroupBy] = useState<'ticker_platform' | 'ticker'>('ticker_platform');

    useEffect(() => {
        loadData();
    }, [groupBy]);

    const loadData = async () => {
        setLoading(true);
        try {
            const res = await axios.get(`/investyx/api-portfolio.php?groupBy=${groupBy}`);
            if (res.data.success) {
                setItems(res.data.data);
                setSummary(res.data.summary);
            } else {
                setError(res.data.error || 'Failed to load');
            }
        } catch (e) {
            setError('Chyba komunikace se serverem.');
        } finally {
            setLoading(false);
        }
    };

    const handleFilteredDataChange = useCallback((filteredItems: PortfolioItem[]) => {
        let total_value_czk = 0;
        let total_cost_czk = 0;
        let total_unrealized_czk = 0;
        const count = filteredItems.length;

        filteredItems.forEach(item => {
            total_value_czk += item.current_value_czk || 0;
            total_cost_czk += item.total_cost_czk || 0;
            total_unrealized_czk += item.unrealized_czk || 0;
        });

        setSummary(prev => {
            const current = prev || { total_value_czk: 0, total_cost_czk: 0, total_unrealized_czk: 0, count: -1 };
            // Check for equality to prevent infinite loop
            if (
                Math.abs(current.total_value_czk - total_value_czk) < 0.01 &&
                Math.abs(current.total_cost_czk - total_cost_czk) < 0.01 &&
                Math.abs(current.total_unrealized_czk - total_unrealized_czk) < 0.01 &&
                current.count === count
            ) {
                return prev;
            }

            return {
                total_value_czk,
                total_cost_czk,
                total_unrealized_czk,
                count
            };
        });
    }, []);

    // Columns MUST be defined before conditional returns (React Hooks rules)
    const columns = useMemo(() => [
        {
            columnId: 'ticker', renderHeaderCell: () => t('col_symbol'), renderCell: (item: PortfolioItem) => (
                <>
                    <Text weight="semibold">{item.ticker}</Text>
                    <Badge size="small" appearance="tint" style={{ marginLeft: 8 }}>{item.currency}</Badge>
                </>
            ),
            compare: (a: PortfolioItem, b: PortfolioItem) => a.ticker.localeCompare(b.ticker)
        },
        {
            columnId: 'platform', renderHeaderCell: () => t('col_platform'), renderCell: (item: PortfolioItem) => item.platform,
            compare: (a: PortfolioItem, b: PortfolioItem) => a.platform.localeCompare(b.platform)
        },
        {
            columnId: 'net_qty', renderHeaderCell: () => t('col_quantity'), renderCell: (item: PortfolioItem) => item.net_qty.toLocaleString(undefined, { maximumFractionDigits: 4 }),
            compare: (a: PortfolioItem, b: PortfolioItem) => a.net_qty - b.net_qty
        },
        {
            columnId: 'avg_cost_orig', renderHeaderCell: () => t('col_avg_cost_orig'), renderCell: (item: PortfolioItem) => item.avg_cost_orig?.toFixed(2),
            compare: (a: PortfolioItem, b: PortfolioItem) => a.avg_cost_orig - b.avg_cost_orig
        },
        {
            columnId: 'current_price', renderHeaderCell: () => t('col_curr_price'), renderCell: (item: PortfolioItem) => item.current_price?.toFixed(2),
            compare: (a: PortfolioItem, b: PortfolioItem) => a.current_price - b.current_price
        },
        {
            columnId: 'current_value_czk', renderHeaderCell: () => t('col_value_czk'), renderCell: (item: PortfolioItem) => <Text weight="semibold">{item.current_value_czk.toLocaleString(undefined, { maximumFractionDigits: 0 })}</Text>,
            compare: (a: PortfolioItem, b: PortfolioItem) => a.current_value_czk - b.current_value_czk
        },
        {
            columnId: 'unrealized_orig', renderHeaderCell: () => t('col_pnl_orig'), renderCell: (item: PortfolioItem) => (
                <Text className={item.unrealized_orig >= 0 ? styles.positive : styles.negative}>
                    {item.unrealized_orig > 0 ? '+' : ''}{item.unrealized_orig.toLocaleString(undefined, { maximumFractionDigits: 2 })}
                </Text>
            ),
            compare: (a: PortfolioItem, b: PortfolioItem) => a.unrealized_orig - b.unrealized_orig
        },
        {
            columnId: 'unrealized_pct_orig', renderHeaderCell: () => t('col_pnl_pct_orig'), renderCell: (item: PortfolioItem) => (
                <Text className={item.unrealized_pct_orig >= 0 ? styles.positive : styles.negative}>
                    {item.unrealized_pct_orig.toFixed(2)} %
                </Text>
            ),
            compare: (a: PortfolioItem, b: PortfolioItem) => a.unrealized_pct_orig - b.unrealized_pct_orig, sortable: true
        },
        {
            columnId: 'fx_pnl_czk', renderHeaderCell: () => t('col_fx_pnl'), renderCell: (item: PortfolioItem) => (
                <Text className={item.fx_pnl_czk >= 0 ? styles.positive : styles.negative}>
                    {item.fx_pnl_czk.toLocaleString(undefined, { maximumFractionDigits: 0 })}
                </Text>
            ),
            compare: (a: PortfolioItem, b: PortfolioItem) => a.fx_pnl_czk - b.fx_pnl_czk, sortable: true
        },
        {
            columnId: 'unrealized_czk', renderHeaderCell: () => t('col_pnl_czk'), renderCell: (item: PortfolioItem) => (
                <Text weight="semibold" className={item.unrealized_czk >= 0 ? styles.positive : styles.negative}>
                    {item.unrealized_czk.toLocaleString(undefined, { maximumFractionDigits: 0 })}
                </Text>
            ),
            compare: (a: PortfolioItem, b: PortfolioItem) => a.unrealized_czk - b.unrealized_czk, sortable: true
        }
    ], [t, styles.positive, styles.negative]);

    const getRowId = useCallback((item: PortfolioItem) => item.ticker, []);

    if (loading) return <Spinner label={t('loading_balances')} />;
    if (error) return <PageLayout><PageContent><Text>{error}</Text></PageContent></PageLayout>;

    return (
        <PageLayout>
            <PageHeader>
                <Toolbar>
                    <ToolbarButton icon={<ArrowClockwise24Regular />} onClick={loadData}>{t('btn_refresh')}</ToolbarButton>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginLeft: '16px' }}>
                        <GroupList24Regular />
                        <Dropdown
                            value={groupBy === 'ticker_platform' ? 'Ticker + Platforma' : 'Jen Ticker'}
                            selectedOptions={[groupBy]}
                            onOptionSelect={(_, data) => setGroupBy(data.optionValue as 'ticker_platform' | 'ticker')}
                            style={{ minWidth: '180px' }}
                        >
                            <Option value="ticker_platform">Ticker + Platforma</Option>
                            <Option value="ticker">Jen Ticker (agregovaně)</Option>
                        </Dropdown>
                    </div>
                </Toolbar>
            </PageHeader>
            <PageContent>
                {summary && (
                    <div className={styles.summaryContainer}>
                        <Card className={styles.statCard}>
                            <div className={styles.statLabel}>{t('summary_total_value')}</div>
                            <div className={styles.statValue}>
                                {summary.total_value_czk?.toLocaleString(undefined, { maximumFractionDigits: 0 })} Kč
                            </div>
                        </Card>
                        <Card className={styles.statCard}>
                            <div className={styles.statLabel}>{t('summary_buy_cost')}</div>
                            <div className={styles.statValue}>
                                {summary.total_cost_czk?.toLocaleString(undefined, { maximumFractionDigits: 0 })} Kč
                            </div>
                        </Card>
                        <Card className={styles.statCard}>
                            <div className={styles.statLabel}>{t('summary_pnl_czk')}</div>
                            <div className={`${styles.statValue} ${summary.total_unrealized_czk >= 0 ? styles.positive : styles.negative}`}>
                                {summary.total_unrealized_czk > 0 ? '+' : ''}
                                {summary.total_unrealized_czk?.toLocaleString(undefined, { maximumFractionDigits: 0 })} Kč
                            </div>
                            <Text size={200} className={summary.total_unrealized_czk >= 0 ? styles.positive : styles.negative}>
                                {summary.total_cost_czk > 0 ? ((summary.total_unrealized_czk / summary.total_cost_czk) * 100).toFixed(2) : '0'} %
                            </Text>
                        </Card>
                        <Card className={styles.statCard}>
                            <div className={styles.statLabel}>{t('summary_count')}</div>
                            <div className={styles.statValue}>{summary.count}</div>
                        </Card>
                    </div>
                )}

                <div className={styles.tableContainer} style={{ maxHeight: 'calc(100vh - 350px)' }}>
                    <div style={{ minWidth: '800px', height: '100%' }}>
                        <SmartDataGrid
                            items={items}
                            columns={columns}
                            getRowId={getRowId}
                            withFilterRow
                            onFilteredDataChange={handleFilteredDataChange}
                        />
                    </div>
                </div>
            </PageContent>
        </PageLayout>
    );
};
