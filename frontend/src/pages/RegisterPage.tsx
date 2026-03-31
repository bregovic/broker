import React, { useState } from 'react';
import {
    Card,
    Input,
    Label,
    Button,
    makeStyles,
    tokens,
    Title3,
    Link
} from '@fluentui/react-components';
import { useNavigate } from 'react-router-dom';

const useStyles = makeStyles({
    container: {
        display: 'flex',
        justifyContent: 'center',
        alignItems: 'center',
        height: '100vh',
        backgroundColor: tokens.colorNeutralBackground2,
    },
    card: {
        width: '360px',
        padding: '24px',
    },
    field: {
        display: 'flex',
        flexDirection: 'column',
        gap: '6px',
        marginBottom: '16px',
    },
    error: {
        color: tokens.colorPaletteRedForeground1,
        marginBottom: '12px',
        fontSize: '12px',
    },
    footer: {
        marginTop: '16px',
        textAlign: 'center',
        fontSize: '12px',
    }
});

const API_BASE = import.meta.env.DEV
    ? 'https://hollyhop.cz/broker/broker 2.0'
    : '/broker/broker 2.0';

export const RegisterPage: React.FC = () => {
    const styles = useStyles();
    const navigate = useNavigate();

    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [confirmPassword, setConfirmPassword] = useState('');
    const [error, setError] = useState('');
    const [isLoading, setIsLoading] = useState(false);

    const handleRegister = async (e: React.FormEvent) => {
        e.preventDefault();
        setError('');

        if (password !== confirmPassword) {
            setError('Passwords do not match');
            return;
        }

        setIsLoading(true);

        try {
            const res = await fetch(`${API_BASE}/api-register.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password })
            });
            const data = await res.json();

            if (data.success) {
                navigate('/login', { state: { message: 'Registration successful! Please login.' } });
            } else {
                setError(data.error || 'Registration failed');
            }
        } catch (err) {
            setError('Network error');
        } finally {
            setIsLoading(false);
        }
    };

    return (
        <div className={styles.container}>
            <Card className={styles.card}>
                <div style={{ textAlign: 'center', marginBottom: 20 }}>
                    <Title3>Investyx Registration</Title3>
                </div>
                <form onSubmit={handleRegister}>
                    <div className={styles.field}>
                        <Label htmlFor="username">Username</Label>
                        <Input
                            id="username"
                            value={username}
                            onChange={(_, d) => setUsername(d.value)}
                            disabled={isLoading}
                        />
                    </div>
                    <div className={styles.field}>
                        <Label htmlFor="password">Password</Label>
                        <Input
                            id="password"
                            type="password"
                            value={password}
                            onChange={(_, d) => setPassword(d.value)}
                            disabled={isLoading}
                        />
                    </div>
                    <div className={styles.field}>
                        <Label htmlFor="confirmPassword">Confirm Password</Label>
                        <Input
                            id="confirmPassword"
                            type="password"
                            value={confirmPassword}
                            onChange={(_, d) => setConfirmPassword(d.value)}
                            disabled={isLoading}
                        />
                    </div>
                    {error && <div className={styles.error}>{error}</div>}
                    <Button type="submit" appearance="primary" style={{ width: '100%' }} disabled={isLoading}>
                        {isLoading ? 'Registering...' : 'Register'}
                    </Button>
                </form>
                <div className={styles.footer}>
                    Already have an account? <Link onClick={() => navigate('/login')}>Sign In</Link>
                </div>
            </Card>
        </div>
    );
};
