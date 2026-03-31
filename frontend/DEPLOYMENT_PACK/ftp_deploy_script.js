const ftp = require("basic-ftp");

async function upload() {
    const client = new ftp.Client();
    client.ftp.verbose = true;

    try {
        await client.access({
            host: "372733.w33.wedos.net",
            user: "w372733",
            password: "Starter123!",
            secure: false
        });

        console.log("Connected to FTP");
        await client.ensureDir("www/otamat");

        // Upload the 'out' directory contents to 'www/otamat'
        await client.uploadFromDir("out");

        console.log("Upload successful!");
    } catch (err) {
        console.error("Upload failed:", err);
    }
    client.close();
}

upload();
