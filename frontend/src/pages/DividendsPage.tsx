import { useEffect, useState, useCallback, useMemo } from 'react';
import {
    makeStyles,
    tokens,
    Card,
    Text,
    Spinner,
    Badge,
    Toolbar,
    ToolbarButton,
    TabList,
    Tab,
    Button,
    ProgressBar
} from '@fluentui/react-components';
import { ArrowSync24Regular, ArrowDownload24Regular } from "@fluentui/react-icons";
import axios from 'axios';
import { SmartDataGrid } from '../components/SmartDataGrid';
import { PageLayout, PageContent, PageHeader } from '../components/PageLayout';
import { useTranslation } from '../context/TranslationContext';

const useStyles = makeStyles({
    statsContainer: {
        display: 'grid',
        gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))',
        gap: '16px',
        marginBottom: '16px'
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
    negative: { color: tokens.colorPaletteRedForeground1 },
    tabContainer: {
        marginBottom: '16px',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        flexWrap: 'wrap',
        gap: '12px',
        borderBottom: `1px solid ${tokens.colorNeutralStroke2}`,
        paddingBottom: '8px'
    },
    syncPanel: {
        display: 'flex',
        flexDirection: 'column',
        gap: '8px',
        padding: '12px',
        backgroundColor: tokens.colorNeutralBackground2,
        borderRadius: '8px',
        marginBottom: '16px',
        border: `1px solid ${tokens.colorNeutralStroke2}`
    },
    syncProgressRow: {
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        fontSize: '13px'
    }
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

interface MarketDividendItem {
    ticker: string;
    company_name: string;
    currency: string;
    current_price: number;
    current_price_czk: number;
    dividend_rate: number;
    dividend_rate_czk: number;
    dividend_yield: number;
    dividend_frequency: string;
    five_year_avg_yield: number;
    ex_dividend_date: string | null;
    consistency_score: number;
    growth_rate_5y: number;
    payouts_by_year: number[];
}

export const DividendsPage = () => {
    const styles = useStyles();
    const { t } = useTranslation();
    const [loading, setLoading] = useState(true);
    const [items, setItems] = useState<DividendItem[]>([]);
    const [stats, setStats] = useState<DividendStats | null>(null);
    const [error, setError] = useState<string | null>(null);

    // Dual-mode layout states
    const [activeTab, setActiveTab] = useState<'personal' | 'market'>('personal');
    const [marketData, setMarketData] = useState<MarketDividendItem[]>([]);
    const [marketLoading, setMarketLoading] = useState(false);
    
    // Sync states
    const [syncing, setSyncing] = useState(false);
    const [syncProgress, setSyncProgress] = useState(0);
    const [syncMessage, setSyncMessage] = useState('');

    useEffect(() => {
        loadData();
    }, []);

    const loadData = async () => {
        setLoading(true);
        try {
            const res = await axios.get('/api/api-dividends.php');
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

    const loadMarketData = async () => {
        setMarketLoading(true);
        try {
            const res = await axios.get('/api/api-dividend-comparison.php');
            if (res.data.success) {
                setMarketData(res.data.data);
            }
        } catch (err) {
            console.error(err);
        } finally {
            setMarketLoading(false);
        }
    };

    // Auto-fetch market comparison when switching tabs
    useEffect(() => {
        if (activeTab === 'market') {
            loadMarketData();
        }
    }, [activeTab]);

    // Unique tickers inside portfolio
    const uniquePortfolioTickers = useMemo(() => {
        return Array.from(new Set(items.map(item => item.ticker))).filter(Boolean);
    }, [items]);

    // Perform crowdsourced sequential synchronization
    const handleSyncMarketDividends = async () => {
        if (uniquePortfolioTickers.length === 0) return;
        
        setSyncing(true);
        setSyncProgress(0);
        setSyncMessage(t('sync_start') || 'Startuji synchronizaci...');

        let completed = 0;
        for (const ticker of uniquePortfolioTickers) {
            setSyncMessage(`Synchronizuji dividendy pro ${ticker} (${completed + 1}/${uniquePortfolioTickers.length})...`);
            try {
                // Call self-healing fetcher endpoint
                await axios.get(`/api/ajax-fetch-dividends.php?ticker=${encodeURIComponent(ticker)}&force=1`);
            } catch (err) {
                console.error(`Failed to sync ${ticker}:`, err);
            }
            completed++;
            setSyncProgress(Math.round((completed / uniquePortfolioTickers.length) * 100));
        }

        setSyncMessage(t('sync_complete') || 'Synchronizace dokončena!');
        setTimeout(() => {
            setSyncing(false);
            setSyncProgress(0);
            setSyncMessage('');
            loadMarketData();
        }, 1500);
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

    // Personal Dividends Grid Columns
    const personalColumns = useMemo(() => [
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

    // Market Comparison Grid Columns
    const marketColumns = useMemo(() => [
        {
            columnId: 'ticker', renderHeaderCell: () => t('col_ticker') || 'Ticker', renderCell: (item: MarketDividendItem) => <span style={{ fontWeight: 600 }}>{item.ticker}</span>,
            compare: (a: MarketDividendItem, b: MarketDividendItem) => a.ticker.localeCompare(b.ticker)
        },
        {
            columnId: 'company_name', renderHeaderCell: () => t('col_company_name') || 'Firma', renderCell: (item: MarketDividendItem) => <span style={{ color: tokens.colorNeutralForeground2 }}>{item.company_name}</span>,
            compare: (a: MarketDividendItem, b: MarketDividendItem) => a.company_name.localeCompare(b.company_name)
        },
        {
            columnId: 'current_price', renderHeaderCell: () => t('col_price') || 'Cena', renderCell: (item: MarketDividendItem) => (
                item.current_price > 0 ? `${item.current_price.toFixed(2)} ${item.currency}` : '-'
            ),
            compare: (a: MarketDividendItem, b: MarketDividendItem) => a.current_price - b.current_price
        },
        {
            columnId: 'dividend_rate', renderHeaderCell: () => t('col_annual_payout') || 'Roční dividenda', renderCell: (item: MarketDividendItem) => (
                item.dividend_rate > 0 ? (
                    <div style={{ display: 'flex', flexDirection: 'column' }}>
                        <Text style={{ fontWeight: 500 }}>{item.dividend_rate.toFixed(2)} {item.currency}</Text>
                        <Text size={100} style={{ color: tokens.colorNeutralForeground3 }}>{item.dividend_rate_czk.toFixed(2)} Kč</Text>
                    </div>
                ) : '-'
            ),
            compare: (a: MarketDividendItem, b: MarketDividendItem) => a.dividend_rate_czk - b.dividend_rate_czk
        },
        {
            columnId: 'dividend_yield', renderHeaderCell: () => t('col_yield') || 'Výnos %', renderCell: (item: MarketDividendItem) => (
                item.dividend_yield > 0 ? <Text className={styles.positive} style={{ fontWeight: 600 }}>{item.dividend_yield.toFixed(2)} %</Text> : '-'
            ),
            compare: (a: MarketDividendItem, b: MarketDividendItem) => a.dividend_yield - b.dividend_yield
        },
        {
            columnId: 'five_year_avg_yield', renderHeaderCell: () => t('col_avg_yield_5y') || '5letý průměr', renderCell: (item: MarketDividendItem) => (
                item.five_year_avg_yield > 0 ? <Text style={{ color: tokens.colorNeutralForeground2 }}>{item.five_year_avg_yield.toFixed(2)} %</Text> : '-'
            ),
            compare: (a: MarketDividendItem, b: MarketDividendItem) => a.five_year_avg_yield - b.five_year_avg_yield
        },
        {
            columnId: 'consistency_score', renderHeaderCell: () => t('col_consistency') || 'Consistency (5y)', renderCell: (item: MarketDividendItem) => {
                const score = item.consistency_score;
                const color = score === 5 ? 'success' : (score >= 3 ? 'warning' : 'danger');
                return <Badge color={color} shape="rounded">{score} / 5 let</Badge>;
            },
            compare: (a: MarketDividendItem, b: MarketDividendItem) => a.consistency_score - b.consistency_score
        },
        {
            columnId: 'growth_rate_5y', renderHeaderCell: () => t('col_growth_5y') || '5letý růst', renderCell: (item: MarketDividendItem) => (
                item.growth_rate_5y !== 0 ? (
                    <Text className={item.growth_rate_5y > 0 ? styles.positive : styles.negative}>
                        {item.growth_rate_5y > 0 ? '+' : ''}{item.growth_rate_5y.toFixed(1)} %
                    </Text>
                ) : '-'
            ),
            compare: (a: MarketDividendItem, b: MarketDividendItem) => a.growth_rate_5y - b.growth_rate_5y
        },
        {
            columnId: 'ex_dividend_date', renderHeaderCell: () => t('col_ex_date') || 'Ex-dividend datum', renderCell: (item: MarketDividendItem) => {
                if (!item.ex_dividend_date) return '-';
                const exDate = new Date(item.ex_dividend_date);
                const isUpcoming = exDate.getTime() >= new Date().setHours(0,0,0,0);
                return (
                    <Text style={isUpcoming ? { fontWeight: 600, color: tokens.colorPaletteGreenForeground1 } : { color: tokens.colorNeutralForeground3 }}>
                        {exDate.toLocaleDateString(t('locale') === 'en' ? 'en-US' : 'cs-CZ')}
                    </Text>
                );
            },
            compare: (a: MarketDividendItem, b: MarketDividendItem) => {
                const da = a.ex_dividend_date ? new Date(a.ex_dividend_date).getTime() : 0;
                const db = b.ex_dividend_date ? new Date(b.ex_dividend_date).getTime() : 0;
                return da - db;
            }
        },
        {
            columnId: 'dividend_frequency', renderHeaderCell: () => t('col_frequency') || 'Frekvence', renderCell: (item: MarketDividendItem) => {
                const freqMap: Record<string, string> = {
                    'Monthly': t('freq_monthly') || 'Měsíční',
                    'Quarterly': t('freq_quarterly') || 'Kvartální',
                    'Semi-Annual': t('freq_semiannual') || 'Pololetní',
                    'Annual': t('freq_annual') || 'Roční',
                    'Irregular': t('freq_irregular') || 'Nepravidelná'
                };
                return freqMap[item.dividend_frequency] || item.dividend_frequency;
            },
            compare: (a: MarketDividendItem, b: MarketDividendItem) => a.dividend_frequency.localeCompare(b.dividend_frequency)
        }
    ], [t, styles.positive, styles.negative]);

    const getRowId = useCallback((item: DividendItem) => item.id, []);
    const getMarketRowId = useCallback((item: MarketDividendItem) => item.ticker, []);

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
                {/* Stunning Tabs Selector */}
                <div className={styles.tabContainer}>
                    <TabList selectedValue={activeTab} onTabSelect={(_, data) => setActiveTab(data.value as any)}>
                        <Tab value="personal">{t('my_dividends') || 'Moje dividendy'}</Tab>
                        <Tab value="market">{t('market_dividends') || 'Dividendy na trhu'}</Tab>
                    </TabList>

                    {activeTab === 'market' && (
                        <Button 
                            appearance="primary" 
                            icon={syncing ? <Spinner size="tiny" /> : <ArrowDownload24Regular />}
                            disabled={syncing || uniquePortfolioTickers.length === 0} 
                            onClick={handleSyncMarketDividends}
                        >
                            {syncing ? (t('syncing') || 'Synchronizuji...') : (t('sync_market') || 'Sync dividend z trhu')}
                        </Button>
                    )}
                </div>

                {/* Progress bar panel for syncing */}
                {syncing && (
                    <div className={styles.syncPanel}>
                        <div className={styles.syncProgressRow}>
                            <Text weight="semibold">{syncMessage}</Text>
                            <Text>{syncProgress} %</Text>
                        </div>
                        <ProgressBar value={syncProgress / 100} />
                    </div>
                )}

                {activeTab === 'personal' ? (
                    <>
                        {/* Stats Dashboard */}
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

                        {/* Smart Data Grid for Personal Dividends */}
                        <div className={styles.tableContainer} style={{ flex: 1, minHeight: 0 }}>
                            {items.length === 0 ? (
                                <Text>{t('no_dividends')}</Text>
                            ) : (
                                <div style={{ minWidth: '800px', height: '100%' }}>
                                    <SmartDataGrid
                                        items={items}
                                        columns={personalColumns}
                                        getRowId={getRowId}
                                        withFilterRow
                                        onFilteredDataChange={handleFilteredDataChange}
                                    />
                                </div>
                            )}
                        </div>
                    </>
                ) : (
                    /* Smart Data Grid for Market Dividends comparison */
                    <div className={styles.tableContainer} style={{ flex: 1, minHeight: 0 }}>
                        {marketLoading ? (
                            <Spinner label={t('loading_market_data') || 'Načítám srovnání dividend z trhu...'} />
                        ) : marketData.length === 0 ? (
                            <div style={{ padding: '24px', textAlign: 'center', display: 'flex', flexDirection: 'column', gap: '12px', alignItems: 'center' }}>
                                <Text size={400}>{t('no_market_data') || 'Dosud nejsou stažena žádná tržní data o dividendách.'}</Text>
                                <Button appearance="primary" onClick={handleSyncMarketDividends} disabled={uniquePortfolioTickers.length === 0}>
                                    {t('sync_now') || 'Spustit synchronizaci dividend hned'}
                                </Button>
                            </div>
                        ) : (
                            <div style={{ minWidth: '1000px', height: '100%' }}>
                                <SmartDataGrid
                                    items={marketData}
                                    columns={marketColumns}
                                    getRowId={getMarketRowId}
                                    withFilterRow
                                />
                            </div>
                        )}
                    </div>
                )}
            </PageContent>
        </PageLayout>
    );
};
