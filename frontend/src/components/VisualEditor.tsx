import {
    makeStyles,
    tokens,
    Button,
    Tooltip,
    Divider,
    Menu,
    MenuTrigger,
    MenuPopover,
    MenuList,
    MenuItem
} from "@fluentui/react-components";
import {
    Delete24Regular,
    TextBold20Regular,
    TextItalic20Regular,
    TextBulletList20Regular,
    TextNumberListLtr20Regular,
    TextUnderline20Regular,
    Emoji20Regular,
    Eraser20Regular
} from "@fluentui/react-icons";
import { useState, useEffect, useRef } from "react";
import axios from "axios";

const useStyles = makeStyles({
    wrapper: {
        display: 'flex',
        flexDirection: 'column',
        gap: '4px',
        border: `1px solid ${tokens.colorNeutralStroke1}`,
        borderRadius: tokens.borderRadiusMedium,
        backgroundColor: tokens.colorNeutralBackground1,
        overflow: 'hidden',
        ':focus-within': {
            outline: `2px solid ${tokens.colorBrandStroke1}`
        }
    },
    toolbar: {
        display: 'flex',
        gap: '2px',
        padding: '4px',
        backgroundColor: tokens.colorNeutralBackground2,
        borderBottom: `1px solid ${tokens.colorNeutralStroke2}`,
        flexWrap: 'wrap',
        alignItems: 'center'
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
            '&:hover': {
                opacity: 0.95
            }
        },
        '& ul, & ol': {
            paddingLeft: '20px',
            marginTop: '8px',
            marginBottom: '8px'
        }
    },
    editorArea: {
        minHeight: '150px',
        maxHeight: '500px',
        padding: '12px',
        outline: 'none',
        overflowY: 'auto',
        position: 'relative'
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
    placeholder: {
        position: 'absolute',
        top: '12px',
        left: '12px',
        color: tokens.colorNeutralForeground4,
        pointerEvents: 'none'
    },
    emojiGrid: {
        display: 'grid',
        gridTemplateColumns: 'repeat(4, 1fr)',
        gap: '4px',
        padding: '4px'
    }
});

interface VisualEditorProps {
    initialContent: string;
    onChange: (html: string) => void;
    getApiUrl: (endpoint: string) => string;
    placeholder?: string;
}

export const VisualEditor = ({
    initialContent,
    onChange,
    getApiUrl,
    placeholder
}: VisualEditorProps) => {
    const styles = useStyles();
    const editorRef = useRef<HTMLDivElement>(null);
    const [selectedImg, setSelectedImg] = useState<HTMLImageElement | null>(null);
    const [toolbarPos, setToolbarPos] = useState({ top: 0, left: 0 });
    const [showPlaceholder, setShowPlaceholder] = useState(!initialContent);

    useEffect(() => {
        if (editorRef.current) {
            if (editorRef.current.innerHTML === '' && initialContent) {
                editorRef.current.innerHTML = initialContent;
                setShowPlaceholder(false);
            }
        }
    }, [initialContent]);

    const handleInput = () => {
        if (editorRef.current) {
            const html = editorRef.current.innerHTML;
            onChange(html);
            setShowPlaceholder(html === '' || html === '<br>');
        }
    };

    const exec = (command: string, value: string = '') => {
        document.execCommand(command, false, value);
        editorRef.current?.focus();
        handleInput();
    };

    const insertEmoji = (emoji: string) => {
        document.execCommand('insertText', false, emoji);
        editorRef.current?.focus();
        handleInput();
    };

    const handlePaste = async (e: React.ClipboardEvent) => {
        const items = e.clipboardData.items;
        for (let i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image') !== -1) {
                e.preventDefault();
                e.stopPropagation();
                const blob = items[i].getAsFile();
                if (blob) {
                    const formData = new FormData();
                    formData.append('image', blob);
                    try {
                        const res = await axios.post(getApiUrl('api-changerequests.php?action=upload_content_image'), formData);
                        if (res.data.success) {
                            const imgUrl = getApiUrl(res.data.url);
                            document.execCommand('insertHTML', false, `<img src="${imgUrl}" style="max-width: 50%; display: block; cursor: pointer;" />`);
                            handleInput();
                        }
                    } catch (err) {
                        console.error("Upload failed", err);
                    }
                }
            }
        }
    };

    const handleEditorClick = (e: React.MouseEvent) => {
        const target = e.target as HTMLElement;
        if (target.tagName === 'IMG') {
            setSelectedImg(target as HTMLImageElement);
            const rect = target.getBoundingClientRect();
            setToolbarPos({
                top: rect.top + window.scrollY - 40,
                left: rect.left + window.scrollX
            });
        } else {
            setSelectedImg(null);
        }
    };

    const resizeImg = (width: string) => {
        if (selectedImg) {
            selectedImg.style.width = width;
            selectedImg.style.maxWidth = '100%';
            handleInput();
            setSelectedImg(null);
        }
    };

    const deleteImg = () => {
        if (selectedImg) {
            selectedImg.remove();
            handleInput();
            setSelectedImg(null);
        }
    };

    const emojis = [
        '✅', '❌', '😊', '❤️', '😂', '😢', '😡', '⭐',
        '💡', '🚀', '👍', '👎', '📋', '⚠️', '🔥', '✨'
    ];

    return (
        <div className={styles.wrapper}>
            <div className={styles.toolbar}>
                <Tooltip content="Tučné" relationship="label">
                    <Button size="small" appearance="subtle" icon={<TextBold20Regular />} onClick={() => exec('bold')} />
                </Tooltip>
                <Tooltip content="Kurzíva" relationship="label">
                    <Button size="small" appearance="subtle" icon={<TextItalic20Regular />} onClick={() => exec('italic')} />
                </Tooltip>
                <Tooltip content="Podtržené" relationship="label">
                    <Button size="small" appearance="subtle" icon={<TextUnderline20Regular />} onClick={() => exec('underline')} />
                </Tooltip>
                <Divider vertical style={{ height: '20px', margin: '0 4px' }} />
                <Tooltip content="Odrážky" relationship="label">
                    <Button size="small" appearance="subtle" icon={<TextBulletList20Regular />} onClick={() => exec('insertUnorderedList')} />
                </Tooltip>
                <Tooltip content="Číslování" relationship="label">
                    <Button size="small" appearance="subtle" icon={<TextNumberListLtr20Regular />} onClick={() => exec('insertOrderedList')} />
                </Tooltip>
                <Divider vertical style={{ height: '20px', margin: '0 4px' }} />

                <Menu>
                    <MenuTrigger disableButtonEnhancement>
                        <Tooltip content="Vložit smajlíka" relationship="label">
                            <Button size="small" appearance="subtle" icon={<Emoji20Regular />} />
                        </Tooltip>
                    </MenuTrigger>
                    <MenuPopover>
                        <MenuList className={styles.emojiGrid}>
                            {emojis.map(e => (
                                <MenuItem key={e} style={{ fontSize: '24px', padding: '8px', minWidth: '40px', textAlign: 'center' }} onClick={() => insertEmoji(e)}>
                                    {e}
                                </MenuItem>
                            ))}
                        </MenuList>
                    </MenuPopover>
                </Menu>

                <Tooltip content="Vymazat formátování" relationship="label">
                    <Button size="small" appearance="subtle" icon={<Eraser20Regular />} onClick={() => exec('removeFormat')} />
                </Tooltip>
            </div>

            <div style={{ position: 'relative' }}>
                {selectedImg && (
                    <div className={styles.imgToolbar} style={{ top: toolbarPos.top, left: toolbarPos.left }}>
                        <Button size="small" onClick={() => resizeImg('25%')}>Malý (25%)</Button>
                        <Button size="small" onClick={() => resizeImg('50%')}>Střední (50%)</Button>
                        <Button size="small" onClick={() => resizeImg('75%')}>Velký (75%)</Button>
                        <Button size="small" onClick={() => resizeImg('100%')}>Plný (100%)</Button>
                        <Button icon={<Delete24Regular />} size="small" appearance="subtle" onClick={deleteImg} />
                    </div>
                )}
                {showPlaceholder && placeholder && (
                    <div className={styles.placeholder}>{placeholder}</div>
                )}
                <div
                    ref={editorRef}
                    className={`${styles.editorArea} ${styles.visualContent}`}
                    contentEditable
                    onInput={handleInput}
                    onPaste={handlePaste} // Native Paste
                    onDrop={(e) => { // Native Drop
                        e.preventDefault();
                        const items = e.dataTransfer.items;
                        if (items) {
                            // Reuse logic from handlePaste but strictly for drop data
                            const clipboardEvent = { clipboardData: e.dataTransfer, preventDefault: () => { }, stopPropagation: () => { } } as any;
                            handlePaste(clipboardEvent);
                        }
                    }}
                    onClick={handleEditorClick}
                />
            </div>
        </div>
    );
};
