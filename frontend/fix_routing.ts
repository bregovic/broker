
import * as dotenv from 'dotenv';
import { Client } from 'basic-ftp';

dotenv.config();

const FTP_HOST = process.env.FTP_HOST || "";
const FTP_USER = process.env.FTP_USER || "";
const FTP_PASSWORD = process.env.FTP_PASSWORD || "";

async function fix() {
    const client = new Client();
    try {
        await client.access({
            host: FTP_HOST,
            user: FTP_USER,
            password: FTP_PASSWORD,
            secure: false
        });

        console.log("Renaming /www/domains/hollyhop.cz to /www/domains/_hollyhop.cz_trash to fix routing...");
        try {
            await client.rename("/www/domains/hollyhop.cz", "/www/domains/_hollyhop.cz_trash");
            console.log("✅ Renamed successfully.");
        } catch (e) {
            console.error("Could not rename:", e);
        }

    } catch (err) {
        console.error(err);
    }
    client.close();
}

fix();
