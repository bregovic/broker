import {
    Dialog,
    DialogSurface,
    DialogTitle,
    DialogBody,
    DialogActions,
    DialogContent,
    DialogTrigger,
    Button,
    makeStyles,
    Dropdown,
    Option,
    Label,
    TabList,
    Tab,
    tokens,
    Badge,
    Spinner,
    Text,
    Input,
    Card,
    shorthands
} from "@fluentui/react-components";
import {
    Attach24Regular,
    Dismiss16Regular,
    Screenshot24Regular
} from "@fluentui/react-icons";
import { useState, useEffect } from "react";
import axios from "axios";
import { VisualEditor } from "./VisualEditor";

const useStyles = makeStyles({
    textarea: {
        width: '100%',
        minHeight: '100px',
        marginTop: '10px'
    },
    formRow: {
        display: 'flex',
        flexDirection: 'column',
        gap: '5px',
        marginBottom: '15px'
    },
    attachmentList: {
        display: 'flex',
        flexDirection: 'column',
        gap: '8px',
        marginTop: '8px'
    },
    attachmentItem: {
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        padding: '8px',
        backgroundColor: tokens.colorNeutralBackground2,
        borderRadius: '4px',
        border: `1px solid ${tokens.colorNeutralStroke1}`
    },
    dropZone: {
        ...shorthands.border('2px', 'dashed', tokens.colorNeutralStroke1),
        ...shorthands.borderRadius(tokens.borderRadiusMedium),
        ...shorthands.padding('20px'),
        textAlign: 'center',
        backgroundColor: tokens.colorNeutralBackground2,
        cursor: 'pointer',
        transition: 'all 0.2s ease',
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: '8px',
        ':hover': {
            ...shorthands.borderColor(tokens.colorBrandStroke1),
            backgroundColor: tokens.colorNeutralBackground1
        }
    },
    dropZoneActive: {
        ...shorthands.borderColor(tokens.colorBrandStroke1),
        backgroundColor: tokens.colorBrandBackground2,
        transform: 'scale(1.01)'
    }
});

interface FeedbackModalProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    user: { name: string, id: number, role: string } | null;
}

// We ignore 'user' prop as we don't need role check anymore here (handled in Layout)
// keeping it in interface for compatibility but destructuring delicately to avoid unused vars
export const FeedbackModal = ({ open, onOpenChange }: Omit<FeedbackModalProps, 'user'> & { user?: any }) => {
    const styles = useStyles();
    const [tab, setTab] = useState<'report' | 'history'>('report');

    // Form State
    const [subject, setSubject] = useState("");
    const [description, setDescription] = useState("");
    const [priority, setPriority] = useState("medium");
    const [files, setFiles] = useState<File[]>([]);
    const [sending, setSending] = useState(false);
    const [isDragging, setIsDragging] = useState(false);

    // History State
    interface HistoryItem {
        id: number;
        date: string;
        title: string;
        description: string;
        category: 'feature' | 'bugfix' | 'improvement' | 'refactor' | 'deployment';
        task_id: number | null;
    }
    interface HistoryMonth {
        month: string;
        items: HistoryItem[];
    }
    const [historyData, setHistoryData] = useState<HistoryMonth[]>([]);
    const [loadingHistory, setLoadingHistory] = useState(false);
    const [expandedItems, setExpandedItems] = useState<Set<number>>(new Set());

    // Helper for API ID
    const isDev = import.meta.env.DEV;
    const getApiUrl = (endpoint: string) => isDev
        ? `http://localhost/Webhry/hollyhop/broker/broker 2.0/${endpoint}`
        : `/investyx/${endpoint}`;

    useEffect(() => {
        if (open && tab === 'history') {
            loadHistory();
        }
    }, [open, tab]);

    const loadHistory = async () => {
        setLoadingHistory(true);
        try {
            const res = await axios.get(getApiUrl('api-dev-history.php?action=list'));
            if (res.data.success) {
                setHistoryData(res.data.data);
            }
        } catch (e) {
            console.error(e);
        } finally {
            setLoadingHistory(false);
        }
    };

    const handlePaste = (e: React.ClipboardEvent) => {
        // We now use VisualEditor which handles its own pasting for description.
        // If the user pastes while NOT in the editor, we can still catch it here if we want 
        // to add it as a general attachment (legacy behavior).
        // But since VisualEditor will be the primary place for description, we'll keep this 
        // as a fallback for the rest of the modal.

        const items = e.clipboardData.items;

        for (let i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image') !== -1) {
                const blob = items[i].getAsFile();
                if (blob) {
                    const file = new File([blob], `screenshot_${new Date().getTime()}.png`, { type: blob.type });
                    setFiles(prev => [...prev, file]);
                }
            }
        }
    };

    const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.files) {
            const newFiles = Array.from(e.target.files);
            setFiles(prev => [...prev, ...newFiles]);
        }
        e.target.value = '';
    };

    const handleDragOver = (e: React.DragEvent) => {
        e.preventDefault();
        setIsDragging(true);
    };

    const handleDragLeave = () => {
        setIsDragging(false);
    };

    const handleDrop = (e: React.DragEvent) => {
        e.preventDefault();
        setIsDragging(false);
        if (e.dataTransfer.files) {
            const newFiles = Array.from(e.dataTransfer.files);
            setFiles(prev => [...prev, ...newFiles]);
        }
    };

    const handleRemoveFile = (index: number) => {
        setFiles(prev => prev.filter((_, i) => i !== index));
    };

    const handleSubmit = async () => {
        if (!subject.trim()) {
            alert("Vyplňte předmět.");
            return;
        }
        if (!description.trim()) {
            alert("Vyplňte popis.");
            return;
        }
        setSending(true);
        try {
            const formData = new FormData();
            formData.append('subject', subject);
            formData.append('description', description);
            formData.append('priority', priority);

            files.forEach((file) => {
                formData.append('attachments[]', file);
            });

            const res = await axios.post(getApiUrl('api-changerequests.php?action=create'), formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });

            if (res.data.success) {
                setSubject("");
                setDescription("");
                setFiles([]);
                setPriority("medium");
                alert("Požadavek odeslán!");
                onOpenChange(false);
            } else {
                alert("Chyba: " + (res.data.error || 'Neznámá chyba'));
            }
        } catch (e: any) {
            alert("Chyba spojení: " + e.message);
        } finally {
            setSending(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(_e, data) => onOpenChange(data.open)}>
            <DialogSurface style={{ maxWidth: '800px', width: '95%', height: 'auto', maxHeight: '90vh', display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
                <DialogBody style={{ flex: 1, display: 'flex', flexDirection: 'column', overflow: 'hidden', minHeight: 0 }}>
                    <DialogTitle>
                        <TabList selectedValue={tab} onTabSelect={(_, d) => setTab(d.value as any)}>
                            <Tab value="report">Nahlásit chybu / Zpětná vazba</Tab>
                            <Tab value="history">Historie vývoje</Tab>
                        </TabList>
                    </DialogTitle>

                    <DialogContent style={{ flex: 1, overflowY: 'auto', paddingTop: '10px' }}>
                        {tab === 'report' ? (
                            <div onPaste={handlePaste} style={{ minHeight: '100%' }}>
                                <p style={{ marginBottom: '15px' }}>
                                    Našli jste chybu nebo máte nápad? <br />
                                    <Text size={200} style={{ color: tokens.colorNeutralForeground3 }}>
                                        (Tip: Screenshoty můžete vložit přímo pomocí Ctrl+V)
                                    </Text>
                                </p>

                                <div className={styles.formRow}>
                                    <Label required>Předmět</Label>
                                    <Input
                                        value={subject}
                                        onChange={(_e, d) => setSubject(d.value)}
                                        placeholder="Krátký název problému"
                                    />
                                </div>
                                <div className={styles.formRow}>
                                    <Label required>Popis</Label>
                                    <VisualEditor
                                        initialContent={description}
                                        onChange={setDescription}
                                        getApiUrl={getApiUrl}
                                        placeholder="Popište problém co nejpřesněji, můžete vkládat i obrázky (Ctrl+V)..."
                                    />
                                </div>
                                <div className={styles.formRow}>
                                    <Label>Priorita</Label>
                                    <Dropdown
                                        value={priority.charAt(0).toUpperCase() + priority.slice(1)}
                                        selectedOptions={[priority]}
                                        onOptionSelect={(_, d) => setPriority(d.optionValue || 'medium')}
                                    >
                                        <Option value="low">Low</Option>
                                        <Option value="medium">Medium</Option>
                                        <Option value="high">High</Option>
                                    </Dropdown>
                                </div>

                                <div className={styles.formRow}>
                                    <Label>Přílohy</Label>
                                    <div
                                        className={`${styles.dropZone} ${isDragging ? styles.dropZoneActive : ''}`}
                                        onDragOver={handleDragOver}
                                        onDragLeave={handleDragLeave}
                                        onDrop={handleDrop}
                                        onClick={() => document.getElementById('report-file-input')?.click()}
                                    >
                                        <Attach24Regular style={{ fontSize: '32px', color: tokens.colorBrandForeground1 }} />
                                        <div>
                                            <Text weight="semibold">Klikněte pro výběr nebo přetáhněte soubory sem</Text>
                                            <br />
                                            <Text size={200} style={{ color: tokens.colorNeutralForeground3 }}>
                                                Podpora obrázků, logů, PDF, ZIP a dalších...
                                            </Text>
                                        </div>
                                        <input
                                            id="report-file-input"
                                            type="file"
                                            multiple
                                            onChange={handleFileSelect}
                                            style={{ display: 'none' }}
                                        />
                                    </div>
                                </div>
                                {files.length > 0 && (
                                    <div className={styles.attachmentList}>
                                        {files.map((file, idx) => (
                                            <div key={idx} className={styles.attachmentItem}>
                                                <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                                                    {file.name.includes('screenshot') ? <Screenshot24Regular /> : <Attach24Regular />}
                                                    <Text>{file.name}</Text>
                                                    <Text size={200} style={{ color: tokens.colorNeutralForeground3 }}>
                                                        ({(file.size / 1024).toFixed(1)} KB)
                                                    </Text>
                                                </div>
                                                <Button
                                                    icon={<Dismiss16Regular />}
                                                    appearance="subtle"
                                                    size="small"
                                                    onClick={() => handleRemoveFile(idx)}
                                                />
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        ) : tab === 'history' ? (
                            // History View
                            <div style={{ flex: 1 }}>
                                {loadingHistory ? <Spinner label="Načítám historii..." /> : (
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>
                                        {historyData.map(month => (
                                            <div key={month.month}>
                                                <Text weight="bold" size={500} style={{ marginBottom: '10px', display: 'block', borderBottom: `2px solid ${tokens.colorBrandBackground}`, paddingBottom: '5px' }}>
                                                    📅 {month.month}
                                                </Text>
                                                <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', marginLeft: '10px' }}>
                                                    {month.items.map(item => (
                                                        <Card key={item.id} style={{ padding: '10px', cursor: 'pointer' }} onClick={() => {
                                                            setExpandedItems(prev => {
                                                                const next = new Set(prev);
                                                                if (next.has(item.id)) next.delete(item.id);
                                                                else next.add(item.id);
                                                                return next;
                                                            });
                                                        }}>
                                                            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                                                                <Text size={200} style={{ color: tokens.colorNeutralForeground3, minWidth: '70px' }}>
                                                                    {new Date(item.date).toLocaleDateString('cs-CZ')}
                                                                </Text>
                                                                <Badge
                                                                    appearance="filled"
                                                                    color={item.category === 'feature' ? 'success' :
                                                                        item.category === 'bugfix' ? 'danger' :
                                                                            item.category === 'improvement' ? 'informative' :
                                                                                item.category === 'refactor' ? 'warning' : 'brand'}
                                                                    style={{ minWidth: '85px', textAlign: 'center' }}
                                                                >
                                                                    {item.category === 'feature' ? '✨ Feature' :
                                                                        item.category === 'bugfix' ? '🐛 Bugfix' :
                                                                            item.category === 'improvement' ? '📈 Improve' :
                                                                                item.category === 'refactor' ? '🔧 Refactor' : '🚀 Deploy'}
                                                                </Badge>
                                                                <Text weight="semibold" style={{ flex: 1 }}>{item.title}</Text>
                                                                {item.task_id && <Badge appearance="outline" size="small">Task #{item.task_id}</Badge>}
                                                            </div>
                                                            {expandedItems.has(item.id) && item.description && (
                                                                <Text size={200} style={{ marginTop: '8px', color: tokens.colorNeutralForeground2, display: 'block', marginLeft: '80px' }}>
                                                                    {item.description}
                                                                </Text>
                                                            )}
                                                        </Card>
                                                    ))}
                                                </div>
                                            </div>
                                        ))}
                                        {historyData.length === 0 && <Text>Zatím žádná historie.</Text>}
                                    </div>
                                )}
                            </div>
                        ) : null}
                    </DialogContent>

                    <DialogActions>
                        {tab === 'report' && (
                            <Button appearance="primary" onClick={handleSubmit} disabled={sending}>
                                {sending ? "Odesílám..." : "Odeslat"}
                            </Button>
                        )}
                        <DialogTrigger disableButtonEnhancement>
                            <Button appearance="secondary">Zavřít</Button>
                        </DialogTrigger>
                    </DialogActions>
                </DialogBody>
            </DialogSurface>
        </Dialog >
    );
};
