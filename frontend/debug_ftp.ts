
import * as dotenv from 'dotenv';
import { Client } from 'basic-ftp';

dotenv.config();

const FTP_HOST = process.env.FTP_HOST || "";
const FTP_USER = process.env.FTP_USER || "";
const FTP_PASSWORD = process.env.FTP_PASSWORD || "";

async function debug() {
    const client = new Client();
    // client.ftp.verbose = true;
    try {
        await client.access({
            host: FTP_HOST,
            user: FTP_USER,
            password: FTP_PASSWORD,
            secure: false
        });

        console.log("--- Listing / ---");
        const listRoot = await client.list("/");
        listRoot.forEach(f => console.log(f.name, f.isDirectory ? "(DIR)" : ""));

        console.log("\n--- Listing /www ---");
        try {
            const listWWW = await client.list("/www");
            listWWW.forEach(f => console.log(f.name, f.isDirectory ? "(DIR)" : ""));
        } catch (e) { console.log("Could not list /www"); }

        console.log("\n--- Listing /www/domains ---");
        try {
            const listDomains = await client.list("/www/domains");
            listDomains.forEach(f => console.log(f.name, f.isDirectory ? "(DIR)" : ""));
        } catch (e) { console.log("Could not list /www/domains"); }

    } catch (err) {
        console.error(err);
    }
    client.close();
}

debug();
