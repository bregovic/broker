
import { useState, useCallback, useRef, useEffect } from 'react';
import axios from 'axios';

import {
    makeStyles,
    tokens,
    Text,
    Button,
    ProgressBar,
    Card,
    Badge,
    Avatar,
    Toolbar
} from '@fluentui/react-components';
import {
    ArrowUpload24Regular,
    DocumentAdd24Regular,
    Dismiss24Regular,
    DocumentPdfRegular,
    DocumentRegular
} from '@fluentui/react-icons';
import { processImport } from '../utils/ImportProcessor';
import { useTranslation } from '../context/TranslationContext';
import { PageLayout, PageContent, PageHeader } from '../components/PageLayout';

const useStyles = makeStyles({
    container: {
        display: 'flex',
        flexDirection: 'column',
        gap: '24px',
        maxWidth: '800px',
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
        padding: '60px',
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
    fileCard: {
        padding: '24px',
        display: 'flex',
        flexDirection: 'row',
        alignItems: 'center',
        gap: '16px',
        position: 'relative'
    },
    logBox: {
        backgroundColor: tokens.colorNeutralBackgroundStatic,
        color: tokens.colorNeutralForegroundStaticInverted,
        padding: '16px',
        borderRadius: '8px',
        fontFamily: 'Consolas, monospace',
        minHeight: '100px',
        maxHeight: '150px',
        whiteSpace: 'pre-wrap',
        overflow: 'auto',
        fontSize: '13px',
        lineHeight: '1.5',
        marginTop: '40px'
    }
});

const ImportPage = () => {
    const styles = useStyles();
    const { t } = useTranslation();
    const [dragging, setDragging] = useState(false);
    const [files, setFiles] = useState<{ file: File, broker: string }[]>([]);
    const [uploading, setUploading] = useState(false);
    const [logs, setLogs] = useState<string[]>([]);
    const logContainerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (logContainerRef.current) {
            logContainerRef.current.scrollTop = logContainerRef.current.scrollHeight;
        }
    }, [logs]);

    const detectBroker = async (file: File): Promise<string> => {
        // 1. Check filename
        const name = file.name.toLowerCase();
        if (name.includes('revolut')) return 'Revolut';
        if (name.includes('fio') || name.includes('fio_')) return 'Fio banka';
        if (name.includes('trading212') || name.includes('trading 212')) return 'Trading 212';
        if (name.includes('coinbase')) return 'Coinbase';
        if (name.includes('ibkr') || (name.includes('activity') && name.includes('statement'))) return 'IBKR';
        if (name.endsWith('.xlsx')) return 'eToro (Excel)';

        // 2. Check content (fallback for CSVs mostly)
        try {
            const text = await file.slice(0, 4096).text();
            if (text.includes('Fio banka') || text.includes('Id transakce')) return 'Fio banka';
            if (text.includes('Trading 212') || text.includes('Action,Time,ISIN')) return 'Trading 212';
            if (text.includes('Revolut') || text.includes('Type,Product,Started Date')) return 'Revolut';
            if (text.includes('Coinbase') || text.includes('Transaction History')) return 'Coinbase';
            if (text.includes('Interactive Brokers')) return 'IBKR';
        } catch (e) {
            console.error("Content check failed", e);
        }

        return 'Neznámý broker';
    };

    const handleDrop = useCallback(async (e: React.DragEvent) => {
        e.preventDefault();
        setDragging(false);
        if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
            processFiles(e.dataTransfer.files);
        }
    }, []);

    const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.files && e.target.files.length > 0) {
            processFiles(e.target.files);
        }
    };

    const processFiles = async (fileList: FileList) => {
        const newFiles = Array.from(fileList);
        for (const f of newFiles) {
            setLogs(p => [...p, `Analyzuji soubor: ${f.name}...`]);
            const detected = await detectBroker(f);
            setFiles(prev => [...prev, { file: f, broker: detected }]);
            const symbol = (detected === 'Neznámý broker') ? '❌' : '✅';
            setLogs(p => [...p, `${symbol} Detekován (${f.name}): ${detected}`]);
        }
    };

    const handleRemoveFile = (index: number) => {
        setFiles(prev => prev.filter((_, i) => i !== index));
    };

    const handleUploadV3 = async () => {
        if (files.length === 0) return;
        setUploading(true);
        setLogs(p => [...p, '>>> ZAHAJUJI V3 IMPORT (PostgreSQL) <<<']);

        try {
            const currentFiles = [...files];
            for (const item of currentFiles) {
                const { file } = item;
                setLogs(p => [...p, `--- Posílám surový soubor do V3: ${file.name} ---`]);
                
                const formData = new FormData();
                formData.append('file', file);

                try {
                    // Voláme nový V3 endpoint pod /api/ prefixem
                    const res = await axios.post('/api/v3/api-import.php', formData, {
                        headers: { 'Content-Type': 'multipart/form-data' }
                    });


                    if (res.data.success) {
                        setLogs(p => [...p, `✅ V3 Úspěch: ${res.data.message}`]);
                        setFiles(prev => prev.filter(f => f.file !== file));
                    } else {
                        throw new Error(res.data.message || 'Server error');
                    }
                } catch (err: any) {
                    setLogs(p => [...p, `❌ V3 CHYBA (${file.name}): ${err.message}`]);
                }
            }
        } catch (e: any) {
            setLogs(p => [...p, `❌ CRITICAL ERROR: ${e.message}`]);
        } finally {
            setUploading(false);
        }
    };

    const handleUpload = async () => {
        if (files.length === 0) return;
        setUploading(true);
        setLogs(p => [...p, '>>> ZAHAJUJI LEGACY IMPORT (Wedos) <<<']);

        try {
            const currentFiles = [...files];
            for (const item of currentFiles) {
                const { file } = item;
                setLogs(p => [...p, `--- Importuji (JS): ${file.name} ---`]);
                try {
                    await processImport(file, (msg: string) => setLogs(p => [...p, msg]));
                    setLogs(p => [...p, `✅ Hotovo: ${file.name}`]);
                    setFiles(prev => prev.filter(f => f.file !== file));
                } catch (err: any) {
                    setLogs(p => [...p, `❌ CHYBA (${file.name}): ${err.message}`]);
                }
            }
        } catch (e: any) {
            setLogs(p => [...p, `❌ CRITICAL ERROR: ${e.message}`]);
        } finally {
            setUploading(false);
        }
    };



    const getFileIcon = (fileName: string) => {
        if (fileName.endsWith('.pdf')) return <DocumentPdfRegular style={{ fontSize: '32px', color: '#d13438' }} />;
        if (fileName.endsWith('.csv')) return <DocumentRegular style={{ fontSize: '32px', color: '#107c10' }} />;
        return <DocumentRegular style={{ fontSize: '32px' }} />;
    };

    return (
        <PageLayout>
            <PageHeader>
                <Toolbar />
            </PageHeader>
            <PageContent>
                <div className={styles.container}>
                    <div>



                    </div>

                    {files.length === 0 && (
                        <div
                            className={`${styles.dropZone} ${dragging ? styles.dropZoneActive : ''}`}
                            onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
                            onDragLeave={() => setDragging(false)}
                            onDrop={handleDrop}
                            onClick={() => document.getElementById('fileInput')?.click()}
                        >
                            <input
                                type="file"
                                id="fileInput"
                                style={{ display: 'none' }}
                                onChange={handleFileSelect}
                                multiple
                            />

                            <DocumentAdd24Regular style={{ fontSize: '64px', color: tokens.colorBrandForeground1 }} />
                            <div style={{ textAlign: 'center' }}>
                                <Text size={500} weight="semibold" block>{t('import.drop_title')}</Text>
                                <Text size={300} style={{ color: tokens.colorNeutralForeground3 }}>{t('import.supported')}</Text>
                            </div>
                        </div>
                    )}

                    {files.length > 0 && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
                            {files.map((item, index) => (
                                <Card key={index} className={styles.fileCard}>
                                    <Avatar
                                        icon={getFileIcon(item.file.name)}
                                        color="colorful"
                                        active="active"
                                        size={48}
                                    />
                                    <div style={{ flexGrow: 1, display: 'flex', flexDirection: 'column' }}>
                                        <Text weight="bold" size={400}>{item.file.name}</Text>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginTop: '4px' }}>
                                            <Badge appearance={item.broker && item.broker !== 'Neznámý broker' ? "filled" : "outline"} color={item.broker && item.broker !== 'Neznámý broker' ? "brand" : "danger"}>
                                                {item.broker || 'Neznámý'}
                                            </Badge>
                                            <Text size={200} style={{ color: tokens.colorNeutralForeground3 }}>{(item.file.size / 1024).toFixed(1)} KB</Text>
                                        </div>
                                    </div>
                                    {!uploading && (
                                        <Button icon={<Dismiss24Regular />} appearance="subtle" onClick={() => handleRemoveFile(index)} aria-label="Zrušit" />
                                    )}
                                </Card>
                            ))}

                            <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: '20px' }}>
                                <Button appearance="secondary" onClick={() => document.getElementById('fileInputAdd')?.click()}>
                                    {t('import.add_btn')}
                                </Button>
                                <input
                                    type="file"
                                    id="fileInputAdd"
                                    style={{ display: 'none' }}
                                    onChange={handleFileSelect}
                                    multiple
                                />

                                <div style={{ display: 'flex', gap: '10px' }}>
                                    <Button
                                        appearance="outline"
                                        size="large"
                                        onClick={handleUpload}
                                        disabled={uploading}
                                    >
                                        Legacy Web
                                    </Button>
                                    <Button
                                        appearance="primary"
                                        size="large"
                                        icon={<ArrowUpload24Regular />}
                                        onClick={handleUploadV3}
                                        disabled={uploading}
                                        style={{ minWidth: '180px' }}
                                    >
                                        {uploading ? t('import.working') : `Import to V3 (Postgres)`}
                                    </Button>
                                </div>
                            </div>

                        </div>
                    )}

                    {(logs.length > 0) && (
                        <Card className={styles.logBox} ref={logContainerRef}>
                            {logs.map((L, i) => <div key={i}>{L}</div>)}
                        </Card>
                    )}

                    {uploading && <ProgressBar />}
                </div>
            </PageContent>
        </PageLayout>
    );
};

export default ImportPage;
