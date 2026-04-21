import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { 
    Button, Card, Text, Table, TableHeader, TableRow, TableHeaderCell, 
    TableBody, TableCell, Dropdown, Option, ProgressBar, Badge,
    Subtitle1, Caption1, Body1, makeStyles, tokens,
    Title3
} from "@fluentui/react-components";
import { 
    DocumentAdd24Regular, 
    Play24Regular, 
    Dismiss24Regular, 
    ArrowSync24Regular,
    CheckmarkCircle24Regular,
    DocumentPdf24Regular,
    Table24Regular,
    Delete24Regular
} from "@fluentui/react-icons";
import { useTranslation } from '../context/TranslationContext';

const useStyles = makeStyles({
    container: {
        padding: '32px',
        maxWidth: '1200px',
        margin: '0 auto',
        display: 'flex',
        flexDirection: 'column',
        gap: '24px',
    },
    card: {
        padding: '24px',
        boxShadow: '0 4px 20px rgba(0,0,0,0.06)',
        borderRadius: '12px',
        backgroundColor: '#ffffff'
    },
    tableCell: {
        padding: '12px 8px',
        verticalAlign: 'middle'
    },
    dropZone: {
        border: `2px dashed ${tokens.colorNeutralStroke1}`,
        borderRadius: '12px',
        padding: '60px 40px',
        textAlign: 'center',
        cursor: 'pointer',
        transition: 'all 0.2s ease',
        backgroundColor: '#fafafa',
        ':hover': {
            borderColor: tokens.colorBrandStroke1 as any,
            backgroundColor: '#f0f4ff'
        }
    },
    dragging: {
        borderColor: tokens.colorBrandStroke1 as any,
        backgroundColor: '#eef2ff'
    },
    resultSummary: {
        display: 'flex',
        gap: '24px',
        marginBottom: '20px'
    }
});

interface DiagnosticItem {
    filename: string;
    extension: string;
    broker: string;
    parser: string;
    parser_class: string | null;
    rule_id: number | null;
    tx_count: number;
    asset_type: string;
    temp_file: string; // UUID from staging
    success: boolean;
    error: string | null;
}

interface Rule {
    id: number;
    rule_name: string;
    broker_name: string;
}

export const ImportPage: React.FC = () => {
    const styles = useStyles();
    const { t } = useTranslation();
    const [step, setStep] = useState(1);
    const [analyzing, setAnalyzing] = useState(false);
    const [importing, setImporting] = useState(false);
    const [diagnostics, setDiagnostics] = useState<DiagnosticItem[]>([]);
    const [rules, setRules] = useState<Rule[]>([]);
    const [results, setResults] = useState<any[]>([]);
    const [dragging, setDragging] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        axios.get('/api/v3/api-import.php?action=list_rules')
            .then(res => setRules(res.data.rules || []))
            .catch(err => console.error('Failed to load rules', err));
    }, []);

    const reset = () => {
        setStep(1);
        setDiagnostics([]);
        setResults([]);
        setAnalyzing(false);
        setImporting(false);
        setError(null);
    };

    const handleAnalyze = async (fileList: FileList) => {
        setAnalyzing(true);
        setStep(2);
        setError(null);
        
        const formData = new FormData();
        Array.from(fileList).forEach(f => formData.append('files[]', f));

        try {
            const res = await axios.post('/api/v3/api-import.php?action=analyze', formData);
            console.log('[DEBUG] Analyze response:', res.data);
            if (res.data.success) {
                // Merge or Replace? Let's append for "Add more"
                setDiagnostics(prev => [...prev, ...(res.data.data || [])]);
            } else {
                setError(res.data.message);
            }
        } catch (e: any) {
            console.error('[DEBUG] Analyze failure:', e);
            setError('Chyba při nahrávání: ' + e.message);
        } finally {
            setAnalyzing(false);
        }
    };

    const handleRuleChange = (temp_file: string, ruleId: number) => {
        const rule = rules.find(r => r.id === ruleId);
        setDiagnostics(prev => prev.map(d => 
            d.temp_file === temp_file 
                ? { ...d, rule_id: ruleId, broker: rule?.rule_name || 'Manual' } 
                : d
        ));
    };

    const handleExecuteImport = async () => {
        setImporting(true);
        try {
            const res = await axios.post('/api/v3/api-import.php?action=import', { items: diagnostics });
            if (res.data.success) {
                setResults(res.data.summary);
                setStep(3);
            } else {
                setError(res.data.message);
            }
        } catch (e: any) {
            setError('Chyba importu: ' + e.message);
        } finally {
            setImporting(false);
        }
    };

    const getFileIcon = (ext: string) => {
        return ext === 'pdf' ? <DocumentPdf24Regular style={{ color: tokens.colorPaletteRedForeground1 }} /> : <Table24Regular style={{ color: tokens.colorBrandForeground1 }} />;
    };

    // Step 1: Upload
    if (step === 1) {
        return (
            <div className={styles.container}>
                <div style={{ textAlign: 'center', marginBottom: '20px' }}>
                    <Title3 block>{t('import.title')}</Title3>
                    <Caption1 block color="gray">{t('import.desc')}</Caption1>
                </div>
                
                <Card className={styles.card}>
                    <div 
                        className={`${styles.dropZone} ${dragging ? styles.dragging : ''}`}
                        onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
                        onDragLeave={() => setDragging(false)}
                        onDrop={(e) => { e.preventDefault(); setDragging(false); handleAnalyze(e.dataTransfer.files); }}
                        onClick={() => document.getElementById('fileInput')?.click()}
                    >
                        <DocumentAdd24Regular style={{ fontSize: '48px', color: tokens.colorBrandForeground1, marginBottom: '16px' }} />
                        <Title3 block>{t('import.drop_zone')}</Title3>
                        <Text block color="gray">{t('import.drop_zone_sub')}</Text>
                        <input type="file" id="fileInput" style={{ display: 'none' }} onChange={(e) => e.target.files && handleAnalyze(e.target.files)} multiple />
                    </div>
                </Card>
            </div>
        );
    }

    // Step 2: Diagnostic List
    if (step === 2) {
        return (
            <div className={styles.container}>
                <Card className={styles.card} appearance="subtle" style={{ backgroundColor: 'transparent', padding: '0', boxShadow: 'none' }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '24px' }}>
                        <div>
                            <Title3 block style={{ fontWeight: 600 }}>{t('import.step2_title')}</Title3>
                            <Caption1 block style={{ color: '#666' }}>{t('import.step2_desc')}</Caption1>
                        </div>
                        <div style={{ display: 'flex', gap: '12px' }}>
                            <Button icon={<DocumentAdd24Regular />} appearance="secondary" onClick={() => document.getElementById('fileInputMore')?.click()}>{t('import.btn_add_more')}</Button>
                            <Button icon={<Play24Regular />} appearance="primary" disabled={analyzing || importing || diagnostics.length === 0} onClick={handleExecuteImport}>{t('import.btn_start')}</Button>
                            <Button icon={<Dismiss24Regular />} appearance="subtle" onClick={reset}>{t('common.cancel')}</Button>
                        </div>
                    </div>
                    
                    <input type="file" id="fileInputMore" style={{ display: 'none' }} onChange={(e) => e.target.files && handleAnalyze(e.target.files)} multiple />

                    <div style={{ backgroundColor: '#fff', borderRadius: '12px', border: '1px solid #edebe9', overflow: 'hidden', boxShadow: '0 4px 12px rgba(0,0,0,0.04)' }}>
                        <Table aria-label="Diagnostic table" size="medium">
                            <TableHeader style={{ backgroundColor: '#f8f9fa' }}>
                                <TableRow>
                                    <TableHeaderCell style={{ width: '40%', paddingLeft: '24px' }}>{t('import.col_file')}</TableHeaderCell>
                                    <TableHeaderCell style={{ width: '25%' }}>{t('import.col_parser')}</TableHeaderCell>
                                    <TableHeaderCell style={{ width: '15%', textAlign: 'center' }}>{t('import.col_asset_type')}</TableHeaderCell>
                                    <TableHeaderCell style={{ width: '15%', textAlign: 'right' }}>{t('import.col_found')}</TableHeaderCell>
                                    <TableHeaderCell style={{ width: '5%' }} />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {diagnostics.length === 0 && !analyzing && (
                                    <TableRow>
                                        <TableCell colSpan={5}>
                                            <div style={{ padding: '60px', textAlign: 'center' }}>
                                                <Text italic color="gray">{t('import.empty_list')}</Text>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                )}
                                {diagnostics.map((d, i) => (
                                    <TableRow key={d.temp_file || i}>
                                        <TableCell className={styles.tableCell} style={{ paddingLeft: '24px' }}>
                                            <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                                                <div style={{ 
                                                    backgroundColor: d.extension === 'pdf' ? '#fff1f0' : '#f0f4ff', 
                                                    padding: '8px', 
                                                    borderRadius: '8px',
                                                    display: 'flex',
                                                    border: `1px solid ${d.extension === 'pdf' ? '#ff312d' : '#2d79ff'}22`
                                                }}>
                                                    {getFileIcon(d.extension)}
                                                </div>
                                                <div style={{ overflow: 'hidden' }}>
                                                    <Body1 block style={{ whiteSpace: 'nowrap', textOverflow: 'ellipsis', overflow: 'hidden', fontWeight: 500 }}>{d.filename}</Body1>
                                                    <Caption1 style={{ color: '#888', textTransform: 'uppercase' }}>{d.extension}</Caption1>
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell className={styles.tableCell}>
                                            <Dropdown
                                                size="small"
                                                style={{ width: '100%', minWidth: '180px' }}
                                                value={d.broker}
                                                placeholder={t('import.col_parser')}
                                                onOptionSelect={(_, data) => handleRuleChange(d.temp_file, Number(data.optionValue))}
                                            >
                                                {rules.map(r => (
                                                    <Option key={r.id} value={String(r.id)} text={r.rule_name}>{r.rule_name} ({r.broker_name})</Option>
                                                ))}
                                            </Dropdown>
                                        </TableCell>
                                        <TableCell className={styles.tableCell} style={{ textAlign: 'center' }}>
                                            <Badge appearance={d.asset_type === 'Neznámý' ? 'outline' : 'tint'} color="informative">
                                                {d.asset_type}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className={styles.tableCell} style={{ textAlign: 'right', paddingRight: '20px' }}>
                                            {d.tx_count > 0 ? (
                                                <Badge appearance="filled" color="success">
                                                    {t('import.tx_count').replace('{count}', String(d.tx_count))}
                                                </Badge>
                                            ) : (
                                                <Badge appearance="filled" color="danger">
                                                    {t('import.tx_count').replace('{count}', '0')}
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className={styles.tableCell}>
                                            <Button 
                                                icon={<Delete24Regular />} 
                                                appearance="subtle" 
                                                size="small" 
                                                onClick={() => setDiagnostics(prev => prev.filter(item => item.temp_file !== d.temp_file))}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {analyzing && (
                                    <TableRow>
                                        <TableCell colSpan={5}>
                                            <div style={{ padding: '24px', textAlign: 'center', backgroundColor: '#fdfdfd' }}>
                                                <ProgressBar />
                                                <Text italic size={200} style={{ marginTop: '8px', display: 'block' }}>{t('import.analyzing')}</Text>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                    {error && <Badge color="danger" style={{ marginTop: '16px' }} appearance="outline">{error}</Badge>}
                </Card>
            </div>
        );
    }

    // Step 3: Result Summary
    return (
        <div className={styles.container}>
            <Card className={styles.card}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '32px' }}>
                    <div>
                        <Title3 block>{t('import.step3_title')}</Title3>
                        <Caption1 block color="gray">{t('import.step3_desc')}</Caption1>
                    </div>
                    <Button icon={<ArrowSync24Regular />} appearance="primary" onClick={reset}>{t('import.btn_new_import')}</Button>
                </div>

                <div className={styles.resultSummary}>
                    <div style={{ flex: 1, backgroundColor: '#f6ffed', border: '1px solid #b7eb8f', padding: '16px', borderRadius: '12px' }}>
                        <Text block size={100} style={{ color: '#389e0d', textTransform: 'uppercase', letterSpacing: '0.05em' }}>{t('import.stats_inserted')}</Text>
                        <Subtitle1 block style={{ color: '#237804', fontSize: '24px' }}>{results.reduce((sum, r) => sum + r.inserted, 0)}</Subtitle1>
                    </div>
                    <div style={{ flex: 1, backgroundColor: '#f0faff', border: '1px solid #91d5ff', padding: '16px', borderRadius: '12px' }}>
                        <Text block size={100} style={{ color: '#096dd9', textTransform: 'uppercase', letterSpacing: '0.05em' }}>{t('import.stats_found')}</Text>
                        <Subtitle1 block style={{ color: '#0050b3', fontSize: '24px' }}>{results.reduce((sum, r) => sum + r.found, 0)}</Subtitle1>
                    </div>
                    <div style={{ flex: 1, backgroundColor: '#f5f5f5', border: '1px solid #d9d9d9', padding: '16px', borderRadius: '12px' }}>
                        <Text block size={100} style={{ color: '#595959', textTransform: 'uppercase', letterSpacing: '0.05em' }}>{t('import.stats_skipped')}</Text>
                        <Subtitle1 block style={{ color: '#262626', fontSize: '24px' }}>{results.reduce((sum, r) => sum + r.skipped, 0)}</Subtitle1>
                    </div>
                </div>

                <Table aria-label="Results summary">
                    <TableHeader>
                        <TableRow>
                            <TableHeaderCell>{t('import.col_file')}</TableHeaderCell>
                            <TableHeaderCell>{t('import.col_parser')}</TableHeaderCell>
                            <TableHeaderCell style={{ textAlign: 'right' }}>{t('import.col_status')}</TableHeaderCell>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {results.map((r, i) => (
                            <TableRow key={i}>
                                <TableCell className={styles.tableCell} style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                                    <CheckmarkCircle24Regular style={{ color: tokens.colorPaletteGreenForeground1 }} />
                                    <Text>{r.filename}</Text>
                                </TableCell>
                                <TableCell className={styles.tableCell}><Caption1>{r.parser}</Caption1></TableCell>
                                <TableCell className={styles.tableCell} style={{ textAlign: 'right' }}>
                                    <Badge color="success" appearance="tint">{t('common.done')}</Badge>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Card>
        </div>
    );
};

export default ImportPage;
