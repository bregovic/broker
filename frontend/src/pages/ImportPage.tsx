
import { useState, useEffect } from 'react';
import axios from 'axios';
import {
    makeStyles,
    tokens,
    Text,
    Button,
    ProgressBar,
    Card,
    Badge,
    Toolbar,
    Table,
    TableHeader,
    TableRow,
    TableHeaderCell,
    TableBody,
    TableCell,
    Dropdown,
    Option,
    ToolbarButton,
    ToolbarDivider
} from '@fluentui/react-components';
import {
    DocumentAdd24Regular,
    Dismiss24Regular,
    DocumentPdfRegular,
    DocumentRegular,
    CheckmarkCircle24Regular,
    ArrowSync24Regular,
    Play24Regular
} from '@fluentui/react-icons';
import { useTranslation } from '../context/TranslationContext';
import { PageLayout, PageContent, PageHeader } from '../components/PageLayout';

const useStyles = makeStyles({
    container: {
        display: 'flex',
        flexDirection: 'column',
        gap: '24px',
        maxWidth: '1000px',
        margin: '0 auto',
        animationDuration: '0.3s',
        animationName: {
            from: { opacity: 0, transform: 'translateY(10px)' },
            to: { opacity: 1, transform: 'translateY(0)' }
        }
    },
    dropZone: {
        border: `2px dashed ${tokens.colorNeutralStroke1}`,
        borderRadius: '12px',
        padding: '80px',
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        gap: '20px',
        cursor: 'pointer',
        transition: 'all 0.2s cubic-bezier(0.33, 1, 0.68, 1)',
        backgroundColor: tokens.colorNeutralBackground1,
        ':hover': {
            border: `2px dashed ${tokens.colorBrandBackground}`,
            backgroundColor: tokens.colorBrandBackgroundInverted,
            transform: 'scale(1.01)'
        }
    },
    dropZoneActive: {
        border: `2px solid ${tokens.colorBrandBackground}`,
        backgroundColor: tokens.colorBrandBackground2,
        transform: 'scale(1.02)'
    },
    card: {
        padding: '24px',
        display: 'flex',
        flexDirection: 'column',
        gap: '16px'
    },
    tableCell: {
        fontSize: '13px'
    }
});

interface DiagnosticItem {
    filename: string;
    extension: string;
    broker: string;
    parser: string;
    parser_class: string | null;
    tx_count: number;
    rule_id: number | null;
    asset_type: string;
    temp_file: string;
    success?: boolean;
}

interface Rule {
    id: number;
    config_name: string;
    broker_name: string;
}

const ImportPage = () => {
    const styles = useStyles();
    const { t } = useTranslation();
    
    const [step, setStep] = useState(1); // 1: Upload, 2: Diagnostic, 3: Results
    const [dragging, setDragging] = useState(false);
    const [diagnostics, setDiagnostics] = useState<DiagnosticItem[]>([]);
    const [rules, setRules] = useState<Rule[]>([]);
    const [analyzing, setAnalyzing] = useState(false);
    const [importing, setImporting] = useState(false);
    const [results, setResults] = useState<any[]>([]);

    useEffect(() => {
        axios.get('/api/v3/api-import.php?action=list_rules')
            .then(res => { if(res.data.success) setRules(res.data.rules); })
            .catch(err => console.error("Failed to load rules", err));
    }, []);

    const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.files && e.target.files.length > 0) {
            handleAnalyze(e.target.files);
        }
    };

    const handleAnalyze = async (fileList: FileList) => {
        setAnalyzing(true);
        setStep(2);
        
        const formData = new FormData();
        Array.from(fileList).forEach(f => formData.append('files[]', f));

        try {
            const res = await axios.post('/api/v3/api-import.php?action=analyze', formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            if (res.data.success) {
                setDiagnostics(res.data.data);
            }
        } catch (e: any) {
            alert('Chyba při analýze: ' + e.message);
            setStep(1);
        } finally {
            setAnalyzing(false);
        }
    };

    const handleRuleChange = (temp_file: string, ruleId: number) => {
        const rule = rules.find(r => r.id === ruleId);
        setDiagnostics(prev => prev.map(d => 
            d.temp_file === temp_file 
                ? { ...d, rule_id: ruleId, broker: rule?.broker_name || 'Manual' } 
                : d
        ));
    };

    const handleExecuteImport = async () => {
        setImporting(true);
        try {
            const items = diagnostics.map(d => ({
                temp_file: d.temp_file,
                rule_id: d.rule_id,
                filename: d.filename
            }));
            
            const res = await axios.post('/api/v3/api-import.php?action=import', { items });
            if (res.data.success) {
                setResults(res.data.summary);
                setStep(3);
            } else {
                alert('Chyba importu: ' + res.data.message);
            }
        } catch (e: any) {
            alert('Chyba: ' + e.message);
        } finally {
            setImporting(false);
        }
    };

    const reset = () => {
        setStep(1);
        setDiagnostics([]);
        setResults([]);
    };

    const getFileIcon = (ext: string) => {
        if (ext === 'pdf') return <DocumentPdfRegular style={{ fontSize: '24px', color: '#d13438' }} />;
        if (ext === 'csv') return <DocumentRegular style={{ fontSize: '24px', color: '#107c10' }} />;
        return <DocumentRegular style={{ fontSize: '24px' }} />;
    };

    if (step === 1) {
        return (
            <PageLayout>
                <PageHeader><Toolbar /></PageHeader>
                <PageContent>
                    <div className={styles.container}>
                        <div
                            className={`${styles.dropZone} ${dragging ? styles.dropZoneActive : ''}`}
                            onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
                            onDragLeave={() => setDragging(false)}
                            onDrop={(e) => { e.preventDefault(); setDragging(false); handleAnalyze(e.dataTransfer.files); }}
                            onClick={() => document.getElementById('fileInput')?.click()}
                        >
                            <input type="file" id="fileInput" style={{ display: 'none' }} onChange={handleFileSelect} multiple />
                            <DocumentAdd24Regular style={{ fontSize: '64px', color: tokens.colorBrandForeground1 }} />
                            <div style={{ textAlign: 'center' }}>
                                <Text size={600} weight="semibold" block>{t('import_title') || 'Nahrajte výpisy pro V3 Import'}</Text>
                                <Text size={300} style={{ color: tokens.colorNeutralForeground3 }}>Podporujeme Revolut (Stock, Crypto, Commodity), brzy další.</Text>
                            </div>
                        </div>
                    </div>
                </PageContent>
            </PageLayout>
        );
    }

    if (step === 2) {
        return (
            <PageLayout>
                <PageHeader>
                    <Toolbar>
                        <ToolbarButton icon={<DocumentAdd24Regular />} onClick={() => document.getElementById('fileInputMore')?.click()}>Přidat další</ToolbarButton>
                        <input type="file" id="fileInputMore" style={{ display: 'none' }} onChange={(e) => e.target.files && handleAnalyze(e.target.files)} multiple />
                        <ToolbarDivider />
                        <ToolbarButton appearance="primary" icon={<Play24Regular />} disabled={analyzing || importing} onClick={handleExecuteImport}>Zahájit import</ToolbarButton>
                        <ToolbarButton icon={<Dismiss24Regular />} onClick={reset}>Zrušit</ToolbarButton>
                    </Toolbar>
                </PageHeader>
                <PageContent>
                    <div className={styles.container}>
                        <Card className={styles.card}>
                            <Text size={500} weight="semibold">Diagnostika souborů</Text>
                            {analyzing ? <ProgressBar /> : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHeaderCell>Soubor</TableHeaderCell>
                                            <TableHeaderCell>Poskytovatel</TableHeaderCell>
                                            <TableHeaderCell>Typ</TableHeaderCell>
                                            <TableHeaderCell>Nalezeno</TableHeaderCell>
                                            <TableHeaderCell>Akce</TableHeaderCell>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {diagnostics.map((d, i) => (
                                            <TableRow key={i}>
                                                <TableCell className={styles.tableCell}>
                                                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                                                        {getFileIcon(d.extension)}
                                                        <Text>{d.filename}</Text>
                                                    </div>
                                                </TableCell>
                                                <TableCell className={styles.tableCell}>
                                                    <Dropdown
                                                        size="small"
                                                        value={d.broker}
                                                        placeholder="Vyberte parser"
                                                        onOptionSelect={(_, data) => handleRuleChange(d.temp_file, Number(data.optionValue))}
                                                    >
                                                        {rules.map(r => (
                                                            <Option key={r.id} value={r.id.toString()}>{r.broker_name}</Option>
                                                        ))}
                                                    </Dropdown>
                                                </TableCell>
                                                <TableCell className={styles.tableCell}><Badge appearance="outline">{d.asset_type}</Badge></TableCell>
                                                <TableCell className={styles.tableCell}>
                                                    <Badge color={d.tx_count > 0 ? "success" : "danger"}>{d.tx_count} transakcí</Badge>
                                                </TableCell>
                                                <TableCell className={styles.tableCell}>
                                                    <Button size="small" icon={<Dismiss24Regular />} appearance="subtle" onClick={() => setDiagnostics(p => p.filter((_, idx) => idx !== i))} />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </Card>
                        {importing && (
                            <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                                <ProgressBar />
                                <Text size={200} align="center">Importuji do PostgreSQL...</Text>
                            </div>
                        )}
                    </div>
                </PageContent>
            </PageLayout>
        );
    }

    // Step 3: Results
    return (
        <PageLayout>
            <PageHeader>
                <Toolbar>
                    <ToolbarButton icon={<ArrowSync24Regular />} onClick={reset}>Nový import</ToolbarButton>
                </Toolbar>
            </PageHeader>
            <PageContent>
                <div className={styles.container}>
                    <Card className={styles.card}>
                        <Text size={500} weight="semibold">Výsledek zpracování</Text>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHeaderCell>Soubor</TableHeaderCell>
                                    <TableHeaderCell>Nalezeno</TableHeaderCell>
                                    <TableHeaderCell>Uloženo</TableHeaderCell>
                                    <TableHeaderCell>Duplicity</TableHeaderCell>
                                    <TableHeaderCell>Stav</TableHeaderCell>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {results.map((r, i) => (
                                    <TableRow key={i}>
                                        <TableCell className={styles.tableCell}>{r.filename}</TableCell>
                                        <TableCell className={styles.tableCell}>{r.found}</TableCell>
                                        <TableCell className={styles.tableCell}><Text weight="bold" style={{ color: tokens.colorPaletteGreenForeground1 }}>{r.inserted}</Text></TableCell>
                                        <TableCell className={styles.tableCell}><Text style={{ color: tokens.colorNeutralForeground4 }}>{r.skipped}</Text></TableCell>
                                        <TableCell className={styles.tableCell}>
                                            <Badge color="success" icon={<CheckmarkCircle24Regular />}>OK</Badge>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                </div>
            </PageContent>
        </PageLayout>
    );
};

export default ImportPage;
