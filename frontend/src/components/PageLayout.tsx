
import { makeStyles, tokens } from '@fluentui/react-components';

const useStyles = makeStyles({
    root: {
        display: 'flex',
        flexDirection: 'column',
        height: '100%',
        width: '100%',
        boxSizing: 'border-box'
    }
});

export const PageLayout: React.FC<{ children: React.ReactNode }> = ({ children }) => {
    const styles = useStyles();
    return <div className={styles.root}>{children}</div>;
};

const useHeaderStyles = makeStyles({
    root: {
        width: '100%',
        padding: '10px 24px',
        paddingRight: '30px', // Extra offset for right side as requested
        backgroundColor: tokens.colorNeutralBackground1,
        borderBottom: `1px solid ${tokens.colorNeutralStroke1}`,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        flexShrink: 0,
        boxSizing: 'border-box',
        overflowX: 'auto',
        scrollbarWidth: 'none',
        '::-webkit-scrollbar': { display: 'none' },
        touchAction: 'pan-x', // Allow horizontal scroll for toolbar
        '-webkit-overflow-scrolling': 'touch',
        '@media (max-width: 768px)': {
            padding: '10px 12px'
        }
    }
});

export const PageHeader: React.FC<{ children: React.ReactNode }> = ({ children }) => {
    const styles = useHeaderStyles();
    return <div className={styles.root}>{children}</div>;
};

const useContentStyles = makeStyles({
    root: {
        flexGrow: 1,
        overflow: 'auto',
        padding: '24px',
        position: 'relative',
        display: 'flex',
        flexDirection: 'column',
        gap: '24px',
        touchAction: 'pan-y', // Allow vertical scroll for content
        '-webkit-overflow-scrolling': 'touch',
        '@media (max-width: 768px)': {
            padding: '12px',
            gap: '12px'
        }
    }
});

export const PageContent: React.FC<{ children: React.ReactNode; noScroll?: boolean }> = ({ children, noScroll }) => {
    const styles = useContentStyles();
    return <div className={styles.root} style={noScroll ? { overflow: 'hidden' } : undefined}>{children}</div>;
};
