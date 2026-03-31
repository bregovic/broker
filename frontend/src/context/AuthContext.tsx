import React, { createContext, useContext, useState, useEffect } from 'react';

type User = {
    id: number;
    username: string;
    role: 'admin' | 'user';
    name: string;
    initials: string;
    assigned_tasks_count?: number;
};

type AuthContextType = {
    user: User | null;
    isLoading: boolean;
    login: (username: string, pass: string) => Promise<boolean>;
    logout: () => void;
};

const AuthContext = createContext<AuthContextType>({
    user: null,
    isLoading: true,
    login: async () => false,
    logout: () => { },
});

export const useAuth = () => useContext(AuthContext);

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
    const [user, setUser] = useState<User | null>(null);
    const [isLoading, setIsLoading] = useState(true);

    const API_BASE = import.meta.env.DEV
        ? 'http://localhost/Webhry/hollyhop/broker/broker 2.0'
        : '/investyx'; // Correct path matching deployment

    useEffect(() => {
        checkAuth();

        const handleFocus = () => checkAuth();
        window.addEventListener('focus', handleFocus);

        // Poll every 60 seconds
        const interval = setInterval(checkAuth, 60000);

        return () => {
            window.removeEventListener('focus', handleFocus);
            clearInterval(interval);
        };
    }, []);

    const checkAuth = async () => {
        try {
            const res = await fetch(`${API_BASE}/ajax-get-user.php`, {
                credentials: 'include'
            });
            const data = await res.json();
            if (data.success && data.user) {
                setUser({
                    id: data.user.id,
                    username: data.user.name,
                    role: data.user.role || 'user',
                    name: data.user.name || '',
                    initials: data.user.initials || 'User',
                    assigned_tasks_count: data.user.assigned_tasks_count || 0
                });
            } else {
                setUser(null);
            }
        } catch (e) {
            setUser(null);
        } finally {
            setIsLoading(false);
        }
    };

    const login = async (username: string, pass: string) => {
        try {
            const res = await fetch(`${API_BASE}/api-login.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password: pass }),
                credentials: 'include'
            });
            const data = await res.json();
            if (data.success && data.user) {
                setUser({
                    id: data.user.id,
                    username: data.user.username,
                    role: data.user.role,
                    name: data.user.username,
                    initials: data.user.username.substring(0, 2).toUpperCase(),
                    assigned_tasks_count: data.user.assigned_tasks_count || 0
                });
                return true;
            }
            return false;
        } catch (e) {
            return false;
        }
    };

    const logout = () => {
        // Optional: Call server logout
        fetch(`${API_BASE}/php/logout.php`); // If exists
        setUser(null);
    };

    return (
        <AuthContext.Provider value={{ user, isLoading, login, logout }}>
            {children}
        </AuthContext.Provider>
    );
};
