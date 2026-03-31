
import { Outlet, useNavigate, useLocation } from 'react-router-dom';
import { useState } from 'react';
import {
    makeStyles,
    tokens,
    Text,
    Button,
    Image
} from '@fluentui/react-components';
import {
    Home24Regular,
    Money24Regular,
    ArrowImport24Regular,
    Savings24Regular,
    ReceiptMoney24Regular,
    SignOut24Regular,
    Alert24Regular,
    Settings24Regular,
    Emoji24Regular,
    ArrowTrending24Regular,
    ArrowSwap24Regular,
    ClipboardTextEdit24Regular
} from '@fluentui/react-icons';
import { FeedbackModal } from './FeedbackModal';
import { useTranslation } from '../context/TranslationContext';

const useStyles = makeStyles({
    root: {
        display: 'flex',
        flexDirection: 'column',
        height: '100vh',
        width: '100%',
        backgroundColor: tokens.colorNeutralBackground2,
    },
    header: {
        height: '60px',
        backgroundColor: '#ffffff',
        color: '#000000',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        padding: '0 24px',
        flexShrink: 0,
        zIndex: 100,
        borderBottom: `1px solid ${tokens.colorNeutralStroke1}`,
        boxShadow: tokens.shadow2,
        // touchAction removed so navContainer can handle it
        '@media (max-width: 768px)': {
            padding: '0 8px',
            gap: '4px',
            height: '56px'
        }
    },
    headerLeftGroup: {
        display: 'flex',
        alignItems: 'center',
        height: '100%',
        gap: '24px',
        flex: 1, // Allow taking available space
        minWidth: 0, // Allow shrinking below content size
        paddingRight: '8px', // Fade out effect space
        overflowX: 'auto', // ENABLE SCROLL HERE
        scrollbarWidth: 'none', // Firefox
        '::-webkit-scrollbar': { display: 'none' }, // Chrome/Safari
        touchAction: 'pan-x', // Explicitly allow only horizontal scroll
        '-webkit-overflow-scrolling': 'touch', // Smooth scroll on iOS
        '@media (max-width: 768px)': {
            gap: '8px', // Slightly more gap
            paddingRight: '4px'
        }
    },
    headerLeft: {
        display: 'flex',
        alignItems: 'center',
        cursor: 'pointer',
        marginRight: '0px', // Removed margin, handled by gap
        flexShrink: 0,
        '@media (max-width: 768px)': {
            marginRight: '0px'
        }
    },
    logoImage: {
        height: '42px',
        objectFit: 'contain',
        '@media (max-width: 768px)': {
            height: '28px', // Smaller logo on mobile to save space
            maxWidth: '100px'
        }
    },
    navContainer: {
        display: 'flex',
        alignItems: 'center',
        height: '100%',
        gap: '4px',
        // overflowX: 'auto', // MOVED TO PARENT
        flex: '1 1 auto', // Grow, shrink, auto basis
        minWidth: 0, // CRITICAL for flexbox scrolling
        maxWidth: '100%', // Ensure it doesn't overflow parent visual bounds
        // scrollbarWidth: 'none', // Firefox
        // '::-webkit-scrollbar': { display: 'none' }, // Chrome/Safari
        // touchAction: 'pan-x', // Explicitly allow only horizontal scroll
        // '-webkit-overflow-scrolling': 'touch', // Smooth scroll on iOS
    },
    headerRight: {
        display: 'flex',
        alignItems: 'center',
        gap: '4px',
        flexShrink: 0, // Do not shrink right controls
        marginLeft: '4px'
    },
    headerIcon: {
        color: tokens.colorNeutralForeground1,
        ':hover': { color: tokens.colorBrandForeground1 }
    },
    body: {
        display: 'flex',
        flexDirection: 'column',
        flexGrow: 1,
        overflow: 'hidden'
    },
    content: {
        display: 'flex',
        flexDirection: 'column',
        flexGrow: 1,
        overflow: 'hidden',
        padding: '0',
        position: 'relative',
        width: '100%',
    },
    navItem: {
        padding: '0 12px',
        height: '100%',
        display: 'flex',
        alignItems: 'center',
        gap: '8px',
        cursor: 'pointer',
        color: tokens.colorNeutralForeground1,
        borderBottom: '3px solid transparent',
        boxSizing: 'border-box',
        whiteSpace: 'nowrap', // Ensure text doesn't wrap
        flexShrink: 0, // Prevent collapsing in scroll container
        ':hover': {
            backgroundColor: tokens.colorNeutralBackground1Hover,
            color: tokens.colorBrandForeground1
        }
    },
    navItemActive: {
        borderBottom: `3px solid ${tokens.colorBrandBackground}`,
        fontWeight: 600,
        color: tokens.colorBrandForeground1
    },
    userCircle: {
        width: '32px', // Slightly smaller
        height: '32px',
        borderRadius: '50%',
        backgroundColor: tokens.colorBrandBackground,
        color: '#ffffff',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: '13px',
        fontWeight: 600,
        cursor: 'pointer',
        marginLeft: '4px'
    },
    notificationWrapper: {
        position: 'relative',
        display: 'flex',
        alignItems: 'center'
    },
    badge: {
        position: 'absolute',
        top: '2px',
        right: '2px',
        zIndex: 1
    }
});

import { SettingsDialog } from './SettingsDialog';
import { useAuth } from '../context/AuthContext';
import { Badge } from '@fluentui/react-components';

const Layout = () => {
    const styles = useStyles();
    const navigate = useNavigate();
    const location = useLocation();
    const { t } = useTranslation();
    const path = location.pathname;
    const selectedValue = path.endsWith('/') ? 'market' : path.split('/').pop() || 'market';

    const { user, logout } = useAuth();
    const [feedbackOpen, setFeedbackOpen] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);

    const NavItem = ({ value, icon, label }: { value: string, icon: any, label: string }) => {
        const isActive = selectedValue === value || (value === 'market' && path.endsWith('/'));
        return (
            <div
                className={isActive ? `${styles.navItem} ${styles.navItemActive}` : styles.navItem}
                onClick={() => {
                    if (value === 'requests') {
                        window.dispatchEvent(new CustomEvent('reset-requests-page'));
                    }
                    navigate(value === 'market' ? '/' : value);
                }}
            >
                {icon}
                <Text>{label}</Text>
            </div>
        );
    };

    return (
        <div className={styles.root}>
            <header className={styles.header}>
                <div className={styles.headerLeftGroup}>
                    <div className={styles.headerLeft} onClick={() => navigate('/')}>
                        <Image src="/investyx/logo.png" className={styles.logoImage} alt="Investyx Logo" />
                    </div>

                    <nav className={styles.navContainer}>
                        <NavItem value="market" icon={<Home24Regular />} label={t('nav_market')} />
                        <NavItem value="portfolio" icon={<Money24Regular />} label={t('nav_portfolio')} />
                        <NavItem value="dividends" icon={<ReceiptMoney24Regular />} label={t('nav_dividends')} />
                        <NavItem value="pnl" icon={<ArrowTrending24Regular />} label={t('nav_pnl')} />
                        <NavItem value="balance" icon={<Savings24Regular />} label={t('nav_balances')} />
                        <NavItem value="rates" icon={<ArrowSwap24Regular />} label={t('nav_rates')} />
                        <NavItem value="import" icon={<ArrowImport24Regular />} label={t('nav_import')} />
                        {(user?.role === 'admin' || (!!user?.assigned_tasks_count && user.assigned_tasks_count > 0)) && (
                            <NavItem value="requests" icon={<ClipboardTextEdit24Regular />} label="Správa požadavků" />
                        )}

                        <div className={styles.headerRight} style={{ marginLeft: 'auto', paddingRight: '12px' }}>
                            <div className={styles.notificationWrapper}>
                                <Button
                                    appearance="transparent"
                                    icon={<Alert24Regular className={styles.headerIcon} />}
                                    onClick={() => {
                                        window.dispatchEvent(new CustomEvent('reset-requests-page'));
                                        navigate('/requests?mine=1');
                                    }}
                                    title="Moje požadavky"
                                />
                                {user?.assigned_tasks_count && user.assigned_tasks_count > 0 ? (
                                    <Badge
                                        size="small"
                                        appearance="filled"
                                        color="danger"
                                        className={styles.badge}
                                    >
                                        {user.assigned_tasks_count}
                                    </Badge>
                                ) : null}
                            </div>
                            <Button appearance="transparent" icon={<Emoji24Regular className={styles.headerIcon} />} onClick={() => setFeedbackOpen(true)} />
                            <Button appearance="transparent" icon={<Settings24Regular className={styles.headerIcon} />} onClick={() => setSettingsOpen(true)} />

                            <div className={styles.userCircle} title={user?.name}>
                                {user ? user.initials : '...'}
                            </div>
                            <Button appearance="subtle" icon={<SignOut24Regular />} onClick={() => logout()} style={{ marginLeft: 8 }} title="Zpět / Odhlásit" />
                        </div>
                    </nav>
                </div>
            </header>

            <FeedbackModal open={feedbackOpen} onOpenChange={setFeedbackOpen} user={user} />
            <SettingsDialog open={settingsOpen} onOpenChange={setSettingsOpen} />

            <div className={styles.body}>
                <main className={styles.content}>
                    <Outlet />
                </main>
            </div>
        </div>
    );
};

export default Layout;
