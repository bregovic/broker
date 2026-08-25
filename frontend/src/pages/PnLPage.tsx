
import { useEffect, useState, useMemo, useCallback } from 'react';
import {
    makeStyles,
    tokens,
    Card,
    Text,
    Spinner,
    Badge,
    Toolbar,
    ToolbarButton,
    MessageBar,
    MessageBarBody
} from '@fluentui/react-components';
import { ArrowSync24Regular } from "@fluentui/react-icons";
import axios from 'axios';
import { SmartDataGrid } from '../components/SmartDataGrid';
import { ServiceFilter, useServiceFilter } from "../components/ServiceFilter";
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
    fx_czk: number;
    fees_czk: number;
    /** Poplatek + spread, tedy co obchod doopravdy stál. */
    execution_cost_czk?: number;
    net_profit_czk: number;
    tax_test: boolean;
    /** KNOWN | ODVOZENY | UNKNOWN — jak jistá je pořizovací cena. */
    basis_status?: string;
    holding_days: number;
    platform: string;
    currency: string;
}

interface PnLStats {
    net_profit: number;
    realized_profit: number;
    realized_loss: number;
    /** Před poplatky — karty „ziskové/ztrátové obchody“ jsou popsané jako hrubé. */
    gross_profit: number;
    gross_loss: number;
    fx_total: number | null;
    /** U výpisů vedených rovnou v korunách kurzový pohyb z dat vyčíst nejde. */
    fx_znamy?: boolean;
    fees_total: number;
    execution_cost_total: number;
    tax_free_profit: number;
    taxable_profit: number;
    winning: number;
    losing: number;
    total_count: number;
    basis_odvozeny?: number;
    basis_unknown?: number;
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
            const res = await axios.get('/api/api-pnl.php');
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
        const realized_profit = filteredData.filter(i => (i.net_profit_czk || 0) >= 0).reduce((sum, i) => sum + (i.net_profit_czk || 0), 0);
        const realized_loss = filteredData.filter(i => (i.net_profit_czk || 0) < 0).reduce((sum, i) => sum + Math.abs(i.net_profit_czk || 0), 0);
        // Karty „ziskové/ztrátové obchody“ jsou popsané jako hrubé, takže se
        // musí počítat z hrubého zisku — dřív ukazovaly totéž co čistý výsledek.
        const gross_profit = filteredData.filter(i => (i.profit_czk || 0) >= 0).reduce((sum, i) => sum + (i.profit_czk || 0), 0);
        const gross_loss = filteredData.filter(i => (i.profit_czk || 0) < 0).reduce((sum, i) => sum + Math.abs(i.profit_czk || 0), 0);
        const fx_total = filteredData.reduce((sum, i) => sum + (i.fx_czk || 0), 0);
        const fees_total = filteredData.reduce((sum, i) => sum + (i.fees_czk || 0), 0);
        const execution_cost_total = filteredData.reduce((sum, i) => sum + (i.execution_cost_czk || 0), 0);
        const tax_free_profit = filteredData.filter(i => i.tax_test).reduce((sum, i) => sum + (i.net_profit_czk || 0), 0);
        const winning = filteredData.filter(i => (i.net_profit_czk || 0) >= 0).length;
        const losing = filteredData.filter(i => (i.net_profit_czk || 0) < 0).length;

        setStats(prev => {
            const current = prev || { net_profit: 0, realized_profit: 0, realized_loss: 0, gross_profit: 0, gross_loss: 0, fx_total: 0, fees_total: 0, execution_cost_total: 0, tax_free_profit: 0, taxable_profit: 0, winning: 0, losing: 0, total_count: 0 };
            // Check for equality to prevent infinite loop
            if (
                Math.abs(current.net_profit - net_profit) < 0.01 &&
                Math.abs(current.realized_profit - realized_profit) < 0.01 &&
                Math.abs((current.fx_total || 0) - fx_total) < 0.01 &&
                Math.abs((current.fees_total || 0) - fees_total) < 0.01 &&
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
                gross_profit,
                gross_loss,
                fx_total,
                fees_total,
                execution_cost_total,
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
            columnId: 'fx_czk', renderHeaderCell: () => t('col_fx') || 'Kurz. rozdíl', renderCell: (item: PnLItem) => (
                <Text className={(item.fx_czk || 0) >= 0 ? styles.positive : styles.negative}>
                    {(item.fx_czk || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })}
                </Text>
            ),
            compare: (a: PnLItem, b: PnLItem) => (a.fx_czk || 0) - (b.fx_czk || 0)
        },
        {
            columnId: 'fees_czk', renderHeaderCell: () => t('col_fees') || 'Poplatky', renderCell: (item: PnLItem) => (
                <Text className={styles.negative}>
                    {(item.fees_czk || 0) > 0 ? '-' : ''}{(item.fees_czk || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })}
                </Text>
            ),
            compare: (a: PnLItem, b: PnLItem) => (a.fees_czk || 0) - (b.fees_czk || 0)
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
    // Filtruje se před předáním do tabulky, aby souhrnné hodnoty seděly.
    const getPlatform = useCallback((item: PnLItem) => item.platform, []);
    const sluzby = useServiceFilter(items, getPlatform);


    if (loading) return <Spinner label={t('loading_pnl')} />;
    if (error) return <PageLayout><PageContent><Text>{error}</Text></PageContent></PageLayout>;

    return (
        <PageLayout>
            <PageHeader>
                <Toolbar>
                    <ToolbarButton appearance="subtle" icon={<ArrowSync24Regular />} onClick={loadData}>
                        {t('refresh') || 'Obnovit'}
                    </ToolbarButton>
                    <ServiceFilter
                        dostupne={sluzby.dostupne}
                        selected={sluzby.selected}
                        onChange={sluzby.setSelected}
                    />
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
                                +{(stats.gross_profit ?? 0).toLocaleString(undefined, { maximumFractionDigits: 0 })} Kč
                            </div>
                            <Text size={200}>{stats.winning} {t('trades_count')}</Text>
                        </Card>
                        <Card className={styles.statCard}>
                            <div className={styles.statLabel}>{t('pnl_losing')}</div>
                            <div className={`${styles.statValue} ${styles.negative}`}>
                                -{(stats.gross_loss ?? 0).toLocaleString(undefined, { maximumFractionDigits: 0 })} Kč
                            </div>
                            <Text size={200}>{stats.losing} {t('trades_count')}</Text>
                        </Card>
                        <Card className={styles.statCard}>
                            <div className={styles.statLabel}>{t('pnl_fx') || 'Kurzový rozdíl'}</div>
                            {/* Nula by tvrdila, že kurz stál. Když je obchod veden rovnou
                                v korunách, pohyb měny z výpisu prostě nezjistíme. */}
                            {stats.fx_znamy === false ? (
                                <>
                                    <div className={styles.statValue}>—</div>
                                    <Text size={200}>{t('pnl_fx_unknown') || 'nelze určit z výpisu'}</Text>
                                </>
                            ) : (
                                <div className={`${styles.statValue} ${(stats.fx_total || 0) >= 0 ? styles.positive : styles.negative}`}>
                                    {(stats.fx_total || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })} Kč
                                </div>
                            )}
                        </Card>
                        <Card className={styles.statCard}>
                            <div className={styles.statLabel}>{t('pnl_fees') || 'Poplatky'}</div>
                            {/* Vykázaný poplatek nemusí být celý náklad obchodu — u Coinbase
                                je v rozdílu Subtotal/Total ještě spread. */}
                            <div className={`${styles.statValue} ${styles.negative}`}>
                                {((stats.fees_total || 0) + (stats.execution_cost_total || 0)) > 0 ? '-' : ''}
                                {((stats.fees_total || 0) + (stats.execution_cost_total || 0)).toLocaleString(undefined, { maximumFractionDigits: 0 })} Kč
                            </div>
                            {(stats.execution_cost_total || 0) > 0 && (
                                <Text size={200}>
                                    {t('pnl_incl_spread') || 'včetně spreadu'} {(stats.execution_cost_total || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })} Kč
                                </Text>
                            )}
                        </Card>
                        <Card className={styles.statCard}>
                            <div className={styles.statLabel}>{t('pnl_tax_free')}</div>
                            <div className={styles.statValue}>
                                {stats.tax_free_profit?.toLocaleString(undefined, { maximumFractionDigits: 0 })} Kč
                            </div>
                            {/* Nula tu mate: netýká se držených kusů, ale jen toho,
                                co už bylo prodáno a v den prodeje test splňovalo. */}
                            <Text size={200}>
                                {t('pnl_tax_free_hint') || 'z prodaných, ne z držených'}
                            </Text>
                        </Card>
                    </div>
                )}

                {/* Pozice převedená z jiného účtu nemá ve výpisu pořizovací cenu.
                    Dopočítáváme ji z peněz poslaných na burzu — je to odhad a
                    nemá se tvářit jako hotová věc. */}
                {stats && ((stats.basis_odvozeny || 0) > 0 || (stats.basis_unknown || 0) > 0) && (
                    <MessageBar intent={(stats.basis_unknown || 0) > 0 ? 'warning' : 'info'}>
                        <MessageBarBody>
                            {(stats.basis_unknown || 0) > 0
                                ? `U ${stats.basis_unknown} obchodů neznáme pořizovací cenu (chybí historie z původního účtu) — zisk u nich není průkazný.`
                                : `U ${stats.basis_odvozeny} obchodů je pořizovací cena odvozená z peněz poslaných na burzu, ne z konkrétních nákupů.`}
                        </MessageBarBody>
                    </MessageBar>
                )}

                <div className={styles.tableContainer} style={{ flex: 1, minHeight: 0 }}>
                    {items.length === 0 ? (
                        <Text>{t('no_sales')}</Text>
                    ) : (
                        <div style={{ minWidth: '800px', height: '100%' }}>
                            <SmartDataGrid
                                items={sluzby.filtered}
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
