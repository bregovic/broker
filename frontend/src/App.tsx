
import { FluentProvider, webLightTheme } from '@fluentui/react-components';
import { RouterProvider, createBrowserRouter, Navigate, Outlet } from 'react-router-dom';
import Layout from './components/Layout';
import MarketPage from './pages/MarketPage';
import { PortfolioPage } from './pages/PortfolioPage';
import { DividendsPage } from './pages/DividendsPage';
import { PnLPage } from './pages/PnLPage';
import { BalancePage } from './pages/BalancePage';
import { RatesPage } from './pages/RatesPage';
import ImportPage from './pages/ImportPage';
import RequestsPage from './pages/RequestsPage';
import { LoginPage } from './pages/LoginPage';
import { RegisterPage } from './pages/RegisterPage';
import { SettingsProvider } from './context/SettingsContext';
import { AuthProvider, useAuth } from './context/AuthContext';

const BASE_NAME = import.meta.env.DEV ? '/' : '/investyx';

const RequireAuth = () => {
  const { user, isLoading } = useAuth();
  if (isLoading) return <div>Loading...</div>;
  if (!user) return <Navigate to="/login" replace />;
  return <Outlet />;
};

const router = createBrowserRouter([
  {
    path: "/login",
    element: <LoginPage />
  },
  {
    path: "/register",
    element: <RegisterPage />
  },
  {
    path: "/",
    element: <RequireAuth />,
    children: [
      {
        path: "/",
        element: <Layout />,
        children: [
          { path: "/", element: <MarketPage /> },
          { path: "market", element: <MarketPage /> },
          { path: "portfolio", element: <PortfolioPage /> },
          { path: "dividends", element: <DividendsPage /> },
          { path: "pnl", element: <PnLPage /> },
          { path: "rates", element: <RatesPage /> },
          { path: "balance", element: <BalancePage /> },
          { path: "import", element: <ImportPage /> },
          { path: "requests", element: <RequestsPage /> },
        ]
      }
    ]
  }
], { basename: BASE_NAME });

function App() {
  return (
    <FluentProvider theme={webLightTheme}>
      <AuthProvider>
        <SettingsProvider>
          <RouterProvider router={router} />
        </SettingsProvider>
      </AuthProvider>
    </FluentProvider>
  );
}

export default App;
