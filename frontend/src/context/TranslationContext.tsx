import React, { createContext, useContext, useState, useEffect, useCallback, useMemo } from 'react';
import axios from 'axios';

type Language = 'cs' | 'en';

interface TranslationContextType {
    t: (key: string) => string;
    language: Language;
    setLanguage: (lang: Language) => void;
    loading: boolean;
}

const TranslationContext = createContext<TranslationContextType>({
    t: (key) => key,
    language: 'cs',
    setLanguage: () => { },
    loading: false
});

export const useTranslation = () => useContext(TranslationContext);

export const TranslationProvider = ({ children }: { children: React.ReactNode }) => {
    const [language, setLanguageState] = useState<Language>('cs');
    const [translations, setTranslations] = useState<Record<string, string>>({});
    const [loading, setLoading] = useState(true);

    const isDev = import.meta.env.DEV;
    const getApiUrl = (endpoint: string) => isDev
        ? `http://localhost/Webhry/hollyhop/broker/broker%202.0/${endpoint}`
        : `/investyx/${endpoint}`;

    // Load user settings on mount
    useEffect(() => {
        const loadSettings = async () => {
            try {
                const res = await axios.get(getApiUrl('api-settings.php'));
                if (res.data && res.data.success && res.data.settings) {
                    if (res.data.settings.language) {
                        setLanguageState(res.data.settings.language as Language);
                    }
                }
            } catch (e) {
                console.warn("Failed to load user settings, fallback to local");
                const saved = localStorage.getItem('broker_lang') as Language;
                if (saved) setLanguageState(saved);
            }
        };
        loadSettings();
    }, []);

    // Fetch translations when language changes
    useEffect(() => {
        setLoading(true);
        axios.get(getApiUrl(`api-translations.php?lang=${language}`))
            .then(res => {
                if (res.data && res.data.success) {
                    setTranslations(res.data.translations);
                }
            })
            .catch(err => console.error("Translation Error", err))
            .finally(() => setLoading(false));

        localStorage.setItem('broker_lang', language);
    }, [language]);

    const setLanguage = (lang: Language) => {
        setLanguageState(lang);
        // Save to API
        axios.post(getApiUrl('api-settings.php'), { language: lang }).catch(console.error);
    };

    const t = useCallback((key: string): string => {
        return translations[key] || key;
    }, [translations]);

    const value = useMemo(() => ({ t, language, setLanguage, loading }), [t, language, loading]);

    return (
        <TranslationContext.Provider value={value}>
            {children}
        </TranslationContext.Provider>
    );
};
