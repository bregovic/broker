
import * as fs from 'fs';
import * as path from 'path';
import * as dotenv from 'dotenv';
import { Client } from 'basic-ftp';
import { execSync } from 'child_process';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

dotenv.config();

const FTP_HOST = process.env.FTP_HOST || "ftp.hollyhop.cz";
const FTP_USER = process.env.FTP_USER || "";
const FTP_PASSWORD = process.env.FTP_PASSWORD || "";

// Configuration
const DEPLOY_FRONTEND = true;
const DEPLOY_BACKEND = true;

const PATHS = {
    frontend: {
        local: path.join(__dirname, 'dist'),
        remote: '/www/investyx/'
    },
    backend: {
        local: path.resolve(__dirname, '../broker 2.0'),
        remote: '/www/broker/broker 2.0/'
    }
};

async function deploy() {
    console.log("🚀 Starting deployment process...");

    // 1. Build Frontend
    if (DEPLOY_FRONTEND) {
        console.log("📦 Building React Frontend (Investhor)...");
        try {
            execSync('npm run build', { stdio: 'inherit' });
        } catch (e) {
            console.error("❌ Build failed.");
            process.exit(1);
        }
    }

    if (!FTP_USER || !FTP_PASSWORD) {
        console.error("❌ Missing FTP credentials. Please create .env file with FTP_HOST, FTP_USER, FTP_PASSWORD.");
        process.exit(1);
    }

    const client = new Client();
    // client.ftp.verbose = true;

    try {
        console.log(`🔌 Connecting to FTP (${FTP_HOST})...`);
        await client.access({
            host: FTP_HOST,
            user: FTP_USER,
            password: FTP_PASSWORD,
            secure: false // Set to true if FTPS is supported
        });

        // 2. Upload Frontend
        if (DEPLOY_FRONTEND && fs.existsSync(PATHS.frontend.local)) {
            console.log(`📤 Uploading Frontend to ${PATHS.frontend.remote}...`);
            await client.ensureDir(PATHS.frontend.remote);
            await client.clearWorkingDir(); // Optional: Clear old files
            await client.uploadFromDir(PATHS.frontend.local, PATHS.frontend.remote);
            console.log("✅ Frontend uploaded.");
        }

        // 3. Upload Backend (Targeted API Files)
        if (DEPLOY_BACKEND && fs.existsSync(PATHS.backend.local)) {
            console.log(`📤 Uploading Backend API files to ${PATHS.backend.remote}...`);

            // Try to CD first to handle spaces in path better
            try {
                await client.cd(PATHS.backend.remote);
            } catch (e) {
                console.warn("   ⚠️ Could not CD to remote backend dir. Creating it...");
                await client.ensureDir(PATHS.backend.remote);
                await client.cd(PATHS.backend.remote);
            }

            const filesToSync = [
                'api-market-data.php',
                'ajax_import_ticker.php',
                'ajax-live-prices.php',
                'googlefinanceservice.php',
                'ajax-get-user.php',
                'rates.php',
                'import-handler.php',
                'api-portfolio.php',
                'api-dividends.php',
                'api-pnl.php',
                'api-rates-list.php',
                'ajax-add-rate.php',
                'api-transactions.php',
                'cnb-import-year.php',
                'cnb-import.php',
                'calculate_metrics.php',
                'ajax-update-prices.php',
                'ajax-fetch-history.php',
                'ajax-get-chart-data.php',
                'ajax-toggle-watch.php',
                'api-settings.php',
                'setup_translations_db.php',
                'setup_translations_db_v2.php',
                'api-translations.php',
                'setup_translations_db_v2.php',
                'api-translations.php',
                'setup_auth.php',
                'api-login.php',
                'api-register.php',
                'logout.php'
            ];

            for (const file of filesToSync) {
                const localPath = path.join(PATHS.backend.local, file);

                if (fs.existsSync(localPath)) {
                    // Upload to current working dir
                    await client.uploadFrom(localPath, file);
                    console.log(`   -> Uploaded ${file}`);
                } else {
                    console.warn(`   ⚠️ File not found locally: ${file}`);
                }
            }
            console.log("✅ Backend API updated.");
        }

    } catch (err) {
        console.error("❌ FTP Error:", err);
    } finally {
        client.close();
        console.log("👋 Done.");
    }
}

deploy();
