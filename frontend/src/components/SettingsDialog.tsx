
import {
    Dialog,
    DialogSurface,
    DialogTitle,
    DialogBody,
    DialogContent,
    DialogActions,
    Button,
    Dropdown,
    Option,
    Label,
    makeStyles,
    Text,
    tokens,
    Divider,
    Input,
    Tab,
    TabList,
    Spinner,
    Table,
    TableHeader,
    TableRow,
    TableHeaderCell,
    TableBody,
    TableCell
} from '@fluentui/react-components';
import { useTranslation } from '../context/TranslationContext';
import { useState, useEffect } from 'react';
import axios from 'axios';
import { LockClosedRegular, SettingsRegular, DatabaseRegular, GlobeRegular, ArrowLeftRegular, AddRegular } from '@fluentui/react-icons';

const useStyles = makeStyles({
    content: {
        display: 'flex',
        flexDirection: 'column',
        gap: '16px',
        paddingTop: '10px',
        minHeight: '400px',
        maxHeight: '75vh',
        overflowY: 'auto'
    },
    section: {
        display: 'flex',
        flexDirection: 'column',
        gap: '12px',
        padding: '12px',
        border: `1px solid ${tokens.colorNeutralStroke2}`,
        borderRadius: '8px'
    },
    adminBox: {
        marginTop: '20px',
        padding: '20px',
        border: `1px solid ${tokens.colorBrandStroke1}`,
        borderRadius: '12px',
        backgroundColor: tokens.colorBrandBackgroundInverted,
        animationName: { from: { opacity: 0, transform: 'translateY(10px)' }, to: { opacity: 1, transform: 'translateY(0)' } },
        animationDuration: '0.4s'
    }
});

export const SettingsDialog = ({ open, onOpenChange }: { open: boolean, onOpenChange: (open: boolean) => void }) => {
    const styles = useStyles();
    const { language, setLanguage, t } = useTranslation();
    const [saving, setSaving] = useState(false);
    
    // Settings state
    const [baseCurrency, setBaseCurrency] = useState('CZK');
    const [adminPassword, setAdminPassword] = useState('');
    const [isAdmin, setIsAdmin] = useState(false);
    const [activeTab, setActiveTab] = useState<'general' | 'admin'>('general');

    // Lookup states
    const [lookupTable, setLookupTable] = useState<string | null>(null);
    const [lookupData, setLookupData] = useState<any[]>([]);
    const [loadingLookup, setLoadingLookup] = useState(false);

    const getApiUrl = (endpoint: string) => `/api/${endpoint}`;

    useEffect(() => {
        if (open) {
            axios.get(getApiUrl('api-settings.php')).then(res => {
                if (res.data.success && res.data.settings) {
                    setBaseCurrency(res.data.settings.base_currency || 'CZK');
                }
            });
        }
    }, [open]);

    const handleSave = async () => {
        setSaving(true);
        try {
            await axios.post(getApiUrl('api-settings.php'), {
                language,
                base_currency: baseCurrency
            });
            onOpenChange(false);
        } catch (e) {
            alert("Failed to save settings");
        } finally {
            setSaving(false);
        }
    };

    const checkAdmin = (val: string) => {
        setAdminPassword(val);
        if (val === 'Admin123') setIsAdmin(true);
    };

    const loadLookup = async (table: string) => {
        setLookupTable(table);
        setLoadingLookup(true);
        try {
            const res = await axios.get(getApiUrl(`api-admin-lookup.php?table=${table}`));
            if (res.data.success) {
                setLookupData(res.data.data);
            }
        } catch (e) {
            console.error("Lookup load failed", e);
        } finally {
            setLoadingLookup(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(_, data) => { onOpenChange(data.open); if(!data.open) setLookupTable(null); }}>
            <DialogSurface style={{ maxWidth: '600px', width: '95%' }}>
                <DialogBody>
                    <DialogTitle>{t('settings.title')}</DialogTitle>
                    <DialogContent className={styles.content}>
                        
                        {lookupTable ? (
                            <div className={styles.adminBox}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '15px' }}>
                                    <Button icon={<ArrowLeftRegular />} onClick={() => setLookupTable(null)} appearance="subtle" />
                                    <Text size={500} weight="bold">{t(`admin.${lookupTable}`) || lookupTable}</Text>
                                    <div style={{ flexGrow: 1 }} />
                                    <Button icon={<AddRegular />} appearance="primary" size="small">Přidat</Button>
                                </div>
                                
                                {loadingLookup ? <Spinner /> : (
                                    <div style={{ maxHeight: '300px', overflowY: 'auto', border: `1px solid ${tokens.colorNeutralStroke2}`, borderRadius: '4px' }}>
                                        <Table size="small">
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHeaderCell>Název</TableHeaderCell>
                                                    {lookupTable === 'brokers' && <TableHeaderCell>Parser</TableHeaderCell>}
                                                    {lookupTable === 'currencies' && <TableHeaderCell>Zdroj</TableHeaderCell>}
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {lookupData.map((row, idx) => (
                                                    <TableRow key={idx}>
                                                        <TableCell><Text weight="semibold">{row.name || row.currency}</Text></TableCell>
                                                        {lookupTable === 'brokers' && <TableCell>{row.parser_type}</TableCell>}
                                                        {lookupTable === 'currencies' && <TableCell>{row.source || 'CNB'}</TableCell>}
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <>
                                <TabList selectedValue={activeTab} onTabSelect={(_, data) => setActiveTab(data.value as any)}>
                                    <Tab id="general" value="general" icon={<SettingsRegular />}>{t('settings.general') || 'Obecné'}</Tab>
                                    <Tab id="admin" value="admin" icon={<LockClosedRegular />}>{t('settings.admin')}</Tab>
                                </TabList>

                                {activeTab === 'general' && (
                                    <div className={styles.section}>
                                        <div style={{ display: 'flex', flexDirection: 'column', gap: '5px' }}>
                                            <Label>{t('settings.language')}</Label>
                                            <Dropdown
                                                value={language === 'cs' ? 'Čeština' : 'English'}
                                                onOptionSelect={(_, data) => setLanguage(data.optionValue as any)}
                                            >
                                                <Option value="cs" text="Čeština">Čeština</Option>
                                                <Option value="en" text="English">English</Option>
                                            </Dropdown>
                                        </div>

                                        <div style={{ display: 'flex', flexDirection: 'column', gap: '5px' }}>
                                            <Label>{t('settings.currency')}</Label>
                                            <Dropdown
                                                value={baseCurrency}
                                                onOptionSelect={(_, data) => setBaseCurrency(data.optionValue || 'CZK')}
                                            >
                                                <Option value="CZK">CZK - Koruna</Option>
                                                <Option value="USD">USD - Dollar</Option>
                                                <Option value="EUR">EUR - Euro</Option>
                                            </Dropdown>
                                            <Text size={200} style={{ color: tokens.colorNeutralForeground4 }}>Měna, ve které se bude přepočítávat celé portfolio.</Text>
                                        </div>
                                    </div>
                                )}

                                {activeTab === 'admin' && !isAdmin && (
                                    <div className={styles.section}>
                                        <Label>{t('common.admin_pass')}</Label>
                                        <Input 
                                            type="password" 
                                            placeholder="Heslo..." 
                                            value={adminPassword} 
                                            onChange={(e) => checkAdmin(e.target.value)}
                                            contentBefore={<LockClosedRegular />}
                                        />
                                    </div>
                                )}

                                {activeTab === 'admin' && isAdmin && (
                                    <div className={styles.adminBox}>
                                        <Text size={500} weight="bold" block style={{ marginBottom: '15px' }}>{t('admin.config')}</Text>
                                        
                                        <div style={{ display: 'flex', flexDirection: 'column', gap: '15px' }}>
                                            <div className={styles.section}>
                                                <Text weight="semibold"><DatabaseRegular style={{ verticalAlign: 'middle', marginRight: '5px' }} /> {t('admin.lookups') || 'Číselníky'}</Text>
                                                <div style={{ display: 'flex', gap: '10px', flexWrap: 'wrap' }}>
                                                    <Button size="small" onClick={() => loadLookup('brokers')}>{t('admin.brokers')}</Button>
                                                    <Button size="small" onClick={() => loadLookup('currencies')}>{t('admin.currencies')}</Button>
                                                    <Button size="small" onClick={() => loadLookup('asset_types')}>{t('admin.assets')}</Button>
                                                </div>
                                            </div>

                                            <div className={styles.section}>
                                                <Text weight="semibold"><GlobeRegular style={{ verticalAlign: 'middle', marginRight: '5px' }} /> {t('admin.external_api') || 'Externí API'}</Text>
                                                <div className={styles.section} style={{ border: 'none', padding: 0 }}>
                                                    <Label size="small">Yahoo Finance API Key</Label>
                                                    <Input size="small" placeholder="Hardcoded" disabled value="••••••••••••" />
                                                    <Label size="small">ČNB Import</Label>
                                                    <Button size="small" appearance="outline" onClick={() => window.open('/api/cnb-import-year.php', '_blank')}>Ruční trigger (Debug)</Button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </>
                        )}

                        <Divider style={{ margin: '20px 0' }} />
                        <div style={{ padding: '8px', opacity: 0.5 }}>
                            <Text size={100} block>Version: {__APP_VERSION__}</Text>
                            <Text size={100} block>Built: {new Date(__APP_BUILD_DATE__).toLocaleString()}</Text>
                        </div>
                    </DialogContent>
                    <DialogActions>
                        {!lookupTable && (
                            <Button appearance="primary" onClick={handleSave} disabled={saving}>
                                {saving ? <Spinner size="tiny" /> : t('common.save')}
                            </Button>
                        )}
                        <Button appearance="secondary" onClick={() => onOpenChange(false)}>
                            {t('common.cancel')}
                        </Button>
                    </DialogActions>
                </DialogBody>
            </DialogSurface>
        </Dialog>
    );
};
