
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
    Divider
} from '@fluentui/react-components';
import { useTranslation } from '../context/TranslationContext';
import { useAuth } from '../context/AuthContext';
import { useState } from 'react';

const useStyles = makeStyles({
    content: {
        display: 'flex',
        flexDirection: 'column',
        gap: '16px',
        paddingTop: '10px',
        minHeight: '150px'
    }
});

export const SettingsDialog = ({ open, onOpenChange }: { open: boolean, onOpenChange: (open: boolean) => void }) => {
    const styles = useStyles();
    const { language, setLanguage, t } = useTranslation();
    const { user } = useAuth();
    const [saving, setSaving] = useState(false);

    const isDev = import.meta.env.DEV;
    const getApiUrl = (endpoint: string) => isDev ? `http://localhost/Webhry/hollyhop/broker/broker 2.0/${endpoint}` : `/investyx/${endpoint}`;

    const handleSave = async () => {
        setSaving(true);
        // TranslationContext saves immediately on setLanguage, so we just simulate delay or close
        setTimeout(() => {
            setSaving(false);
            onOpenChange(false);
        }, 500);
    };

    return (
        <Dialog open={open} onOpenChange={(_, data) => onOpenChange(data.open)}>
            <DialogSurface>
                <DialogBody>
                    <DialogTitle>{t('settings.title')}</DialogTitle>
                    <DialogContent className={styles.content}>
                        <div style={{ marginBottom: '16px', padding: '8px', backgroundColor: tokens.colorNeutralBackground2, borderRadius: '4px' }}>
                            <Text size={200} block style={{ color: tokens.colorNeutralForeground4 }}>
                                Version: <span style={{ fontFamily: 'monospace' }}>{__APP_VERSION__}</span>
                            </Text>
                            <Text size={200} block style={{ color: tokens.colorNeutralForeground4 }}>
                                Built: {new Date(__APP_BUILD_DATE__).toLocaleString()}
                            </Text>
                        </div>
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

                        {user?.role === 'admin' && (
                            <>
                                <Divider style={{ margin: '10px 0' }} />
                                <div style={{ display: 'flex', flexDirection: 'column', gap: '5px' }}>
                                    <Label>Admin Nástroje</Label>
                                    <Button onClick={() => window.open(getApiUrl('debug-prices.php?ticker=ZM'), '_blank')}>Debug Prices (API)</Button>
                                    <Text size={200} style={{ color: tokens.colorNeutralForeground4 }}>Otevře diagnostiku API v novém okně.</Text>
                                </div>
                            </>
                        )}
                    </DialogContent>
                    <DialogActions>
                        <Button appearance="primary" onClick={handleSave} disabled={saving}>
                            {saving ? t('common.loading') : t('common.save')}
                        </Button>
                        <Button appearance="secondary" onClick={() => onOpenChange(false)}>
                            {t('common.cancel')}
                        </Button>
                    </DialogActions>
                </DialogBody>
            </DialogSurface>
        </Dialog>
    );
};
