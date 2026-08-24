import { useCallback, useEffect, useMemo, useState } from 'react';
import { Dropdown, Option, Button, Tooltip, tokens } from '@fluentui/react-components';
import { Dismiss16Regular, BuildingBank24Regular } from '@fluentui/react-icons';
import { useTranslation } from '../context/TranslationContext';

/**
 * Výběr služeb (brokerů) pro přehledové stránky.
 *
 * Volba se drží v localStorage a platí napříč stránkami — když si uživatel
 * zobrazí jen Fio, chce ho vidět i v dividendách a v P&L. Ovládací prvek je
 * proto vždycky na stránce vidět a při aktivním filtru je zvýrazněný; skrytý
 * filtr, který tiše mění čísla, je horší než žádný.
 *
 * Prázdný výběr znamená „vše“ — ne „nic“, aby stránka nikdy nevyšla prázdná.
 */
const KLIC = 'investyx.serviceFilter';

function nactiUlozene(): string[] {
    try {
        const raw = localStorage.getItem(KLIC);
        const val = raw ? JSON.parse(raw) : [];
        return Array.isArray(val) ? val.filter((x) => typeof x === 'string') : [];
    } catch {
        return [];
    }
}

/**
 * Stav filtru + odfiltrovaná data. Filtruje se ještě před předáním do tabulky,
 * takže souhrnné karty (počítané z `onFilteredDataChange`) sedí samy od sebe.
 */
export function useServiceFilter<T>(items: T[], getPlatform: (item: T) => string) {
    const [selected, setSelected] = useState<string[]>(() => nactiUlozene());

    // Změnu ve filtru chceme vidět i na ostatních otevřených stránkách.
    useEffect(() => {
        const onStorage = (e: StorageEvent) => {
            if (e.key === KLIC) setSelected(nactiUlozene());
        };
        window.addEventListener('storage', onStorage);
        return () => window.removeEventListener('storage', onStorage);
    }, []);

    const zmen = useCallback((next: string[]) => {
        setSelected(next);
        try {
            localStorage.setItem(KLIC, JSON.stringify(next));
        } catch { /* soukromý režim prohlížeče – filtr prostě nepřežije reload */ }
    }, []);

    /** Služby vyskytující se v datech, s počtem řádků. */
    const dostupne = useMemo(() => {
        const m = new Map<string, number>();
        for (const it of items) {
            const p = (getPlatform(it) || '').trim();
            if (p) m.set(p, (m.get(p) ?? 0) + 1);
        }
        return [...m.entries()].sort((a, b) => a[0].localeCompare(b[0]));
    }, [items, getPlatform]);

    // Uložená služba, která v aktuálních datech není (jiný účet, smazaný import),
    // se ignoruje — jinak by filtr vyprázdnil stránku bez zjevného důvodu.
    const platne = useMemo(
        () => selected.filter((s) => dostupne.some(([p]) => p === s)),
        [selected, dostupne]
    );

    const filtered = useMemo(
        () => (platne.length === 0 ? items : items.filter((it) => platne.includes((getPlatform(it) || '').trim()))),
        [items, platne, getPlatform]
    );

    return { selected: platne, setSelected: zmen, dostupne, filtered };
}

interface Props {
    dostupne: [string, number][];
    selected: string[];
    onChange: (next: string[]) => void;
}

export const ServiceFilter = ({ dostupne, selected, onChange }: Props) => {
    const { t } = useTranslation();
    const aktivni = selected.length > 0;

    // Jediná dostupná služba – filtr by neměl co dělat.
    if (dostupne.length < 2) return null;

    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginLeft: '16px' }}>
            <BuildingBank24Regular />
            <Dropdown
                multiselect
                selectedOptions={selected}
                value={aktivni ? selected.join(', ') : t('filter_services_all')}
                placeholder={t('filter_services_all')}
                aria-label={t('filter_services')}
                onOptionSelect={(_, data) => onChange(data.selectedOptions)}
                style={{
                    minWidth: '210px',
                    ...(aktivni ? { borderBottom: `2px solid ${tokens.colorBrandForeground1}` } : {}),
                }}
            >
                {dostupne.map(([nazev, pocet]) => (
                    <Option key={nazev} value={nazev} text={nazev}>
                        {`${nazev} (${pocet})`}
                    </Option>
                ))}
            </Dropdown>
            {aktivni && (
                <Tooltip content={t('filter_services_clear')} relationship="label">
                    <Button
                        appearance="subtle"
                        size="small"
                        icon={<Dismiss16Regular />}
                        onClick={() => onChange([])}
                        aria-label={t('filter_services_clear')}
                    />
                </Tooltip>
            )}
        </div>
    );
};
