import {
    makeStyles,
    tokens,
    Button,
    Dropdown,
    Option,
    Label,
    Spinner,
    Text,
    Checkbox,
    Avatar,
    Dialog,
    DialogSurface,
    shorthands,
    Switch,
    Popover,
    PopoverTrigger,
    PopoverSurface,
    Badge
} from "@fluentui/react-components";
import { Filter24Regular, ArrowUp24Regular, ArrowDown24Regular, Subtract24Regular } from "@fluentui/react-icons";
import { useState, useEffect } from "react";
import { useSearchParams } from 'react-router-dom';
import axios from "axios";
import { SmartDataGrid } from "../components/SmartDataGrid";
import { PageLayout, PageContent, PageHeader } from "../components/PageLayout";
import { VisualEditor } from "../components/VisualEditor";
import {
    Attach24Regular,
    Delete20Regular,
    Document24Regular,
    History24Regular,
    Comment24Regular,
    Send24Regular,
    Edit24Regular,
    Save24Regular,
    Dismiss24Regular,
    Emoji20Regular,
    ArrowLeft24Regular
} from "@fluentui/react-icons";
import {
    Menu,
    MenuTrigger,
    MenuPopover,
    MenuList,
    MenuItem
} from "@fluentui/react-components";

const useStyles = makeStyles({
    root: {
        display: 'flex',
        flexDirection: 'column',
        gap: '24px',
        height: '100%'
    },
    detailHeader: {
        display: 'flex',
        justifyContent: 'space-between',
        alignItems: 'center',
        gap: '20px',
        paddingBottom: '16px',
        borderBottom: `1px solid ${tokens.colorNeutralStroke2}`
    },
    detailTitle: {
        fontSize: '24px',
        lineHeight: '32px',
        fontWeight: '600',
        color: tokens.colorNeutralForeground1
    },
    detailId: {
        fontSize: '24px',
        lineHeight: '32px',
        fontWeight: '400',
        color: tokens.colorNeutralForeground3,
        marginRight: '12px'
    },
    detailLayout: {
        display: 'grid',
        gridTemplateColumns: 'minmax(0, 3fr) 1fr',
        gap: '24px',
        height: '100%',
        overflow: 'hidden',
        '@media (max-width: 1000px)': {
            gridTemplateColumns: '1fr',
            overflow: 'auto'
        }
    },
    mainColumn: {
        display: 'flex',
        flexDirection: 'column',
        gap: '24px',
        overflowY: 'auto',
        paddingRight: '12px'
    },
    sideColumn: {
        display: 'flex',
        flexDirection: 'column',
        gap: '24px',
        overflowY: 'auto'
    },
    card: {
        padding: '20px',
        boxShadow: tokens.shadow4,
        borderRadius: tokens.borderRadiusMedium,
        backgroundColor: tokens.colorNeutralBackground1,
        border: `1px solid ${tokens.colorNeutralStroke2}`
    },
    sectionHeader: {
        display: 'flex',
        justifyContent: 'space-between',
        alignItems: 'center',
        marginBottom: '16px'
    },
    sectionTitle: {
        fontSize: '16px',
        fontWeight: '600',
        color: tokens.colorNeutralForeground1,
        display: 'flex',
        alignItems: 'center',
        gap: '8px'
    },
    visualContent: {
        lineHeight: '1.8',
        fontSize: '16px',
        fontFamily: 'Segoe UI, sans-serif',
        color: tokens.colorNeutralForeground1,
        '& img': {
            maxWidth: '100%',
            height: 'auto',
            borderRadius: '4px',
            marginTop: '8px',
            marginBottom: '8px',
            border: `1px solid ${tokens.colorNeutralStroke1}`,
            cursor: 'pointer',
            transition: 'outline 0.1s',
            '&.selected': {
                outline: `2px solid ${tokens.colorBrandBackground}`,
                outlineOffset: '2px'
            },
            ':hover': {
                opacity: 0.95
            }
        }
    },
    editorArea: {
        minHeight: '150px',
        padding: '12px',
        border: `1px solid ${tokens.colorNeutralStroke1}`,
        borderRadius: tokens.borderRadiusMedium,
        backgroundColor: tokens.colorNeutralBackground1,
        outline: 'none',
        ':focus': {
            outline: `2px solid ${tokens.colorBrandStroke1}`
        },
        overflowY: 'auto'
    },
    commentBox: {
        display: 'flex',
        flexDirection: 'column',
        gap: '16px',
        padding: '20px',
        backgroundColor: tokens.colorNeutralBackground1,
        border: `1px solid ${tokens.colorNeutralStroke2}`,
        borderRadius: tokens.borderRadiusMedium,
        boxShadow: tokens.shadow2
    },
    commentItem: {
        display: 'flex',
        gap: '16px',
        padding: '16px',
        borderBottom: `1px solid ${tokens.colorNeutralStroke2}`,
        ':last-child': {
            borderBottom: 'none'
        }
    },
    filterBar: {
        display: 'flex',
        gap: '16px',
        flexWrap: 'wrap',
        alignItems: 'center',
        padding: '16px',
        backgroundColor: tokens.colorNeutralBackground1,
        borderRadius: tokens.borderRadiusMedium,
        boxShadow: tokens.shadow2,
        border: `1px solid ${tokens.colorNeutralStroke2}`
    },
    imgToolbar: {
        display: 'flex',
        gap: '4px',
        backgroundColor: tokens.colorNeutralBackground1,
        border: `1px solid ${tokens.colorNeutralStroke1}`,
        borderRadius: '4px',
        padding: '4px',
        boxShadow: tokens.shadow8,
        position: 'absolute',
        zIndex: 100
    },
    dropZone: {
        ...shorthands.border('2px', 'dashed', tokens.colorNeutralStroke1),
        ...shorthands.borderRadius(tokens.borderRadiusMedium),
        ...shorthands.padding('16px'),
        textAlign: 'center',
        backgroundColor: tokens.colorNeutralBackground2,
        cursor: 'pointer',
        transition: 'all 0.2s ease',
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: '4px',
        ':hover': {
            ...shorthands.borderColor(tokens.colorBrandStroke1),
            backgroundColor: tokens.colorNeutralBackground1
        }
    },
    dropZoneActive: {
        ...shorthands.borderColor(tokens.colorBrandStroke1),
        backgroundColor: tokens.colorBrandBackground2,
    }
});

interface RequestItem {
    id: number;
    subject: string;
    description: string;
    priority: 'low' | 'medium' | 'high';
    status: string;
    created_at: string;
    username: string;
    admin_notes?: string;
    attachment_path?: string;
    assigned_to?: number;
    assigned_username?: string;
    attachments?: { request_id: number; file_path: string; filename: string; created_at: string }[];
}

interface UserItem {
    id: number;
    username: string;
}

interface AttachmentItem {
    id: number;
    request_id: number;
    file_path: string;
    filename: string;
    filesize: number;
    created_at: string;
}

interface CommentItem {
    id: number;
    request_id: number;
    user_id: number;
    username: string;
    comment: string;
    created_at: string;
    attachments: string[];
    reactions: Record<string, number[]>;
    user_reactions: string[];
}

interface AuditLogItem {
    id: number;
    request_id: number;
    username: string;
    change_type: string;
    old_value: string;
    new_value: string;
    created_at: string;
}

// Convert legacy markdown-style images to HTML for the editor
const mdToHtml = (text: string, baseUrlBuilder: (p: string) => string) => {
    if (!text) return "";
    // Replace ![alt](url) with <img>
    return text.replace(/!\[(.*?)\]\((.*?)\)/g, (_, alt, url) => {
        const fullUrl = url.startsWith('http') ? url : baseUrlBuilder(url);
        return `<img src="${fullUrl}" alt="${alt}" style="max-width: 100%; display: block;" />`;
    }).replace(/\n/g, '<br>');
};


const RequestsPage = () => {
    const styles = useStyles();

    // Manage State
    const [requests, setRequests] = useState<RequestItem[]>([]);
    const [loadingRequests, setLoadingRequests] = useState(false);
    const [selectedRequest, setSelectedRequest] = useState<RequestItem | null>(null);
    const [users, setUsers] = useState<UserItem[]>([]);
    const [attachments, setAttachments] = useState<AttachmentItem[]>([]);
    const [uploadingAttachment, setUploadingAttachment] = useState(false);
    const [isDragging, setIsDragging] = useState(false);

    const [isEditingDescription, setIsEditingDescription] = useState(false);
    const [descriptionEditValue, setDescriptionEditValue] = useState('');
    const [isEditingSubject, setIsEditingSubject] = useState(false);
    const [subjectEditValue, setSubjectEditValue] = useState('');

    // Filter
    const allStatuses = ['New', 'New feature', 'Analysis', 'Development', 'Back to development', 'Testing', 'Testing AI', 'Done', 'Duplicity', 'Canceled'];
    // Exclude: 'Back to development', 'New feature', 'Testing AI' (as per user request)
    const defaultActiveStatuses = ['New', 'Analysis', 'Development', 'Testing'];
    const [selectedStatuses, setSelectedStatuses] = useState<string[]>(defaultActiveStatuses);

    // Comments
    const [comments, setComments] = useState<CommentItem[]>([]);
    const [loadingComments, setLoadingComments] = useState(false);
    const [auditLog, setAuditLog] = useState<AuditLogItem[]>([]);
    const [newComment, setNewComment] = useState('');
    const [sendingComment, setSendingComment] = useState(false);

    // Filter by Mine
    const [searchParams, setSearchParams] = useSearchParams();
    const [showOnlyMine, setShowOnlyMine] = useState(searchParams.get('mine') === '1');

    // Lightbox
    const [lightboxImage, setLightboxImage] = useState<string | null>(null);

    // API Helper
    const isDev = import.meta.env.DEV;
    const getApiUrl = (endpoint: string) => isDev
        ? `http://localhost/Webhry/hollyhop/broker/broker 2.0/${endpoint}`
        : `/investyx/${endpoint}`;

    useEffect(() => {
        loadRequests();
        loadUsers();

        // Listen for reset events from menu
        const handleReset = () => {
            setSelectedRequest(null);
            const mine = new URLSearchParams(window.location.search).get('mine') === '1';
            setShowOnlyMine(mine);
        };
        window.addEventListener('reset-requests-page', handleReset);
        return () => window.removeEventListener('reset-requests-page', handleReset);
    }, [showOnlyMine]);

    useEffect(() => {
        const mine = searchParams.get('mine') === '1';
        if (mine !== showOnlyMine) setShowOnlyMine(mine);
    }, [searchParams]);

    useEffect(() => {
        if (selectedRequest) {
            loadComments(selectedRequest.id);
            loadAuditLog(selectedRequest.id);
            loadAttachments(selectedRequest.id);
            setIsEditingDescription(false);
            setIsEditingSubject(false);
        } else {
            setComments([]);
            setAuditLog([]);
            setAttachments([]);
            setNewComment('');
        }
    }, [selectedRequest]);

    const loadRequests = async () => {
        setLoadingRequests(true);
        try {
            const url = showOnlyMine
                ? getApiUrl('api-changerequests.php?action=list')
                : getApiUrl('api-changerequests.php?action=list&view=all');
            const res = await axios.get(url);
            if (res.data.success) setRequests(res.data.data);
        } catch (e) { console.error(e); } finally { setLoadingRequests(false); }
    };

    const loadUsers = async () => {
        try {
            const res = await axios.get(getApiUrl('api-changerequests.php?action=list_users'));
            if (res.data.success) setUsers(res.data.data);
        } catch (e) { console.error(e); }
    };

    const loadComments = async (requestId: number) => {
        setLoadingComments(true);
        try {
            const res = await axios.get(getApiUrl(`api-comments.php?action=list&request_id=${requestId}`));
            if (res.data.success) setComments(res.data.data);
        } catch (e) { console.error(e); } finally { setLoadingComments(false); }
    };

    const loadAuditLog = async (requestId: number) => {
        try {
            const res = await axios.get(getApiUrl(`api-changerequests.php?action=get_history&request_id=${requestId}`));
            if (res.data.success) setAuditLog(res.data.data);
        } catch (e) { console.error(e); }
    };

    const handleUpdateStatus = async (id: number, status: string) => {
        try {
            await axios.post(getApiUrl('api-changerequests.php?action=update'), { id, status });
            updateLocalRequest(id, { status });
            loadAuditLog(id);
        } catch (e) { alert('Update failed'); }
    };

    const handleUpdateAssignee = async (id: number, userId: number) => {
        try {
            const res = await axios.post(getApiUrl('api-changerequests.php?action=update'), { id, assigned_to: userId });
            const updatedName = res.data.assigned_username;
            updateLocalRequest(id, {
                assigned_to: userId,
                assigned_username: userId === 0 ? undefined : (updatedName || users.find(u => u.id === userId)?.username)
            });
            loadAuditLog(id);
        } catch (e) { console.error(e); }
    };

    const handleUpdatePriority = async (id: number, priority: string) => {
        try {
            await axios.post(getApiUrl('api-changerequests.php?action=update_priority'), { id, priority });
            updateLocalRequest(id, { priority: priority as any });
            loadAuditLog(id);
        } catch (e) { console.error(e); }
    };

    const loadAttachments = async (requestId: number) => {
        try {
            const res = await axios.get(getApiUrl(`api-changerequests.php?action=list_attachments&request_id=${requestId}`));
            if (res.data.success) setAttachments(res.data.data);
        } catch (e) { console.error(e); }
    };

    const handleUploadAttachment = async (targetFiles: FileList | File[] | null) => {
        if (!selectedRequest || !targetFiles || targetFiles.length === 0) return;
        setUploadingAttachment(true);
        try {
            const formData = new FormData();
            formData.append('request_id', String(selectedRequest.id));

            // Handle multiple files if possible (backend supports it now)
            Array.from(targetFiles).forEach(file => {
                formData.append('attachments[]', file);
            });

            const res = await axios.post(getApiUrl('api-changerequests.php?action=upload_attachment'), formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });

            if (res.data.success) {
                loadAttachments(selectedRequest.id);
                loadAuditLog(selectedRequest.id);
            }
        } catch (e) { console.error(e); alert('Upload failed'); } finally {
            setUploadingAttachment(false);
        }
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
            handleUploadAttachment(e.dataTransfer.files);
        }
    };

    const handleSaveDescription = async () => {
        if (!selectedRequest) return;
        try {
            await axios.post(getApiUrl('api-changerequests.php?action=update'), {
                id: selectedRequest.id,
                description: descriptionEditValue
            });
            updateLocalRequest(selectedRequest.id, { description: descriptionEditValue });
            setIsEditingDescription(false);
            loadAuditLog(selectedRequest.id);
        } catch (e) { console.error(e); alert('Failed to save description'); }
    }

    const handleSaveSubject = async () => {
        if (!selectedRequest || !subjectEditValue.trim()) return;
        try {
            await axios.post(getApiUrl('api-changerequests.php?action=update'), {
                id: selectedRequest.id,
                subject: subjectEditValue
            });
            updateLocalRequest(selectedRequest.id, { subject: subjectEditValue });
            setIsEditingSubject(false);
            loadAuditLog(selectedRequest.id);
        } catch (e) { console.error(e); alert('Failed to save subject'); }
    }

    const handleDeleteAttachment = async (id: number) => {
        if (!confirm('Opravdu smazat tuto přílohu?')) return;
        try {
            const formData = new FormData();
            formData.append('id', String(id));
            await axios.post(getApiUrl('api-changerequests.php?action=delete_attachment'), formData);
            if (selectedRequest) {
                loadAttachments(selectedRequest.id);
                loadAuditLog(selectedRequest.id);
            }
        } catch (e) { alert('Smazání selhalo'); }
    };

    const handleToggleReaction = async (commentId: number, type: string) => {
        try {
            await axios.post(getApiUrl('api-comments.php?action=toggle_reaction'), { comment_id: commentId, type });
            if (selectedRequest) loadComments(selectedRequest.id);
        } catch (e) { console.error(e); }
    };

    const updateLocalRequest = (id: number, changes: Partial<RequestItem>) => {
        const update = (r: RequestItem) => r.id === id ? { ...r, ...changes } : r;
        setRequests(prev => prev.map(update));
        if (selectedRequest && selectedRequest.id === id) {
            setSelectedRequest(prev => prev ? update(prev) : null);
        }
    };

    const handleAddComment = async () => {
        if (!selectedRequest || !newComment.trim()) return;
        setSendingComment(true);
        try {
            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('request_id', String(selectedRequest.id));
            formData.append('comment', newComment);
            const res = await axios.post(getApiUrl('api-comments.php'), formData);
            if (res.data.success) {
                setNewComment('');
                loadComments(selectedRequest.id);
            }
        } catch (e) { console.error(e); } finally { setSendingComment(false); }
    };

    const renderContent = (html: string) => {
        if (!html) return null;
        // Basic check if it's legacy markdown or HTML
        const isHtml = html.includes('<') || html.includes('>');
        const processed = isHtml ? html : mdToHtml(html, (p) => getApiUrl(p));

        return (
            <div
                className={styles.visualContent}
                dangerouslySetInnerHTML={{ __html: processed }}
                onClick={(e) => {
                    const target = e.target as HTMLElement;
                    if (target.tagName === 'IMG') {
                        setLightboxImage((target as HTMLImageElement).src);
                    }
                }}
            />
        );
    };

    const columns = [
        {
            columnId: 'id',
            renderHeaderCell: () => '#',
            renderCell: (item: RequestItem) => item.id,
            compare: (a: RequestItem, b: RequestItem) => a.id - b.id,
            minWidth: 40
        },
        {
            columnId: 'priority',
            renderHeaderCell: () => 'Priorita',
            renderCell: (item: RequestItem) => {
                const p = item.priority || 'medium';
                if (p === 'high') return <Badge color="danger" icon={<ArrowUp24Regular />}>High</Badge>;
                if (p === 'low') return <Badge color="success" icon={<ArrowDown24Regular />}>Low</Badge>;
                return <Badge color="informative" icon={<Subtract24Regular />}>Medium</Badge>;
            },
            compare: (a: RequestItem, b: RequestItem) => {
                const map: any = { high: 3, medium: 2, low: 1 };
                return (map[a.priority] || 2) - (map[b.priority] || 2);
            },
            minWidth: 110
        },
        {
            columnId: 'subject',
            renderHeaderCell: () => 'Předmět',
            renderCell: (item: RequestItem) => (<Text weight="semibold">{item.subject}</Text>),
            compare: (a: RequestItem, b: RequestItem) => a.subject.localeCompare(b.subject),
            minWidth: 300
        },
        {
            columnId: 'status',
            renderHeaderCell: () => 'Stav',
            renderCell: (item: RequestItem) => {
                const colorMap: Record<string, any> = {
                    'New': tokens.colorPaletteBlueBackground2,
                    'New feature': tokens.colorPalettePurpleBackground2,
                    'Analysis': tokens.colorPaletteYellowBackground1,
                    'Development': tokens.colorPaletteBlueBackground2,
                    'Back to development': tokens.colorPaletteDarkOrangeBackground2,
                    'Testing': tokens.colorPaletteTealBackground2,
                    'Testing AI': tokens.colorPaletteBerryBackground2,
                    'Done': tokens.colorPaletteGreenBackground2,
                    'Duplicity': tokens.colorNeutralBackground3,
                    'Canceled': tokens.colorNeutralBackground5,
                };
                return (
                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                        <div style={{ width: 8, height: 8, borderRadius: '50%', backgroundColor: colorMap[item.status] || tokens.colorNeutralBackground5 }} />
                        <Text>{item.status}</Text>
                    </div>
                );
            },
            compare: (a: RequestItem, b: RequestItem) => a.status.localeCompare(b.status),
            minWidth: 150
        },
        {
            columnId: 'assigned_username',
            renderHeaderCell: () => 'Přiřazeno',
            renderCell: (item: RequestItem) => (
                <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                    {item.assigned_username ? (
                        <><Avatar name={item.assigned_username} size={24} color="colorful" /><Text>{item.assigned_username}</Text></>
                    ) : (
                        <Text style={{ color: tokens.colorNeutralForeground3 }}>-</Text>
                    )}
                </div>
            ),
            compare: (a: RequestItem, b: RequestItem) => (a.assigned_username || '').localeCompare(b.assigned_username || ''),
            minWidth: 150
        }
    ];

    if (selectedRequest) {
        return (
            <PageLayout>
                <PageHeader>
                    <div className={styles.detailHeader} style={{ width: '100%' }}>
                        <div style={{ display: 'flex', gap: '15px', alignItems: 'center' }}>
                            <Button icon={<ArrowLeft24Regular />} appearance="subtle" onClick={() => setSelectedRequest(null)}>Zpět</Button>
                            <div style={{ display: 'flex', alignItems: 'center' }}>
                                <span className={styles.detailId}>#{selectedRequest.id}</span>
                                {isEditingSubject ? (
                                    <div style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
                                        <input
                                            value={subjectEditValue}
                                            onChange={(e) => setSubjectEditValue(e.target.value)}
                                            style={{
                                                fontSize: '24px',
                                                fontWeight: '600',
                                                padding: '4px 8px',
                                                border: `1px solid ${tokens.colorBrandStroke1}`,
                                                borderRadius: '4px',
                                                backgroundColor: tokens.colorNeutralBackground1,
                                                color: tokens.colorNeutralForeground1,
                                                minWidth: '400px'
                                            }}
                                            autoFocus
                                            onKeyDown={(e) => { if (e.key === 'Enter') handleSaveSubject(); }}
                                        />
                                        <Button icon={<Save24Regular />} appearance="primary" onClick={handleSaveSubject} />
                                        <Button icon={<Dismiss24Regular />} appearance="subtle" onClick={() => setIsEditingSubject(false)} />
                                    </div>
                                ) : (
                                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                                        <span className={styles.detailTitle}>{selectedRequest.subject}</span>
                                        <Button
                                            icon={<Edit24Regular />}
                                            appearance="subtle"
                                            size="small"
                                            onClick={() => {
                                                setSubjectEditValue(selectedRequest.subject);
                                                setIsEditingSubject(true);
                                            }}
                                        />
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </PageHeader>

                <PageContent>
                    <div className={styles.detailLayout}>
                        <div className={styles.mainColumn}>
                            <div className={styles.card}>
                                <div className={styles.sectionHeader}>
                                    <Text className={styles.sectionTitle}>Popis</Text>
                                    {!isEditingDescription ? (
                                        <Button icon={<Edit24Regular />} appearance="subtle" size="small" onClick={() => {
                                            setDescriptionEditValue(
                                                selectedRequest.description.includes('<')
                                                    ? selectedRequest.description
                                                    : mdToHtml(selectedRequest.description, (p) => getApiUrl(p))
                                            );
                                            setIsEditingDescription(true);
                                        }}>Upravit</Button>
                                    ) : (
                                        <div style={{ display: 'flex', gap: '8px' }}>
                                            <Button icon={<Save24Regular />} appearance="primary" size="small" onClick={handleSaveDescription}>Uložit</Button>
                                            <Button icon={<Dismiss24Regular />} appearance="subtle" size="small" onClick={() => setIsEditingDescription(false)}>Zrušit</Button>
                                        </div>
                                    )}
                                </div>

                                {isEditingDescription ? (
                                    <VisualEditor
                                        initialContent={descriptionEditValue}
                                        onChange={setDescriptionEditValue}
                                        getApiUrl={getApiUrl}
                                    />
                                ) : (
                                    renderContent(selectedRequest.description)
                                )}
                            </div>

                            <div className={styles.commentBox}>
                                <Text className={styles.sectionTitle}><Comment24Regular /> Diskuze ({comments.length})</Text>
                                <div style={{ display: 'flex', flexDirection: 'column', gap: '0', marginBottom: '16px' }}>
                                    {loadingComments && <Spinner size="small" />}
                                    {!loadingComments && comments.length === 0 && <Text style={{ color: tokens.colorNeutralForeground3, fontStyle: 'italic', padding: '10px' }}>Zatím žádné komentáře.</Text>}
                                    {comments.map(c => (
                                        <div key={c.id} className={styles.commentItem}>
                                            <Avatar name={c.username} color="colorful" />
                                            <div style={{ display: 'flex', flexDirection: 'column', gap: '6px', flex: 1 }}>
                                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                                    <Text weight="semibold">{c.username}</Text>
                                                    <Text size={200} style={{ color: tokens.colorNeutralForeground3 }}>{new Date(c.created_at).toLocaleString('cs-CZ')}</Text>
                                                </div>
                                                {renderContent(c.comment)}

                                                <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginTop: '8px' }}>
                                                    {Object.entries(c.reactions).map(([type, userIds]) => {
                                                        if (userIds.length === 0) return null;
                                                        const emojiMap: any = {
                                                            check: '✅', cross: '❌', smile: '😊', heart: '❤️',
                                                            sad: '😢', angry: '😡', laugh: '😂', star: '⭐'
                                                        };
                                                        const isActive = c.user_reactions.includes(type);
                                                        return (
                                                            <Button
                                                                key={type}
                                                                size="small"
                                                                appearance={isActive ? "primary" : "subtle"}
                                                                onClick={() => handleToggleReaction(c.id, type)}
                                                                style={{
                                                                    minWidth: 'auto',
                                                                    padding: '2px 8px',
                                                                    borderRadius: '12px',
                                                                    backgroundColor: isActive ? tokens.colorBrandBackground2 : tokens.colorNeutralBackground2,
                                                                    border: isActive ? `1px solid ${tokens.colorBrandStroke1}` : 'none'
                                                                }}
                                                            >
                                                                <span style={{ fontSize: '14px' }}>{emojiMap[type] || '❓'}</span>
                                                                <span style={{ marginLeft: '4px', fontSize: '11px', fontWeight: 'bold' }}>{userIds.length}</span>
                                                            </Button>
                                                        );
                                                    })}

                                                    <Menu>
                                                        <MenuTrigger disableButtonEnhancement>
                                                            <Button
                                                                size="small"
                                                                appearance="subtle"
                                                                icon={<Emoji20Regular style={{ color: tokens.colorNeutralForeground4 }} />}
                                                                style={{ borderRadius: '12px', minWidth: 'auto' }}
                                                            />
                                                        </MenuTrigger>
                                                        <MenuPopover>
                                                            <MenuList style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '4px', padding: '4px' }}>
                                                                {[
                                                                    { type: 'check', char: '✅', label: 'Hotovo' },
                                                                    { type: 'cross', char: '❌', label: 'Chyba' },
                                                                    { type: 'smile', char: '😊', label: 'Super' },
                                                                    { type: 'heart', char: '❤️', label: 'Díky' },
                                                                    { type: 'laugh', char: '😂', label: 'Smích' },
                                                                    { type: 'sad', char: '😢', label: 'Smutek' },
                                                                    { type: 'angry', char: '😡', label: 'Vztek' },
                                                                    { type: 'star', char: '⭐', label: 'Hvězda' }
                                                                ].map(emoji => (
                                                                    <MenuItem
                                                                        key={emoji.type}
                                                                        onClick={() => handleToggleReaction(c.id, emoji.type)}
                                                                        style={{ padding: '8px', fontSize: '20px', minWidth: 'auto', textAlign: 'center' }}
                                                                    >
                                                                        {emoji.char}
                                                                    </MenuItem>
                                                                ))}
                                                            </MenuList>
                                                        </MenuPopover>
                                                    </Menu>
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                <div style={{ borderTop: `1px solid ${tokens.colorNeutralStroke2}`, paddingTop: '16px' }}>
                                    <VisualEditor
                                        initialContent={newComment}
                                        onChange={setNewComment}
                                        getApiUrl={getApiUrl}
                                    />
                                    <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: '12px' }}>
                                        <Button appearance="primary" icon={<Send24Regular />} onClick={handleAddComment} disabled={sendingComment || !newComment.trim()}>Odeslat</Button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className={styles.sideColumn}>
                            <div className={styles.card}>
                                <Text className={styles.sectionTitle} style={{ marginBottom: '12px' }}>Podrobnosti</Text>
                                <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
                                    <div>
                                        <Label style={{ color: tokens.colorNeutralForeground3, marginBottom: '4px', display: 'block' }}>Stav</Label>
                                        <Dropdown
                                            value={selectedRequest.status}
                                            selectedOptions={[selectedRequest.status]}
                                            onOptionSelect={(_, d) => handleUpdateStatus(selectedRequest.id, d.optionValue || 'New')}
                                            style={{ width: '100%' }}
                                        >
                                            {allStatuses.map(s => <Option key={s} value={s}>{s}</Option>)}
                                        </Dropdown>
                                    </div>
                                    <div>
                                        <Label style={{ color: tokens.colorNeutralForeground3, marginBottom: '4px', display: 'block' }}>Priorita</Label>
                                        <Dropdown
                                            value={selectedRequest.priority.charAt(0).toUpperCase() + selectedRequest.priority.slice(1)}
                                            selectedOptions={[selectedRequest.priority]}
                                            onOptionSelect={(_, d) => handleUpdatePriority(selectedRequest.id, d.optionValue || 'medium')}
                                            style={{ width: '100%' }}
                                        >
                                            {['low', 'medium', 'high'].map(p => <Option key={p} value={p}>{p.charAt(0).toUpperCase() + p.slice(1)}</Option>)}
                                        </Dropdown>
                                    </div>
                                    <div>
                                        <Label style={{ color: tokens.colorNeutralForeground3, marginBottom: '4px', display: 'block' }}>Řešitel</Label>
                                        <Dropdown
                                            placeholder="Vybrat řešitele"
                                            value={selectedRequest.assigned_username || "Nepřiřazeno"}
                                            selectedOptions={selectedRequest.assigned_to ? [String(selectedRequest.assigned_to)] : []}
                                            onOptionSelect={(_, d) => handleUpdateAssignee(selectedRequest.id, Number(d.optionValue))}
                                            style={{ width: '100%' }}
                                        >
                                            <Option key="0" value="0">Nepřiřazeno</Option>
                                            {users.map(u => <Option key={u.id} value={String(u.id)}>{u.username}</Option>)}
                                        </Dropdown>
                                    </div>
                                </div>
                            </div>

                            <div className={styles.card}>
                                <div className={styles.sectionHeader}>
                                    <Text className={styles.sectionTitle}><Attach24Regular /> Přílohy ({attachments.length})</Text>
                                </div>

                                <div
                                    className={`${styles.dropZone} ${isDragging ? styles.dropZoneActive : ''}`}
                                    onDragOver={handleDragOver}
                                    onDragLeave={handleDragLeave}
                                    onDrop={handleDrop}
                                    onClick={() => document.getElementById('req-file-input')?.click()}
                                    style={{ marginBottom: '12px' }}
                                >
                                    <Attach24Regular style={{ color: tokens.colorBrandForeground1 }} />
                                    <Text size={200} weight="semibold">Přidat přílohy</Text>
                                    <Text size={100} style={{ color: tokens.colorNeutralForeground3 }}>(vložte nebo přetáhněte)</Text>
                                    <input
                                        id="req-file-input"
                                        type="file"
                                        multiple
                                        style={{ display: 'none' }}
                                        onChange={(e) => handleUploadAttachment(e.target.files)}
                                    />
                                </div>

                                <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                                    {attachments.map(att => (
                                        <div
                                            key={att.id}
                                            style={{
                                                display: 'flex',
                                                alignItems: 'center',
                                                gap: '10px',
                                                padding: '8px',
                                                backgroundColor: tokens.colorNeutralBackground2,
                                                borderRadius: '4px',
                                            }}
                                        >
                                            <div
                                                style={{ display: 'flex', flex: 1, alignItems: 'center', gap: '10px', cursor: 'pointer', minWidth: 0 }}
                                                onClick={() => window.open(getApiUrl(att.file_path), '_blank')}
                                            >
                                                <Document24Regular style={{ color: tokens.colorNeutralForeground4 }} />
                                                <div style={{ flex: 1, minWidth: 0 }}>
                                                    <Text block truncate weight="semibold" size={200}>{att.filename}</Text>
                                                    <Text size={100} style={{ color: tokens.colorNeutralForeground3 }}>
                                                        {Math.round(att.filesize / 1024)} KB • {new Date(att.created_at).toLocaleDateString('cs-CZ')}
                                                    </Text>
                                                </div>
                                            </div>
                                            <Button
                                                icon={<Delete20Regular />}
                                                size="small"
                                                appearance="subtle"
                                                onClick={() => handleDeleteAttachment(att.id)}
                                                style={{ color: tokens.colorPaletteRedForeground1 }}
                                            />
                                        </div>
                                    ))}
                                    {attachments.length === 0 && <Text style={{ fontStyle: 'italic', fontSize: '12px' }}>Žádné přílohy.</Text>}
                                    {uploadingAttachment && <Spinner size="tiny" label="Nahrávám..." />}
                                </div>
                            </div>

                            <div className={styles.card}>
                                <Text className={styles.sectionTitle} style={{ marginBottom: '8px' }}><History24Regular /> Historie</Text>
                                <div style={{ fontSize: '12px', display: 'flex', flexDirection: 'column', gap: '8px' }}>
                                    {auditLog.map(log => (
                                        <div key={log.id} style={{ padding: '8px', backgroundColor: tokens.colorNeutralBackground2, borderRadius: '4px', borderLeft: `3px solid ${tokens.colorBrandBackground}` }}>
                                            <Text weight="semibold" block>{log.username}</Text>
                                            <Text size={200} block style={{ color: tokens.colorNeutralForeground3 }}>{new Date(log.created_at).toLocaleString('cs-CZ')}</Text>
                                            <Text block style={{ marginTop: '4px' }}>
                                                {log.change_type === 'status' ? `Stav: ${log.old_value} -> ${log.new_value}` :
                                                    log.change_type === 'assignee' ? `Řešitel: ${log.new_value}` :
                                                        log.change_type === 'priority' ? `Priorita: ${log.new_value}` :
                                                            log.change_type === 'description' ? 'Popis upraven' :
                                                                log.change_type === 'subject' ? `Předmět změněn: ${log.new_value}` :
                                                                    log.change_type === 'comment' ? 'Přidán komentář' :
                                                                        log.change_type === 'attachment' ? log.new_value :
                                                                            log.change_type === 'attachment_deleted' ? `Smazána příloha: ${log.old_value}` :
                                                                                log.change_type}
                                            </Text>
                                        </div>
                                    ))}
                                    {auditLog.length === 0 && <Text style={{ fontStyle: 'italic' }}>Beze změn.</Text>}
                                </div>
                            </div>
                        </div>
                    </div>
                </PageContent>

                {lightboxImage && (
                    <Dialog open={true} onOpenChange={() => setLightboxImage(null)}>
                        <DialogSurface style={{ maxWidth: '90vw', maxHeight: '90vh', padding: 0 }}>
                            <img src={lightboxImage} style={{ width: '100%', height: '100%', objectFit: 'contain' }} onClick={() => setLightboxImage(null)} />
                        </DialogSurface>
                    </Dialog>
                )}
            </PageLayout>
        );
    }

    return (
        <PageLayout>
            <PageHeader>
                <div style={{ display: 'flex', justifyContent: 'space-between', width: '100%', alignItems: 'center' }}>
                    <Text size={500} weight="bold">Správa požadavků</Text>
                    <Button appearance="subtle" onClick={loadRequests}>Obnovit</Button>
                </div>
            </PageHeader>
            <PageContent noScroll>
                <div className={styles.root}>
                    <div className={styles.filterBar}>
                        <div style={{ display: 'flex', gap: '20px', alignItems: 'center', width: '100%' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                                <Switch
                                    label="Jen moje"
                                    checked={showOnlyMine}
                                    onChange={(_, data) => {
                                        setShowOnlyMine(!!data.checked);
                                        setSearchParams(prev => {
                                            if (data.checked) prev.set('mine', '1');
                                            else prev.delete('mine');
                                            return prev;
                                        });
                                    }}
                                />
                            </div>
                            <Popover trapFocus>
                                <PopoverTrigger disableButtonEnhancement>
                                    <Button icon={<Filter24Regular />}>
                                        Stavy {selectedStatuses.length < allStatuses.length ? `(${selectedStatuses.length})` : ''}
                                    </Button>
                                </PopoverTrigger>
                                <PopoverSurface>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                                        <Text weight="semibold" style={{ marginBottom: '8px' }}>Filtrovat stavy</Text>
                                        {allStatuses.map(s => (
                                            <Checkbox
                                                key={s}
                                                label={s}
                                                checked={selectedStatuses.includes(s)}
                                                onChange={(_, data) => {
                                                    if (data.checked) setSelectedStatuses(prev => [...prev, s]);
                                                    else setSelectedStatuses(prev => prev.filter(x => x !== s));
                                                }}
                                            />
                                        ))}
                                    </div>
                                </PopoverSurface>
                            </Popover>
                        </div>
                    </div>
                    <div style={{ flex: 1, minHeight: 0, boxShadow: tokens.shadow2, borderRadius: tokens.borderRadiusMedium, overflow: 'auto' }}>
                        <div style={{ minWidth: '1000px', height: '100%' }}>
                            {loadingRequests ? <Spinner /> : (
                                <SmartDataGrid
                                    items={requests.filter(r => selectedStatuses.includes(r.status))}
                                    columns={columns}
                                    getRowId={(i) => i.id}
                                    onRowClick={setSelectedRequest}
                                />
                            )}
                        </div>
                    </div>
                </div>
            </PageContent>
        </PageLayout >
    );
};

export default RequestsPage;
