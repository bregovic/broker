
import { useEffect, useState } from 'react';
import {
    makeStyles,
    tokens,
    Text,
    Spinner,
    Dropdown,
    Option,
    Label,
    Button,
    Dialog,
    DialogSurface,
    DialogBody,
    DialogTitle,
    DialogContent,
    DialogActions,
    Input,
    Toolbar,
    ToolbarButton,
    ToolbarDivider
} from '@fluentui/react-components';
import { Add24Regular, ArrowClockwise24Regular } from '@fluentui/react-icons';
import axios from 'axios';
import { SmartDataGrid } from "../components/SmartDataGrid";
import { PageLayout, PageHeader, PageContent } from "../components/PageLayout";
import { useTranslation } from '../context/TranslationContext';

const useStyles = makeStyles({
    filters: { display: 'flex', gap: '16px', alignItems: 'center' },
    tableContainer: { overflowX: 'auto', backgroundColor: tokens.colorNeutralBackground1, borderRadius: '8px', boxShadow: tokens.shadow2, padding: '16px' },
    formGroup: { display: 'flex', flexDirection: 'column', gap: '8px', marginBottom: '16px' }
});

interface RateItem {
    id: number;
    date: string;
    currency: string;
    rate: number;
    amount: number;
    rate_per_1: number;
    source: string;
}

export const RatesPage = () => {
    const styles = useStyles();
    const { t } = useTranslation();
    const [loading, setLoading] = useState(true);
    const [items, setItems] = useState<RateItem[]>([]);
    const [currencies, setCurrencies] = useState<string[]>([]);
    const [selectedCurrency, setSelectedCurrency] = useState<string>('');
    const [error, setError] = useState<string | null>(null);

    const [isAddOpen, setIsAddOpen] = useState(false);
    const [newRate, setNewRate] = useState({ date: new Date().toISOString().split('T')[0], currency: 'USD', amount: '1', rate: '' });
    const [submitting, setSubmitting] = useState(false);

    // Year Import State
    const [isYearOpen, setIsYearOpen] = useState(false);
    const [selectedYear, setSelectedYear] = useState<string>(new Date().getFullYear().toString());
    const [yearImporting, setYearImporting] = useState(false);

    useEffect(() => {
        loadData();
    }, [selectedCurrency]);

    const loadData = async () => {
        setLoading(true);
        try {
            const params: any = {};
            if (selectedCurrency) params.currency = selectedCurrency;

            const res = await axios.get('/investyx/api-rates-list.php', { params });
            if (res.data.success) {
                setItems(res.data.data);
                setCurrencies(res.data.currencies);
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

    const handleAdd = async () => {
        setSubmitting(true);
        try {
            const res = await axios.post('/investyx/ajax-add-rate.php', {
                date: newRate.date,
                currency: newRate.currency,
                amount: parseFloat(newRate.amount),
                rate: parseFloat(newRate.rate)
            });
            if (res.data.success) {
                setIsAddOpen(false);
                loadData();
                setNewRate({ ...newRate, rate: '' });
            } else {
                alert('Chyba: ' + res.data.error);
            }
        } catch (e) {
            alert('Chyba sítě');
        } finally {
            setSubmitting(false);
        }
    };

    const handleYearImport = async () => {
        setYearImporting(true);
        try {
            const formData = new FormData();
            formData.append('year', selectedYear);

            const res = await axios.post('/investyx/cnb-import-year.php', formData);
            if (res.data.success || res.data.ok) {
                const inserted = res.data.inserted || 0;
                const updated = res.data.updated || 0;
                alert(`Import dokončen.\nVloženo: ${inserted}\nAktualizováno: ${updated}`);
                setIsYearOpen(false);
                loadData();
            } else {
                alert('Chyba: ' + (res.data.message || 'Unknown error'));
            }
        } catch (e: any) {
            alert('Chyba při komunikaci: ' + e.message);
        } finally {
            setYearImporting(false);
        }
    };

    const currentYear = new Date().getFullYear();
    const years = Array.from({ length: currentYear - 1990 }, (_, i) => (currentYear - i).toString());

    return (
        <PageLayout>
            <PageHeader>
                <Toolbar>
                    <ToolbarButton icon={<Add24Regular />} onClick={() => setIsAddOpen(true)}>{t('btn_add_rate')}</ToolbarButton>
                    <ToolbarButton icon={<ArrowClockwise24Regular />} onClick={() => setIsYearOpen(true)}>{t('btn_import_cnb')}</ToolbarButton>
                    <ToolbarDivider />
                    <div className={styles.filters}>
                        <Label size="small">{t('filter_currency')}</Label>
                        <Dropdown
                            placeholder={t('all')}
                            onOptionSelect={(_e, data) => setSelectedCurrency(data.optionValue || '')}
                            value={selectedCurrency || t('all')}
                            size="small"
                        >
                            <Option value="">{t('all')}</Option>
                            {currencies.map(c => <Option key={c} value={c}>{c}</Option>)}
                        </Dropdown>
                    </div>
                </Toolbar>
            </PageHeader>
            <PageContent>
                {loading && <Spinner label={t('loading_rates')} />}

                {!loading && !error && (
                    <div className={styles.tableContainer} style={{ maxHeight: 'calc(100vh - 200px)' }}>
                        <div style={{ minWidth: '800px', height: '100%' }}>
                            <SmartDataGrid
                                items={items}
                                columns={[
                                    { columnId: 'date', renderHeaderCell: () => t('col_date'), renderCell: (item: RateItem) => new Date(item.date).toLocaleDateString(t('locale') === 'en' ? 'en-US' : 'cs-CZ'), compare: (a, b) => a.date.localeCompare(b.date), minWidth: 100 },
                                    { columnId: 'currency', renderHeaderCell: () => t('col_currency'), renderCell: (item: RateItem) => <span style={{ fontWeight: 'bold' }}>{item.currency}</span>, compare: (a, b) => a.currency.localeCompare(b.currency), minWidth: 80 },
                                    { columnId: 'amount', renderHeaderCell: () => t('col_quantity'), renderCell: (item: RateItem) => item.amount.toLocaleString(), compare: (a, b) => a.amount - b.amount, minWidth: 80 },
                                    { columnId: 'rate', renderHeaderCell: () => t('col_rate_czk'), renderCell: (item: RateItem) => item.rate.toFixed(4), compare: (a, b) => a.rate - b.rate, minWidth: 100 },
                                    { columnId: 'rate_per_1', renderHeaderCell: () => t('col_unit'), renderCell: (item: RateItem) => item.rate_per_1.toFixed(4), compare: (a, b) => a.rate_per_1 - b.rate_per_1, minWidth: 100 },
                                    { columnId: 'source', renderHeaderCell: () => t('col_source'), renderCell: (item: RateItem) => item.source, compare: (a, b) => a.source.localeCompare(b.source), minWidth: 100 },
                                ]}
                                getRowId={(item) => item.id}
                            />
                        </div>
                    </div>
                )}
                {error && <Text>{error}</Text>}

                <Dialog open={isAddOpen} onOpenChange={(_e, data) => setIsAddOpen(data.open)}>
                    <DialogSurface>
                        <DialogBody>
                            <DialogTitle>{t('add_rate_title')}</DialogTitle>
                            <DialogContent>
                                <div className={styles.formGroup}>
                                    <Label>{t('col_date')}</Label>
                                    <Input type="date" value={newRate.date} onChange={(e) => setNewRate({ ...newRate, date: e.target.value })} />
                                </div>
                                <div className={styles.formGroup}>
                                    <Label>{t('col_currency')}</Label>
                                    <Input value={newRate.currency} onChange={(e) => setNewRate({ ...newRate, currency: e.target.value.toUpperCase() })} />
                                </div>
                                <div className={styles.formGroup}>
                                    <Label>{t('col_quantity')}</Label>
                                    <Input type="number" value={newRate.amount} onChange={(e) => setNewRate({ ...newRate, amount: e.target.value })} />
                                </div>
                                <div className={styles.formGroup}>
                                    <Label>{t('col_rate_czk')}</Label>
                                    <Input type="number" step="0.0001" value={newRate.rate} onChange={(e) => setNewRate({ ...newRate, rate: e.target.value })} />
                                </div>
                            </DialogContent>
                            <DialogActions>
                                <Button appearance="primary" onClick={handleAdd} disabled={submitting}>{t('common.save')}</Button>
                                <Button appearance="secondary" onClick={() => setIsAddOpen(false)}>{t('common.cancel')}</Button>
                            </DialogActions>
                        </DialogBody>
                    </DialogSurface>
                </Dialog>

                <Dialog open={isYearOpen} onOpenChange={(_e, data) => setIsYearOpen(data.open)}>
                    <DialogSurface>
                        <DialogBody>
                            <DialogTitle>{t('import_cnb_title')}</DialogTitle>
                            <DialogContent>
                                <div className={styles.formGroup}>
                                    <Label>{t('select_year')}</Label>
                                    <Dropdown
                                        value={selectedYear}
                                        onOptionSelect={(_e, data) => setSelectedYear(data.optionValue || '')}
                                    >
                                        {years.map(y => <Option key={y} value={y}>{y}</Option>)}
                                    </Dropdown>
                                </div>
                                <Text>{t('import_cnb_desc')}</Text>
                            </DialogContent>
                            <DialogActions>
                                <Button appearance="primary" onClick={handleYearImport} disabled={yearImporting}>
                                    {yearImporting ? t('btn_importing') : t('btn_import')}
                                </Button>
                                <Button appearance="secondary" onClick={() => setIsYearOpen(false)}>{t('common.cancel')}</Button>
                            </DialogActions>
                        </DialogBody>
                    </DialogSurface>
                </Dialog>
            </PageContent>
        </PageLayout>
    );
};
