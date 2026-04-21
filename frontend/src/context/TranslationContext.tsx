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

    const getApiUrl = (endpoint: string) => `/api/${endpoint}`;

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
        axios.get(`/api/v3/api-labels.php?lang=${language}`)
            .then(res => {
                if (res.data) {
                    // api-labels.php might return the JSON directly or wrapped in {success: true, translations: ...}
                    // Based on our v3/api-labels.php, it's returning the JSON directly.
                    setTranslations(res.data.translations || res.data);
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
        if (!key) return '';
        
        // Try flat first
        if (translations[key] && typeof translations[key] === 'string') {
            return translations[key];
        }

        // Try nested (e.g. "common.save")
        const parts = key.split('.');
        let current: any = translations;
        for (const part of parts) {
            if (current && typeof current === 'object' && part in current) {
                current = current[part];
            } else {
                return key;
            }
        }

        return typeof current === 'string' ? current : key;
    }, [translations]);

    const value = useMemo(() => ({ t, language, setLanguage, loading }), [t, language, loading]);

    return (
        <TranslationContext.Provider value={value}>
            {children}
        </TranslationContext.Provider>
    );
};
