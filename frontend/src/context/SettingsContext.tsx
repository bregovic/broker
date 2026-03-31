
import React, { createContext, useContext, useState, useEffect } from 'react';
import axios from 'axios';
import { translations, type Language } from '../locales/translations';

interface SettingsContextType {
    language: Language;
    setLanguage: (lang: Language) => void;
    theme: string;
    setTheme: (theme: string) => void;
    t: (key: string) => string;
    saveSettings: () => Promise<void>;
}

const SettingsContext = createContext<SettingsContextType | undefined>(undefined);

export const SettingsProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
    const [language, setLanguageState] = useState<Language>('cs');
    const [theme, setTheme] = useState('light');

    // Load settings from server on mount
    useEffect(() => {
        // Adjust URL to match your server path
        const isDev = import.meta.env.DEV;
        const url = isDev
            ? 'http://localhost/Webhry/hollyhop/broker/broker%202.0/api-settings.php'
            : '/investyx/api-settings.php';

        axios.get(url).then(res => {
            if (res.data && res.data.success && res.data.settings) {
                if (res.data.settings.language) setLanguageState(res.data.settings.language as Language);
                // if (res.data.settings.theme) setTheme(res.data.settings.theme);
            }
        }).catch(err => console.error("Failed to load settings", err));
    }, []);

    const setLanguage = (lang: Language) => {
        setLanguageState(lang);
    };

    const saveSettings = async () => {
        const isDev = import.meta.env.DEV;
        const url = isDev
            ? 'http://localhost/Webhry/hollyhop/broker/broker%202.0/api-settings.php'
            : '/investyx/api-settings.php';

        try {
            await axios.post(url, { language, theme });
            return;
        } catch (e) {
            console.error(e);
            throw e;
        }
    };

    const t = (key: string): string => {
        const dict = translations[language] || translations['cs'];
        return (dict as any)[key] || key;
    };

    return (
        <SettingsContext.Provider value={{ language, setLanguage, theme, setTheme, t, saveSettings }}>
            {children}
        </SettingsContext.Provider>
    );
};

export const useSettings = () => {
    const context = useContext(SettingsContext);
    if (!context) {
        throw new Error('useSettings must be used within a SettingsProvider');
    }
    return context;
};
